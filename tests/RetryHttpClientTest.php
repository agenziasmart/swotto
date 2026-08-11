<?php

declare(strict_types=1);

namespace Swotto\Tests;

use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Stringable;
use Swotto\Config\Configuration;
use Swotto\Contract\HttpClientInterface;
use Swotto\Exception\ApiException;
use Swotto\Exception\AuthenticationException;
use Swotto\Exception\ConnectionException;
use Swotto\Exception\ForbiddenException;
use Swotto\Exception\NetworkException;
use Swotto\Exception\NotFoundException;
use Swotto\Exception\RateLimitException;
use Swotto\Exception\ValidationException;
use Swotto\Retry\RetryHttpClient;

/**
 * RetryHttpClientTest.
 *
 * Unit tests for RetryHttpClient decorator with exponential backoff
 */
class RetryHttpClientTest extends TestCase
{
    /**
     * @var HttpClientInterface&\Mockery\MockInterface
     */
    private HttpClientInterface $mockClient;

    private Configuration $config;

    private RetryHttpClient $retryClient;

    protected function setUp(): void
    {
        /* @phpstan-ignore-next-line */
        $this->mockClient = Mockery::mock(HttpClientInterface::class);
        $this->config = new Configuration([
            'url' => 'https://api.example.com',
            'retry_enabled' => true,
            'retry_max_attempts' => 3,
            'retry_initial_delay_ms' => 10, // Very short for testing
            'retry_max_delay_ms' => 100,
            'retry_multiplier' => 2.0,
            'retry_jitter' => false, // Disable jitter for predictable tests
        ]);
        $this->retryClient = new RetryHttpClient(
            $this->mockClient, // @phpstan-ignore-line
            $this->config,
            new NullLogger()
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    // ========== BASIC SUCCESS TESTS ==========

    public function testSuccessfulRequestOnFirstAttempt(): void
    {
        $expectedResponse = ['data' => 'test'];

        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/test', [])
            ->andReturn($expectedResponse);

        $response = $this->retryClient->request('GET', '/test', []);

        $this->assertEquals($expectedResponse, $response);
    }

    // ========== RETRY ON NETWORK ERRORS ==========

    public function testRetryOnNetworkException(): void
    {
        $expectedResponse = ['data' => 'success'];

        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/test', [])
            ->andThrow(new NetworkException('Network error', [], 0));

        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/test', [])
            ->andReturn($expectedResponse);

        $response = $this->retryClient->request('GET', '/test', []);

        $this->assertEquals($expectedResponse, $response);
    }

    public function testRetryLogDoesNotExposeExceptionMessageOrQuery(): void
    {
        $exceptionSecret = 'SENTINEL_RETRY_AUTH_SECRET';
        $querySecret = 'SENTINEL_RETRY_QUERY_SECRET';
        $userInfoSecret = 'SENTINEL_RETRY_USERINFO_SECRET';
        $uri = "https://user:{$userInfoSecret}@api.example.com/auth/session?access_token={$querySecret}";
        $logger = new RetryCapturingLogger();
        $config = new Configuration([
            'url' => 'https://api.example.com',
            'retry_max_attempts' => 2,
            'retry_initial_delay_ms' => 1,
            'retry_max_delay_ms' => 1,
            'retry_jitter' => false,
        ]);
        $retryClient = new RetryHttpClient(
            $this->mockClient, // @phpstan-ignore-line
            $config,
            $logger
        );
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->andThrow(new NetworkException($exceptionSecret));
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->andReturn(['success' => true]);

        $result = $retryClient->request('HEAD', $uri);

        $this->assertSame(['success' => true], $result);
        $serialized = serialize($logger->entries);
        $this->assertStringNotContainsString($exceptionSecret, $serialized);
        $this->assertStringNotContainsString($querySecret, $serialized);
        $this->assertStringNotContainsString($userInfoSecret, $serialized);
        $this->assertCount(2, $logger->entries);
        $this->assertSame([
            'method' => 'HEAD',
            'uri' => 'https://api.example.com/auth/session',
            'attempt' => 1,
            'max_attempts' => 2,
            'delay_ms' => 1,
            'failure_type' => 'network',
            'exception_type' => NetworkException::class,
        ], $logger->entries[0]['context']);
        $this->assertSame('https://api.example.com/auth/session', $logger->entries[1]['context']['uri']);
    }

    public function testRetryLogsUseBoundedUriAndSafeMethodMetadata(): void
    {
        $method = "GET\r\nSENTINEL_METHOD";
        $uri = "https://user:SENTINEL_BEFORE\xFFSENTINEL_AFTER@api.example.com/safe"
            . "\r\n\x00\u{200B}\u{2028}\u{2029}\xC3\x28"
            . str_repeat('à', 300)
            . '?token=SENTINEL_QUERY#SENTINEL_FRAGMENT';
        $logger = new RetryCapturingLogger();
        $config = new Configuration([
            'url' => 'https://api.example.com',
            'retry_max_attempts' => 2,
            'retry_initial_delay_ms' => 1,
            'retry_max_delay_ms' => 1,
            'retry_jitter' => false,
        ]);
        $retryClient = new RetryHttpClient($this->mockClient, $config, $logger); // @phpstan-ignore-line
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with($method, $uri, [])
            ->andThrow(new NetworkException('SENTINEL_EXCEPTION'));
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with($method, $uri, [])
            ->andReturn(['success' => true]);

        $previousSubstitute = mb_substitute_character();
        mb_substitute_character(47);

        try {
            $result = $retryClient->request($method, $uri, ['retry_non_idempotent' => true]);
        } finally {
            mb_substitute_character($previousSubstitute);
        }

        self::assertSame(['success' => true], $result);
        self::assertCount(2, $logger->entries);
        self::assertSame('warning', $logger->entries[0]['level']);
        self::assertSame('info', $logger->entries[1]['level']);
        foreach ($logger->entries as $entry) {
            self::assertSame('UNKNOWN', $entry['context']['method'] ?? null);
            $safeUri = $entry['context']['uri'] ?? null;
            self::assertIsString($safeUri);
            self::assertStringStartsWith('https://api.example.com/safe', $safeUri);
            self::assertTrue(mb_check_encoding($safeUri, 'UTF-8'));
            self::assertLessThanOrEqual(512, strlen($safeUri));
            self::assertDoesNotMatchRegularExpression('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $safeUri);
        }
        self::assertStringNotContainsString('SENTINEL_', serialize($logger->entries));
    }

    public function testExhaustedRetriesDoNotLogRawExceptionAndPreserveTheException(): void
    {
        $sentinel = 'SENTINEL_EXHAUSTED_RETRY';
        $logger = new RetryCapturingLogger();
        $config = new Configuration([
            'url' => 'https://api.example.com',
            'retry_max_attempts' => 2,
            'retry_initial_delay_ms' => 1,
            'retry_max_delay_ms' => 1,
            'retry_jitter' => false,
        ]);
        $retryClient = new RetryHttpClient($this->mockClient, $config, $logger); // @phpstan-ignore-line
        $exception = new NetworkException($sentinel);
        $this->mockClient->shouldReceive('request')->twice()->andThrow($exception);

        try {
            $retryClient->request('HEAD', '/auth?token=SENTINEL_EXHAUSTED_QUERY');
            self::fail('Expected the final retry exception.');
        } catch (NetworkException $actual) {
            self::assertSame($exception, $actual);
        }

        $serialized = serialize($logger->entries);
        self::assertStringNotContainsString($sentinel, $serialized);
        self::assertStringNotContainsString('SENTINEL_EXHAUSTED_QUERY', $serialized);
        self::assertCount(1, $logger->entries);
    }

    public function testRetryOnConnectionException(): void
    {
        $expectedResponse = ['data' => 'success'];

        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/test', [])
            ->andThrow(new ConnectionException('Connection failed', 'https://api.example.com', [], 0));

        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/test', [])
            ->andReturn($expectedResponse);

        $response = $this->retryClient->request('GET', '/test', []);

        $this->assertEquals($expectedResponse, $response);
    }

    // ========== RETRY ON 5XX SERVER ERRORS ==========

    public function testRetryOn500ServerError(): void
    {
        $expectedResponse = ['data' => 'success'];

        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/test', [])
            ->andThrow(new ApiException('Internal Server Error', [], 500));

        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/test', [])
            ->andReturn($expectedResponse);

        $response = $this->retryClient->request('GET', '/test', []);

        $this->assertEquals($expectedResponse, $response);
    }

    public function testRetryOn503ServiceUnavailable(): void
    {
        $expectedResponse = ['data' => 'success'];

        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/test', [])
            ->andThrow(new ApiException('Service Unavailable', [], 503));

        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/test', [])
            ->andReturn($expectedResponse);

        $response = $this->retryClient->request('GET', '/test', []);

        $this->assertEquals($expectedResponse, $response);
    }

    public function testSuccessOnThirdAttemptAfterTwoServerErrors(): void
    {
        $expectedResponse = ['data' => 'success'];

        // First attempt: 500
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/test', [])
            ->andThrow(new ApiException('Internal Server Error', [], 500));

        // Second attempt: 503
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/test', [])
            ->andThrow(new ApiException('Service Unavailable', [], 503));

        // Third attempt: success
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/test', [])
            ->andReturn($expectedResponse);

        $response = $this->retryClient->request('GET', '/test', []);

        $this->assertEquals($expectedResponse, $response);
    }

    // ========== RETRY ON 429 RATE LIMIT ==========

    public function testRetryOn429RateLimit(): void
    {
        $expectedResponse = ['data' => 'success'];

        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/test', [])
            ->andThrow(new RateLimitException('Too Many Requests', [], 1)); // 1 second retry

        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/test', [])
            ->andReturn($expectedResponse);

        $response = $this->retryClient->request('GET', '/test', []);

        $this->assertEquals($expectedResponse, $response);
    }

    /**
     * A server-supplied Retry-After must never exceed retry_max_delay_ms.
     *
     * Without the cap, `Retry-After: 86400` — a legitimate value — parked the worker for
     * a full day on a single response.
     */
    public function testRetryAfterIsCappedByMaxDelay(): void
    {
        $expectedResponse = ['data' => 'success'];

        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/test', [])
            ->andThrow(new RateLimitException('Too Many Requests', [], 86400)); // 24 hours

        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/test', [])
            ->andReturn($expectedResponse);

        $startedAt = microtime(true);
        $response = $this->retryClient->request('GET', '/test', []);
        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        $this->assertEquals($expectedResponse, $response);
        $this->assertLessThan(
            1000,
            $elapsedMs,
            'Retry-After must be capped at retry_max_delay_ms (100 ms in this configuration)'
        );
    }

    // ========== NON-IDEMPOTENT METHODS ==========

    /**
     * A network error is ambiguous: the POST may already have been applied. Replaying it
     * can duplicate an order or an upload, so it is not retried by default.
     */
    public function testPostIsNotRetriedByDefault(): void
    {
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('POST', '/orders', [])
            ->andThrow(new NetworkException('Connection reset'));

        $this->expectException(NetworkException::class);

        $this->retryClient->request('POST', '/orders', []);
    }

    public function testPatchIsNotRetriedByDefault(): void
    {
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('PATCH', '/orders/1', [])
            ->andThrow(new ApiException('Server error', [], 503));

        $this->expectException(ApiException::class);

        $this->retryClient->request('PATCH', '/orders/1', []);
    }

    /**
     * The caller can accept the duplication risk explicitly, per request.
     */
    public function testPostIsRetriedWithExplicitOptIn(): void
    {
        $expectedResponse = ['id' => 42];

        // The opt-in flag is consumed by the decorator and must not reach the transport.
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('POST', '/orders', [])
            ->andThrow(new NetworkException('Connection reset'));

        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('POST', '/orders', [])
            ->andReturn($expectedResponse);

        $response = $this->retryClient->request('POST', '/orders', ['retry_non_idempotent' => true]);

        $this->assertEquals($expectedResponse, $response);
    }

    /**
     * PUT and DELETE are idempotent by definition and keep retrying without opt-in.
     */
    #[DataProvider('idempotentMethodProvider')]
    public function testIdempotentMethodsAreRetried(string $method): void
    {
        $expectedResponse = ['ok' => true];

        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with($method, '/resource', [])
            ->andThrow(new NetworkException('Connection reset'));

        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with($method, '/resource', [])
            ->andReturn($expectedResponse);

        $response = $this->retryClient->request($method, '/resource', []);

        $this->assertEquals($expectedResponse, $response);
    }

    /**
     * @return array<int, array<int, string>>
     */
    public static function idempotentMethodProvider(): array
    {
        return [['GET'], ['HEAD'], ['PUT'], ['DELETE'], ['OPTIONS']];
    }

    public function testRequestRawHonoursTheSameMethodPolicy(): void
    {
        $this->mockClient
            ->shouldReceive('requestRaw')
            ->once()
            ->with('POST', '/upload', [])
            ->andThrow(new NetworkException('Connection reset'));

        $this->expectException(NetworkException::class);

        $this->retryClient->requestRaw('POST', '/upload', []);
    }

    // ========== NO RETRY ON 4XX CLIENT ERRORS ==========

    public function testNoRetryOn401Unauthorized(): void
    {
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/auth', [])
            ->andThrow(new AuthenticationException('Unauthorized', [], 401));

        $this->expectException(AuthenticationException::class);

        $this->retryClient->request('GET', '/auth', []);
    }

    public function testNoRetryOn403Forbidden(): void
    {
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/admin', [])
            ->andThrow(new ForbiddenException('Forbidden', [], 403));

        $this->expectException(ForbiddenException::class);

        $this->retryClient->request('GET', '/admin', []);
    }

    public function testNoRetryOn404NotFound(): void
    {
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/not-exists', [])
            ->andThrow(new NotFoundException('Not Found', [], 404));

        $this->expectException(NotFoundException::class);

        $this->retryClient->request('GET', '/not-exists', []);
    }

    public function testNoRetryOn422Validation(): void
    {
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('POST', '/users', [])
            ->andThrow(new ValidationException('Validation failed', ['email' => 'required'], 422));

        $this->expectException(ValidationException::class);

        $this->retryClient->request('POST', '/users', []);
    }

    // ========== MAX ATTEMPTS EXHAUSTED ==========

    public function testFailureAfterMaxAttemptsExhausted(): void
    {
        // All 3 attempts fail with 500
        $this->mockClient
            ->shouldReceive('request')
            ->times(3)
            ->with('GET', '/test', [])
            ->andThrow(new ApiException('Internal Server Error', [], 500));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Internal Server Error');

        $this->retryClient->request('GET', '/test', []);
    }

    public function testNetworkErrorExhaustsAllAttempts(): void
    {
        // All 3 attempts fail with network error
        $this->mockClient
            ->shouldReceive('request')
            ->times(3)
            ->with('GET', '/test', [])
            ->andThrow(new NetworkException('Network error', [], 0));

        $this->expectException(NetworkException::class);

        $this->retryClient->request('GET', '/test', []);
    }

    // ========== RAW RESPONSE TESTS ==========

    public function testRequestRawWithRetry(): void
    {
        /* @phpstan-ignore-next-line */
        $mockResponse = Mockery::mock(ResponseInterface::class);
        /* @phpstan-ignore-next-line */
        $mockStream = Mockery::mock(StreamInterface::class);
        $mockStream->shouldReceive('getContents')->andReturn('{"data":"test"}');
        $mockResponse->shouldReceive('getBody')->andReturn($mockStream);

        // First attempt fails
        $this->mockClient
            ->shouldReceive('requestRaw')
            ->once()
            ->with('GET', '/test', [])
            ->andThrow(new NetworkException('Network error', [], 0));

        // Second attempt succeeds
        $this->mockClient
            ->shouldReceive('requestRaw')
            ->once()
            ->with('GET', '/test', [])
            ->andReturn($mockResponse);

        $response = $this->retryClient->requestRaw('GET', '/test', []);

        $this->assertInstanceOf(ResponseInterface::class, $response);
    }

    // ========== CONFIGURATION TESTS ==========

    public function testCustomMaxAttempts(): void
    {
        $config = new Configuration([
            'url' => 'https://api.example.com',
            'retry_enabled' => true,
            'retry_max_attempts' => 2, // Only 2 attempts
            'retry_initial_delay_ms' => 1,
            'retry_jitter' => false,
        ]);

        $retryClient = new RetryHttpClient(
            $this->mockClient, // @phpstan-ignore-line
            $config,
            new NullLogger()
        );

        // Both attempts fail
        $this->mockClient
            ->shouldReceive('request')
            ->times(2) // Only 2 attempts
            ->with('GET', '/test', [])
            ->andThrow(new ApiException('Server Error', [], 500));

        $this->expectException(ApiException::class);

        $retryClient->request('GET', '/test', []);
    }

    public function testSingleAttemptNoRetry(): void
    {
        $config = new Configuration([
            'url' => 'https://api.example.com',
            'retry_enabled' => true,
            'retry_max_attempts' => 1, // No retries, just 1 attempt
            'retry_initial_delay_ms' => 1,
            'retry_jitter' => false,
        ]);

        $retryClient = new RetryHttpClient(
            $this->mockClient, // @phpstan-ignore-line
            $config,
            new NullLogger()
        );

        // Single attempt fails
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/test', [])
            ->andThrow(new ApiException('Server Error', [], 500));

        $this->expectException(ApiException::class);

        $retryClient->request('GET', '/test', []);
    }
}

final class RetryCapturingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $entries = [];

    /** @param array<string, mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->entries[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
