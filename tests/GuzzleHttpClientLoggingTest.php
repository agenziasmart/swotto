<?php

declare(strict_types=1);

namespace Swotto\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;
use Stringable;
use Swotto\Config\Configuration;
use Swotto\Exception\SwottoException;
use Swotto\Http\GuzzleHttpClient;

/** Failure logs must be useful for correlation without copying upstream-controlled data. */
class GuzzleHttpClientLoggingTest extends TestCase
{
    private CapturingLogger $logs;

    private GuzzleHttpClient $httpClient;

    protected function setUp(): void
    {
        $this->logs = new CapturingLogger();
        $this->httpClient = new GuzzleHttpClient(
            new Configuration(['url' => 'https://api.example.com']),
            $this->logs
        );
    }

    public function testUnErroreDiValidazioneNonFinisceNelCanaleError(): void
    {
        $this->requestFailingWith(422, (string) json_encode([
            'success' => false,
            'error' => ['type' => 'VALIDATION_ERROR', 'details' => ['warehouse_uuid' => 'obbligatorio']],
        ]));

        $this->assertNotSame('error', $this->lastLevel());
    }

    public function testAuthenticationRejectionUsesSafeDebugContext(): void
    {
        $sentinel = 'SENTINEL_AUTH_RESPONSE_MESSAGE';

        $this->requestFailingWith(401, (string) json_encode([
            'error' => ['message' => $sentinel],
        ]));

        $this->assertSame('debug', $this->lastLevel());
        $this->assertSame([
            'failure_type' => 'http',
            'exception_type' => RequestException::class,
            'status' => 401,
        ], $this->lastContext());
        $this->assertStringNotContainsString($sentinel, serialize($this->logs->entries));
    }

    public function testIlCorpoDellErroreNonEntraNelContestoDelLog(): void
    {
        $body = (string) json_encode([
            'success' => false,
            'error' => [
                'type' => 'VALIDATION_ERROR',
                'message' => 'The given data was invalid, and here is a long explanation to push this payload well past the 120 characters that Guzzle would have kept.',
                'details' => ['warehouse_uuid' => 'obbligatorio'],
            ],
        ]);
        self::assertGreaterThan(120, \strlen($body), 'Il payload di prova deve superare la soglia di Guzzle');

        $this->requestFailingWith(422, $body);

        $this->assertArrayNotHasKey('response_body', $this->lastContext());
        $this->assertStringNotContainsString('warehouse_uuid', serialize($this->logs->entries));
    }

    /** Removing the body from logs must not remove it from the public exception contract. */
    public function testIlCorpoRestaLeggibileDaChiVieneDopo(): void
    {
        $caught = $this->requestFailingWith(422, (string) json_encode([
            'success' => false,
            'error' => ['type' => 'VALIDATION_ERROR', 'details' => ['warehouse_uuid' => 'obbligatorio']],
        ]));

        $this->assertNotNull($caught);
        $this->assertNotSame(
            [],
            $caught->getErrorData(),
            'Il log ha consumato lo stream: i dati d\'errore non arrivano piu\' al consumer'
        );
    }

    public function testUnGuastoDelServizioRestaUnErrore(): void
    {
        $this->requestFailingWith(500, (string) json_encode(['error' => ['type' => 'SYSTEM_INTERNAL_ERROR']]));

        $this->assertSame('error', $this->lastLevel());
    }

    public function testUnGuastoDiConnessioneRestaUnErrore(): void
    {
        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())->method('request')->willThrowException(
            new ConnectException('Connection refused', new Request('GET', 'test'))
        );
        $this->injectMockGuzzle($mockGuzzle);

        try {
            $this->httpClient->request('GET', 'test');
        } catch (\Throwable) {
            // atteso
        }

