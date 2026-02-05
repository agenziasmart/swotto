<?php

declare(strict_types=1);

namespace Swotto\Tests;

use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\NullLogger;
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
