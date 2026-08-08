<?php

declare(strict_types=1);

namespace Swotto\Tests;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Swotto\Config\Configuration;
use Swotto\Contract\HttpClientInterface;
use Swotto\Exception\ConfigurationException;
use Swotto\Response\SwottoResponse;
use Swotto\SwottoClient;

/**
 * ContractRegressionTest.
 *
 * Public-contract regressions: configuration validation, request bodies that used to be
 * dropped, CSV records torn apart by naive line splitting, and MIME types an ERP exports
 * that were not recognised as binary.
 */
class ContractRegressionTest extends TestCase
{
    // ========== CONFIGURATION ==========

    /**
     * A non-string url reached rtrim() and surfaced as a TypeError rather than a
     * ConfigurationException.
     */
    public function testNonStringUrlIsRejectedWithConfigurationException(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('url must be a non-empty string, array given');

        new Configuration(['url' => []]);
    }

    public function testEmptyUrlIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        new Configuration(['url' => '   ']);
    }

    public function testUrlWithoutHostIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('not a valid absolute URL');

        new Configuration(['url' => 'api.example.com/v1']);
    }

    public function testUnsupportedSchemeIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('url scheme must be http or https');

        new Configuration(['url' => 'ftp://api.example.com']);
    }

    /**
     * http must keep working: the documented Docker setup reaches the API over
     * http://host.docker.internal:8081.
     */
    public function testPlainHttpUrlIsAccepted(): void
    {
        $config = new Configuration(['url' => 'http://host.docker.internal:8081/']);

        $this->assertSame('http://host.docker.internal:8081', $config->getBaseUrl());
    }

    // ========== REQUEST BODIES ==========

    /**
     * @return array<string, array{mixed, mixed}>
     */
    public static function scalarBodyProvider(): array
    {
        return [
            'string' => ['raw-payload', 'raw-payload'],
            'integer' => [42, '42'],
            'float' => [1.5, '1.5'],
        ];
    }

    /**
     * The signature declares `mixed $data`, but anything that was not a non-empty array
     * was silently discarded, so post($uri, 'raw-payload') sent nothing at all.
     */
    #[DataProvider('scalarBodyProvider')]
    public function testNonArrayDataBecomesRawBody(mixed $data, mixed $expectedBody): void
    {
        $captured = [];
        $client = $this->createRecordingClient(function (array $options) use (&$captured): void {
            $captured = $options;
        });

        $client->post('raw', $data);

        $this->assertSame($expectedBody, $captured['body'] ?? null);
        $this->assertArrayNotHasKey('json', $captured);
    }

    public function testStreamDataBecomesRawBody(): void
    {
        $captured = [];
        $client = $this->createRecordingClient(function (array $options) use (&$captured): void {
            $captured = $options;
        });

        $stream = Utils::streamFor('streamed payload');
        $client->put('raw', $stream);

        $this->assertSame($stream, $captured['body'] ?? null);
    }

    public function testArrayDataStillBecomesJson(): void
    {
        $captured = [];
        $client = $this->createRecordingClient(function (array $options) use (&$captured): void {
            $captured = $options;
        });

        $client->post('customers', ['name' => 'ACME']);

        $this->assertSame(['name' => 'ACME'], $captured['json'] ?? null);
        $this->assertArrayNotHasKey('body', $captured);
    }

    public function testEmptyArrayStillSendsNoBody(): void
    {
        $captured = [];
        $client = $this->createRecordingClient(function (array $options) use (&$captured): void {
            $captured = $options;
        });

        $client->post('ping', []);

        $this->assertArrayNotHasKey('json', $captured);
        $this->assertArrayNotHasKey('body', $captured);
    }

    public function testExplicitBodyOptionWins(): void
    {
        $captured = [];
        $client = $this->createRecordingClient(function (array $options) use (&$captured): void {
            $captured = $options;
        });

        $client->post('raw', ['ignored' => true], ['body' => 'explicit']);

        $this->assertSame('explicit', $captured['body'] ?? null);
        $this->assertArrayNotHasKey('json', $captured);
    }

    public function testUnsupportedDataTypeIsRejected(): void
    {
        $captured = [];
        $client = $this->createRecordingClient(function (array $options) use (&$captured): void {
            $captured = $options;
        });

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Request data must be an array, string, stream or scalar');

        $client->post('raw', new \stdClass());
    }

    // ========== CSV ==========

    /**
     * Splitting on "\n" before parsing tore a quoted multi-line field into two records.
     */
    public function testCsvKeepsQuotedMultilineFieldsIntact(): void
    {
        $csv = "name,notes\nAlice,\"line1\nline2\"\nBob,single\n";
        $response = new SwottoResponse(new Response(200, ['Content-Type' => 'text/csv'], $csv));

        $rows = $response->asArray();

        $this->assertCount(2, $rows);
        $this->assertSame(['name' => 'Alice', 'notes' => "line1\nline2"], $rows[0]);
        $this->assertSame(['name' => 'Bob', 'notes' => 'single'], $rows[1]);
    }

    public function testCsvHandlesEmbeddedCommasAndQuotes(): void
    {
        $csv = "sku,description\nA1,\"Bolt, hex, 8mm\"\nA2,\"Says \"\"hello\"\"\"\n";
        $response = new SwottoResponse(new Response(200, ['Content-Type' => 'text/csv'], $csv));

        $rows = $response->asArray();

        $this->assertSame('Bolt, hex, 8mm', $rows[0]['description']);
        $this->assertSame('Says "hello"', $rows[1]['description']);
    }

    public function testCsvSkipsBlankLines(): void
    {
        $csv = "a,b\n1,2\n\n3,4\n";
        $response = new SwottoResponse(new Response(200, ['Content-Type' => 'text/csv'], $csv));

        $this->assertCount(2, $response->asArray());
    }

    public function testCsvPadsShortRows(): void
    {
        $csv = "a,b,c\n1,2\n";
        $response = new SwottoResponse(new Response(200, ['Content-Type' => 'text/csv'], $csv));

        $this->assertSame(['a' => '1', 'b' => '2', 'c' => ''], $response->asArray()[0]);
    }

    /**
     * The SW4 API exports with a semicolon, the convention Excel expects in most of Europe.
     * Assuming a comma parsed every record as one column keyed by the whole header line —
     * silently, since nothing about it looks like a failure.
     */
    public function testCsvDetectsSemicolonDelimiter(): void
    {
        $csv = "\"Nome Cliente\";\"Partita IVA\";Email\nACME Srl;IT12345678901;info@example.com\n";
        $response = new SwottoResponse(new Response(200, ['Content-Type' => 'text/csv'], $csv));

        $rows = $response->asArray();

        $this->assertCount(1, $rows);
        $this->assertSame(
            ['Nome Cliente' => 'ACME Srl', 'Partita IVA' => 'IT12345678901', 'Email' => 'info@example.com'],
            $rows[0]
        );
    }

    public function testCsvDetectsTabDelimiter(): void
    {
        $csv = "a\tb\tc\n1\t2\t3\n";
        $response = new SwottoResponse(new Response(200, ['Content-Type' => 'text/csv'], $csv));

        $this->assertSame(['a' => '1', 'b' => '2', 'c' => '3'], $response->asArray()[0]);
    }

    /**
     * A separator inside a quoted field must not win the vote.
     */
    public function testCsvDelimiterDetectionIgnoresQuotedSeparators(): void
    {
        $csv = "name;notes\n\"ACME\";\"Bolt, hex, 8mm, zinc\"\n";
        $response = new SwottoResponse(new Response(200, ['Content-Type' => 'text/csv'], $csv));

        $rows = $response->asArray();

        $this->assertSame(['name' => 'ACME', 'notes' => 'Bolt, hex, 8mm, zinc'], $rows[0]);
    }

    /**
     * A single-column CSV has no separator to detect; the comma fallback must still work.
     */
    public function testCsvSingleColumnStillParses(): void
    {
        $csv = "email\nuno@example.com\ndue@example.com\n";
        $response = new SwottoResponse(new Response(200, ['Content-Type' => 'text/csv'], $csv));

        $rows = $response->asArray();

        $this->assertCount(2, $rows);
        $this->assertSame(['email' => 'uno@example.com'], $rows[0]);
    }

    /**
     * A UTF-8 BOM precedes the header in exports meant for Excel.
     */
    public function testCsvHandlesUtf8BomBeforeHeader(): void
    {
        $csv = "\xEF\xBB\xBFname;city\nACME;Milano\n";
        $response = new SwottoResponse(new Response(200, ['Content-Type' => 'text/csv'], $csv));

        $rows = $response->asArray();

        $this->assertCount(1, $rows);
        $this->assertSame('ACME', reset($rows[0]));
        $this->assertSame('Milano', $rows[0]['city']);
    }

    // ========== BINARY DETECTION ==========

    /**
     * @return array<int, array<int, string>>
     */
    public static function binaryContentTypeProvider(): array
    {
        return [
            ['application/octet-stream'],
            ['application/zip'],
            ['application/vnd.ms-excel'],
            ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            ['application/vnd.oasis.opendocument.text'],
            ['application/gzip'],
            ['application/pdf'],
            ['image/png'],
        ];
    }

    #[DataProvider('binaryContentTypeProvider')]
    public function testBinaryContentTypesAreRecognised(string $contentType): void
    {
        $response = new SwottoResponse(new Response(200, ['Content-Type' => $contentType], 'x'));

        $this->assertTrue($response->isBinary(), $contentType . ' should be binary');
    }

    /**
     * @return array<int, array<int, string>>
     */
    public static function textContentTypeProvider(): array
    {
        return [['application/json'], ['text/csv'], ['text/plain'], ['text/html']];
    }

    #[DataProvider('textContentTypeProvider')]
    public function testTextContentTypesAreNotBinary(string $contentType): void
    {
        $response = new SwottoResponse(new Response(200, ['Content-Type' => $contentType], 'x'));

        $this->assertFalse($response->isBinary(), $contentType . ' should not be binary');
    }

    /**
     * Build a client whose HTTP layer hands every set of options to $record.
     *
     * @param callable(array<string, mixed>): void $record Options observer
     */
    private function createRecordingClient(callable $record): SwottoClient
    {
        $httpClient = new class ($record) implements HttpClientInterface {
            /**
             * @var callable(array<string, mixed>): void
             */
            private $record;

            /**
             * @param callable(array<string, mixed>): void $record
             */
            public function __construct(callable $record)
            {
                $this->record = $record;
            }

            public function request(string $method, string $uri, array $options = []): array
            {
                ($this->record)($options);

                return [];
            }

            public function requestRaw(string $method, string $uri, array $options = []): ResponseInterface
            {
                ($this->record)($options);

                return new Response(200, [], '{}');
            }
        };

        return new SwottoClient(['url' => 'https://api.example.com'], null, $httpClient);
    }
}