        $this->assertSame('error', $this->lastLevel());
    }

    public function testHttpFailureLogDoesNotExposeUpstreamData(): void
    {
        $exceptionSecret = 'SENTINEL_EXCEPTION_AUTH_SECRET';
        $responseSecret = 'SENTINEL_RESPONSE_AUTH_SECRET';
        $querySecret = 'SENTINEL_QUERY_AUTH_SECRET';
        $userInfoSecret = 'SENTINEL_URL_USERINFO_SECRET';
        $uri = "https://user:{$userInfoSecret}@api.example.com/me?access_token={$querySecret}";
        $requestId = 'req-safe-123';
        $response = new Response(
            503,
            ['X-Request-ID' => $requestId],
            (string) json_encode(['error' => ['message' => $responseSecret]])
        );
        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())->method('request')->willThrowException(
            new RequestException(
                $exceptionSecret,
                new Request('HEAD', $uri),
                $response
            )
        );
        $this->injectMockGuzzle($mockGuzzle);

        try {
            $this->httpClient->request('HEAD', $uri);
        } catch (\Throwable) {
            // atteso
        }

        $serializedLogs = serialize($this->logs->entries);
        $this->assertStringNotContainsString($exceptionSecret, $serializedLogs);
        $this->assertStringNotContainsString($responseSecret, $serializedLogs);
        $this->assertStringNotContainsString($querySecret, $serializedLogs);
        $this->assertStringNotContainsString($userInfoSecret, $serializedLogs);
        $this->assertSame('error', $this->lastLevel());
        $this->assertSame([
            'failure_type' => 'http',
            'exception_type' => RequestException::class,
            'status' => 503,
            'request_id' => $requestId,
        ], $this->lastContext());
    }

    public function testNetworkFailureLogDoesNotExposeExceptionOrQuery(): void
    {
        $exceptionSecret = 'SENTINEL_NETWORK_AUTH_SECRET';
        $querySecret = 'SENTINEL_NETWORK_QUERY_SECRET';
        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())->method('request')->willThrowException(
            new ConnectException(
                $exceptionSecret,
                new Request('HEAD', "me?access_token={$querySecret}")
            )
        );
        $this->injectMockGuzzle($mockGuzzle);

        try {
            $this->httpClient->request('HEAD', "me?access_token={$querySecret}");
        } catch (\Throwable) {
            // atteso
        }

        $serializedLogs = serialize($this->logs->entries);
        $this->assertStringNotContainsString($exceptionSecret, $serializedLogs);
        $this->assertStringNotContainsString($querySecret, $serializedLogs);
        $this->assertSame('error', $this->lastLevel());
        $this->assertSame([
            'failure_type' => 'network',
            'exception_type' => ConnectException::class,
        ], $this->lastContext());
    }

    public function testRequestIdNelLogELimitato(): void
    {
        $response = new Response(503, ['X-Request-ID' => str_repeat('r', 300)], '{}');
        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())->method('request')->willThrowException(
            new RequestException('failure', new Request('HEAD', 'me'), $response)
        );
        $this->injectMockGuzzle($mockGuzzle);

        try {
            $this->httpClient->request('HEAD', 'me');
        } catch (\Throwable) {
            // atteso
        }

        $requestId = $this->lastContext()['request_id'] ?? null;
        $this->assertIsString($requestId);
        $this->assertSame(128, strlen($requestId));
    }

    /** @param class-string<SwottoException> $expectedClass */
    #[DataProvider('safeExceptionMessageProvider')]
    public function testOnlyBusinessValidationStatusesExposeUpstreamMessages(
        int $status,
        string $expectedClass,
        string $expectedMessage
    ): void {
        $sentinel = 'SENTINEL_PUBLIC_EXCEPTION_MESSAGE';
        $caught = $this->requestFailingWith($status, (string) json_encode([
            'error' => ['message' => $sentinel, 'details' => ['sentinel' => $sentinel]],
        ]));

        self::assertInstanceOf($expectedClass, $caught);
        self::assertSame($expectedMessage === '@public' ? $sentinel : $expectedMessage, $caught->getMessage());
        self::assertSame($sentinel, $caught->getErrorData()['error']['details']['sentinel'] ?? null);
        self::assertNull($caught->getPrevious());
    }

    /** @return iterable<string, array{int, class-string<SwottoException>, string}> */
    public static function safeExceptionMessageProvider(): iterable
    {
        yield '400 validation is public' => [400, \Swotto\Exception\ValidationException::class, '@public'];
        yield '402 business is public' => [402, \Swotto\Exception\ApiException::class, '@public'];
        yield '409 business is public' => [409, \Swotto\Exception\ApiException::class, '@public'];
        yield '422 validation is public' => [422, \Swotto\Exception\ValidationException::class, '@public'];
        yield '401 authentication is constant' => [401, \Swotto\Exception\AuthenticationException::class, 'Unauthorized'];
        yield '403 authorization is constant' => [403, \Swotto\Exception\ForbiddenException::class, 'Forbidden'];
        yield '404 lookup is constant' => [404, \Swotto\Exception\NotFoundException::class, 'Not Found'];
        yield '418 default client error is constant' => [418, \Swotto\Exception\ApiException::class, 'HTTP request rejected.'];
        yield '429 throttling is constant' => [429, \Swotto\Exception\RateLimitException::class, 'Too Many Requests'];
        yield '500 upstream failure is constant' => [500, \Swotto\Exception\ApiException::class, 'Upstream service error.'];
    }

    public function testNetworkAndConnectionExceptionsHaveConstantPublicDiagnostics(): void
    {
        $sentinel = 'SENTINEL_TRANSPORT_EXCEPTION';
        $secret = 'SENTINEL_URI_SECRET';
        $uri = "https://user:{$secret}@api.example.com/auth?token={$secret}";

        $networkGuzzle = $this->createMock(GuzzleClient::class);
        $networkGuzzle->expects($this->once())->method('request')->willThrowException(
            new RequestException($sentinel, new Request('HEAD', $uri), null, null, ['errno' => 7])
        );
        $this->injectMockGuzzle($networkGuzzle);

        try {
            $this->httpClient->request('HEAD', $uri);
            self::fail('Expected NetworkException.');
        } catch (\Swotto\Exception\NetworkException $exception) {
            self::assertSame('Network request failed.', $exception->getMessage());
            self::assertSame(['message' => 'Network request failed.', 'code' => 0], $exception->getErrorData());
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString($sentinel, serialize($exception->getErrorData()));
            self::assertStringNotContainsString($secret, serialize($exception->getErrorData()));
        }

        $connectionGuzzle = $this->createMock(GuzzleClient::class);
        $connectionGuzzle->expects($this->once())->method('request')->willThrowException(
            new ConnectException($sentinel, new Request('HEAD', $uri), null, ['errno' => 7])
        );
        $this->injectMockGuzzle($connectionGuzzle);

        try {
            $this->httpClient->request('HEAD', $uri);
            self::fail('Expected ConnectionException.');
        } catch (\Swotto\Exception\ConnectionException $exception) {
            self::assertSame('Connection failed.', $exception->getMessage());
            self::assertSame('https://api.example.com', $exception->getUrl());
            self::assertSame([], $exception->getTraceDetails());
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString($sentinel, serialize($exception->getErrorData()));
            self::assertStringNotContainsString($secret, serialize($exception->getErrorData()));
        }
    }

    public function testRequestRawKeepsErrorDataButNeverUsesItAsExceptionOrLogText(): void
    {
        $sentinel = 'SENTINEL_RAW_RESPONSE_BODY';
        $response = new Response(500, ['X-Request-ID' => 'req-raw'], $sentinel);
        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())->method('request')->willThrowException(
            new RequestException($sentinel, new Request('GET', '/raw'), $response)
        );
        $this->injectMockGuzzle($mockGuzzle);

        try {
            $this->httpClient->requestRaw('GET', '/raw');
            self::fail('Expected ApiException.');
        } catch (\Swotto\Exception\ApiException $exception) {
            self::assertSame('Upstream service error.', $exception->getMessage());
            self::assertSame(['raw_body' => $sentinel], $exception->getErrorData());
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString($sentinel, serialize($this->logs->entries));
        }
    }

    public function testUnexpectedTransportExceptionIsReplacedWithoutPreviousOrRawText(): void
    {
        $sentinel = 'SENTINEL_UNEXPECTED_TRANSPORT_FAILURE';
        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())->method('request')->willThrowException(
            new \RuntimeException($sentinel, 77, new \LogicException("previous-{$sentinel}"))
        );
        $this->injectMockGuzzle($mockGuzzle);

        try {
            $this->httpClient->request('GET', '/auth');
            self::fail('Expected NetworkException.');
        } catch (\Swotto\Exception\NetworkException $exception) {
            self::assertSame('Network request failed.', $exception->getMessage());
            self::assertSame(77, $exception->getStatusCode());
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString($sentinel, serialize($this->logs->entries));
            self::assertStringNotContainsString($sentinel, serialize($exception->getErrorData()));
        }
    }

    public function testRequestIdIsControlSafeValidUtf8AndByteBounded(): void
    {
        $requestId = "safe\r\n\x00-invalid-\xC3\x28-prefix-" . str_repeat('à', 100);
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(503);
        $response->method('getHeaderLine')->willReturnCallback(
            static fn (string $name): string => $name === 'X-Request-ID' ? $requestId : ''
        );
        $response->method('getBody')->willReturn(\GuzzleHttp\Psr7\Utils::streamFor('{}'));
        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())->method('request')->willThrowException(
            new RequestException('failure', new Request('HEAD', 'me'), $response)
        );
        $this->injectMockGuzzle($mockGuzzle);

        try {
            $this->httpClient->request('HEAD', 'me');
        } catch (\Throwable) {
            // expected
        }

        $logged = $this->lastContext()['request_id'] ?? null;
        self::assertIsString($logged);
        self::assertTrue(mb_check_encoding($logged, 'UTF-8'));
        self::assertLessThanOrEqual(128, strlen($logged));
        self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $logged);
    }

    private function requestFailingWith(int $status, string $body): ?SwottoException
    {
        $response = new Response($status, ['Content-Type' => 'application/json'], $body);
        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())->method('request')->willThrowException(
            new RequestException('Client error', new Request('POST', 'whstransfer'), $response)
        );
        $this->injectMockGuzzle($mockGuzzle);

        try {
            $this->httpClient->request('POST', 'whstransfer');
        } catch (SwottoException $e) {
            return $e;
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function injectMockGuzzle(GuzzleClient $mockGuzzle): void
    {
        $reflection = new \ReflectionClass($this->httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setValue($this->httpClient, $mockGuzzle);
    }

    private function lastLevel(): string
    {
        $entry = $this->lastEntry();

        return $entry['level'];
    }

    /** @return array<string, mixed> */
    private function lastContext(): array
    {
        $entry = $this->lastEntry();

        return $entry['context'];
    }

    /** @return array{level: string, message: string, context: array<string, mixed>} */
    private function lastEntry(): array
    {
        $entries = $this->logs->entries;
        self::assertNotEmpty($entries, 'Nessuna riga di log emessa');

        return $entries[\count($entries) - 1];
    }
}

/**
 * Logger PSR-3 in memoria. Estende `AbstractLogger` di proposito: qualunque scorciatoia
 * (`->error()`, `->debug()`, `->log()`) finisce nello stesso punto con il proprio livello,
 * quindi il test misura il LIVELLO e non quale metodo sia stato chiamato.
 */
final class CapturingLogger extends AbstractLogger
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
