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

/**
 * Due difetti nel logging degli errori HTTP, misurati sul log di produzione di app.sw4.it
 * il 2026-08-10.
 *
 * 1. **Il livello.** Solo 401 e 404 erano declassati a `debug`; tutto il resto finiva in
 *    `error`, compreso un 422 di validazione — cioè un form inviato incompleto, l'esito più
 *    ordinario che un form abbia. Il consumer logga già l'esito con la propria severità
 *    (in APP.SW4 `ErrorCatcherMiddleware` legge `getLogLevel()` dell'eccezione mappata),
 *    quindi ogni 4xx produceva DUE righe, una delle quali nel canale sbagliato.
 *
 * 2. **Il corpo troncato.** Il messaggio veniva da `$exception->getMessage()`, che per Guzzle
 *    è un riassunto tagliato a 120 caratteri: la riga di log si fermava a
 *    `"error": {"type": "VALID…` e il campo che aveva fallito non si leggeva. Per scoprirlo
 *    bisognava risalire all'access log dell'API, che però registra solo status e dimensione.
 *
 * La cura del punto 2 ha un pericolo suo, ed è il motivo per cui
 * `testIlCorpoRestaLeggibileDaChiVieneDopo` esiste: `logException()` gira PRIMA di
 * `throwMappedException()`, che legge lo stesso stream con `getContents()`. Leggere il body
 * per loggarlo senza riavvolgerlo lascerebbe a valle una stringa vuota — i dati d'errore
 * sparirebbero dall'eccezione senza alcun errore, e il consumer riceverebbe un 422 muto.
 */
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

    /**
     * Il difetto misurato: la riga di log si fermava a 120 caratteri e il campo che aveva
     * fallito restava fuori. Qui il payload supera quella soglia di proposito.
     */
    public function testIlCorpoDellErroreArrivaInteroNelContesto(): void
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

        $logged = (string) ($this->lastContext()['response_body'] ?? '');

        $this->assertStringContainsString('warehouse_uuid', $logged);
        $this->assertStringNotContainsString('truncated', $logged);
    }

    /**
     * La guardia che conta. `logException()` legge lo stream prima di
     * `throwMappedException()`: senza rewind, a valle resterebbe una stringa vuota e
     * l'eccezione arriverebbe al consumer senza i dati d'errore, in silenzio.
     */
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
        $mockGuzzle->method('request')->willThrowException(
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

    /** Un corpo enorme non deve finire per intero in ogni riga di log. */
    public function testUnCorpoEnormeVieneLimitato(): void
    {
        $this->requestFailingWith(422, str_repeat('x', 10000));

        $logged = (string) ($this->lastContext()['response_body'] ?? '');

        $this->assertLessThan(10000, \strlen($logged));
        $this->assertGreaterThan(1000, \strlen($logged), 'Il limite non deve riportare la troncatura di Guzzle');
    }

    private function requestFailingWith(int $status, string $body): ?SwottoException
    {
        $response = new Response($status, ['Content-Type' => 'application/json'], $body);
        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->method('request')->willThrowException(
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
        $clientProperty->setAccessible(true);
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
