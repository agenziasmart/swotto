<?php

declare(strict_types=1);

namespace Swotto\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
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
