<?php

declare(strict_types=1);

namespace Swotto\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Swotto\Config\Configuration;
use Swotto\Http\GuzzleHttpClient;

/**
 * Test log sanitization to prevent exposure of sensitive data.
 *
 * Verifies compliance with OWASP Logging Cheat Sheet and GDPR requirements.
 */
class LogSanitizationTest extends TestCase
{
    private Configuration $config;

    private LoggerInterface $mockLogger;

    private GuzzleHttpClient $httpClient;

    protected function setUp(): void
    {
        $this->config = new Configuration(['url' => 'https://api.example.com']);
    }

    /**
     * Create the logger mock for tests that assert on what was logged, and rebuild the
     * HTTP client around it.
     *
     * Built on demand rather than in setUp() so tests that observe logging through a stub
     * are not left holding an unconfigured mock.
     *
     * @return LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function useMockLogger(): LoggerInterface
    {
        $this->mockLogger = $this->createMock(LoggerInterface::class);
        $this->httpClient = new GuzzleHttpClient($this->config, $this->mockLogger);

        return $this->mockLogger;
    }

    public function testSanitizeMultipartBinaryData(): void
    {
        $response = new Response(200, [], (string) json_encode(['success' => true]));

        // Create a file resource for testing
        $fileContent = str_repeat('BINARY_DATA_', 1000); // Simulate binary file
        $tempFile = tmpfile();
        fwrite($tempFile, $fileContent);
        rewind($tempFile);

        $multipartOptions = [
            'multipart' => [
                [
                    'name' => 'avatar',
                    'contents' => $tempFile,
                ],
                [
                    'name' => 'description',
                    'contents' => 'User profile picture',
                ],
            ],
        ];

        // Expect logger to receive sanitized options (NOT the binary content)
        $this->useMockLogger()->expects($this->once())
            ->method('debug')
            ->with(
                'Requesting POST /upload options',
                ['payload_type' => 'multipart', 'payload_parts' => 2]
            );

        // Mock Guzzle client
        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $reflection = new \ReflectionClass($this->httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($this->httpClient, $mockGuzzle);

        $this->httpClient->request('POST', '/upload', $multipartOptions);

        fclose($tempFile);
    }

    public function testSanitizeSensitiveHeaders(): void
    {
        $response = new Response(200, [], (string) json_encode(['success' => true]));

        $optionsWithSensitiveHeaders = [
            'headers' => [
                'Authorization' => 'Bearer secret-token-12345',
                'Cookie' => 'session_id=abc123def456',
                'X-Api-Key' => 'api-key-xyz789',
                'Content-Type' => 'application/json',
            ],
        ];

        // Header values are never diagnostic metadata, even when a name looks harmless.
        $this->useMockLogger()->expects($this->once())
            ->method('debug')
            ->with(
                'Requesting GET /user options',
                []
            );

        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $reflection = new \ReflectionClass($this->httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($this->httpClient, $mockGuzzle);

        $this->httpClient->request('GET', '/user', $optionsWithSensitiveHeaders);
    }

    public function testSanitizeSensitiveFormParams(): void
    {
        $response = new Response(200, [], (string) json_encode(['success' => true]));

        $optionsWithPassword = [
            'form_params' => [
                'username' => 'john_doe',
                'password' => 'super-secret-password',
                'token' => 'auth-token-123',
                'email' => 'john@example.com',
            ],
        ];

        // Payload values are omitted wholesale; key-name redaction cannot be complete.
        $this->useMockLogger()->expects($this->once())
            ->method('debug')
            ->with(
                'Requesting POST /login options',
                ['payload_type' => 'form']
            );

        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $reflection = new \ReflectionClass($this->httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($this->httpClient, $mockGuzzle);

        $this->httpClient->request('POST', '/login', $optionsWithPassword);
    }

    public function testSanitizeSensitiveJsonBody(): void
    {
        $response = new Response(200, [], (string) json_encode(['success' => true]));

        $optionsWithSensitiveJson = [
            'json' => [
                'username' => 'jane_doe',
                'password' => 'secret-pass-456',
                'api_key' => 'key-abc-xyz',
                'profile' => ['name' => 'Jane', 'age' => 30],
            ],
        ];

        // Only the payload kind is useful and safe to log.
        $this->useMockLogger()->expects($this->once())
            ->method('debug')
            ->with(
                'Requesting POST /api/register options',
                ['payload_type' => 'json']
            );

        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $reflection = new \ReflectionClass($this->httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($this->httpClient, $mockGuzzle);

        $this->httpClient->request('POST', '/api/register', $optionsWithSensitiveJson);
    }

    public function testSanitizeStreamBody(): void
    {
        $response = new Response(200, [], (string) json_encode(['success' => true]));

        // Create a stream with content
        $tempFile = tmpfile();
        $streamContent = 'Large binary stream content here...';
        fwrite($tempFile, $streamContent);
        rewind($tempFile);
        $stream = new Stream($tempFile);

        $optionsWithStream = [
            'body' => $stream,
        ];

        // Neither stream content nor filesystem metadata reaches the log.
        $this->useMockLogger()->expects($this->once())
            ->method('debug')
            ->with(
                'Requesting PUT /document options',
                ['payload_type' => 'body']
            );

        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $reflection = new \ReflectionClass($this->httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($this->httpClient, $mockGuzzle);

        $this->httpClient->request('PUT', '/document', $optionsWithStream);

        fclose($tempFile);
    }

    public function testSanitizeDevappHeader(): void
    {
        $response = new Response(200, [], (string) json_encode(['success' => true]));

        $optionsWithDevapp = [
            'headers' => [
                'X-Devapp' => 'ff73121d-0dae-4a10-af37-5d9ee0a3c5b0',
                'Content-Type' => 'application/json',
            ],
        ];

        // Omitting all header values avoids leaks through new or application-specific names.
        $this->useMockLogger()->expects($this->once())
            ->method('debug')
            ->with(
                'Requesting GET /organizations options',
                []
            );

        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $reflection = new \ReflectionClass($this->httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($this->httpClient, $mockGuzzle);

        $this->httpClient->request('GET', '/organizations', $optionsWithDevapp);
    }

    public function testRequestRawAlsoSanitizesLogs(): void
    {
        $response = new Response(200, [], 'Raw response body');

        $optionsWithSensitiveData = [
            'headers' => [
                'Authorization' => 'Bearer token-xyz',
            ],
        ];

        // Verify requestRaw also sanitizes logs
        $this->useMockLogger()->expects($this->once())
            ->method('debug')
            ->with(
                'Raw request GET /raw options',
                []
            );

        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $reflection = new \ReflectionClass($this->httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($this->httpClient, $mockGuzzle);

        $this->httpClient->requestRaw('GET', '/raw', $optionsWithSensitiveData);
    }

    /**
     * Logging request options by subtraction is unsafe: Guzzle accepts credentials in many
     * top-level options and can add more in future releases. The diagnostic contract is an
     * allowlist of scalar transport metadata, never a sanitized copy of caller data.
     */
    public function testRequestOptionLogUsesAnAllowlistAndNeverCopiesCallerValues(): void
    {
        $sentinel = 'SENTINEL_OPTION_SECRET';
        $logged = $this->captureLoggedOptions('POST', '/auth', [
            'body' => "plain text {$sentinel}",
            'json' => ['innocent_name' => $sentinel],
            'form_params' => ['innocent_name' => $sentinel],
            'multipart' => [['name' => 'description', 'contents' => $sentinel]],
            'query' => "access_token={$sentinel}",
            'auth' => ['user', $sentinel],
            'proxy' => "https://user:{$sentinel}@proxy.example.com?token={$sentinel}",
            'cookies' => ['session' => $sentinel],
            'cert' => ['/run/secrets/client.pem', $sentinel],
            'ssl_key' => ['/run/secrets/client.key', $sentinel],
            'headers' => ['X-Unclassified' => $sentinel],
            'curl' => [10036 => $sentinel],
            'timeout' => 7.5,
            'connect_timeout' => 2,
            'read_timeout' => 3,
            'verify' => '/run/secrets/ca.pem',
            'http_errors' => true,
            'stream' => false,
        ]);

        $this->assertStringNotContainsString($sentinel, serialize($logged));
        $this->assertSame([
            'payload_type' => 'multipart',
            'payload_parts' => 1,
            'timeout' => 7.5,
            'connect_timeout' => 2.0,
            'read_timeout' => 3.0,
            'verify_ssl' => true,
            'http_errors' => true,
            'stream' => false,
        ], $logged);
    }

    /**
     * Test that binary strings from file_get_contents() are sanitized in logs.
     *
     * This test covers the CRITICAL BUG where binary data passed as strings
     * (not resources) were logged in full, causing GDPR violations and log bloat.
     *
     * Scenario: User uploads image via file_get_contents() - the original bug
     * Expected: Binary content replaced with '<binary data: X bytes>'
     * Security: Prevents privacy violations and operational issues
     *
     * @return void
     */
    public function testSanitizeBinaryStringInMultipart(): void
    {
        $response = new Response(200, [], (string) json_encode(['success' => true]));

        // Simulate binary data from file_get_contents()
        // Use realistic binary pattern (PNG header + random data)
        $binaryString = "\x89PNG\r\n\x1a\n" . str_repeat("\x00\x01\xFF\xFE", 250); // 1KB binary

        $multipartOptions = [
            'multipart' => [
                [
                    'name' => 'avatar',
                    'contents' => $binaryString,  // Binary STRING (not resource!) - THE BUG
                ],
                [
                    'name' => 'description',
                    'contents' => 'User profile picture',  // Text metadata (should be preserved)
                ],
            ],
        ];

        // Binary and text part values are both omitted; only the part count remains.
        $this->useMockLogger()->expects($this->once())
            ->method('debug')
            ->with(
                'Requesting POST /upload options',
                ['payload_type' => 'multipart', 'payload_parts' => 2]
            );

        // Mock Guzzle client
        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $reflection = new \ReflectionClass($this->httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($this->httpClient, $mockGuzzle);

        $this->httpClient->request('POST', '/upload', $multipartOptions);
    }

    /**
     * Test that UTF-8 text with emojis is NOT sanitized (false positive prevention).
     *
     * Ensures the binary detection algorithm doesn't incorrectly flag
     * valid UTF-8 text containing unicode/emojis as binary data.
     *
     * @return void
     */
    public function testPreserveUtf8StringWithEmojisInMultipart(): void
    {
        $response = new Response(200, [], (string) json_encode(['success' => true]));

        $utf8Text = 'User uploaded photo 📸 Successfully! 🎉 你好世界';

        $multipartOptions = [
            'multipart' => [
                [
                    'name' => 'description',
                    'contents' => $utf8Text,  // UTF-8 with emoji - should NOT be sanitized
                ],
            ],
        ];

        // Valid text can still contain personal data, so it is omitted like binary data.
        $this->useMockLogger()->expects($this->once())
            ->method('debug')
            ->with(
                'Requesting POST /upload options',
                ['payload_type' => 'multipart', 'payload_parts' => 1]
            );

        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $reflection = new \ReflectionClass($this->httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($this->httpClient, $mockGuzzle);

        $this->httpClient->request('POST', '/upload', $multipartOptions);
    }

    /**
     * Run a request and return the options the logger received at debug level.
     *
     * @param array<string, mixed> $options Options to send
     * @return array<string, mixed> Sanitized options as logged
     */
    private function captureLoggedOptions(string $method, string $uri, array $options): array
    {
        $logged = [];

        $this->runWithRecordingLogger(
            function (string $level, string $message, array $context) use (&$logged): void {
                if ($level === 'debug' && str_ends_with($message, 'options')) {
                    $logged = $context;
                }
            },
            $method,
            $uri,
            $options
        );

        return $logged;
    }

    /**
     * Run a request against a stubbed transport, feeding every log call to $record.
     *
     * @param callable(string, string, array<string, mixed>): void $record Log observer
     * @param array<string, mixed> $options Options to send
     */
    private function runWithRecordingLogger(
        callable $record,
        string $method,
        string $uri,
        array $options
    ): void {
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('info')
            ->willReturnCallback(static function (string $message, array $context = []) use ($record): void {
                $record('info', $message, $context);
            });
        $logger->method('debug')
            ->willReturnCallback(static function (string $message, array $context = []) use ($record): void {
                $record('debug', $message, $context);
            });

        $httpClient = new GuzzleHttpClient($this->config, $logger);

        $guzzle = $this->createStub(GuzzleClient::class);
        $guzzle->method('request')
            ->willReturn(new Response(200, [], (string) json_encode(['success' => true])));

        $reflection = new \ReflectionClass($httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($httpClient, $guzzle);

        $httpClient->request($method, $uri, $options);
    }

    /**
     * Only first-level keys of `json` and `form_params` were redacted, so a secret one
     * level deeper reached the logger in the clear.
     */
    public function testRedactsSecretsNestedInJsonBody(): void
    {
        $logged = $this->captureLoggedOptions('POST', '/integrations', [
            'json' => [
                'name' => 'ERP connector',
                'connection' => [
                    'username' => 'operator',
                    'password' => 'PLACEHOLDER_PASSWORD',
                    'oauth' => ['refresh_token' => 'PLACEHOLDER_REFRESH_TOKEN'],
                ],
            ],
        ]);

        $this->assertSame(['payload_type' => 'json'], $logged);
        $this->assertStringNotContainsString('PLACEHOLDER', serialize($logged));
    }

    /**
     * A container whose own name is sensitive is redacted whole, without descending into
     * it: nothing under `credentials` is worth the risk of leaking one key by omission.
     */
    public function testRedactsAWholeSensitiveContainer(): void
    {
        $logged = $this->captureLoggedOptions('POST', '/integrations', [
            'json' => [
                'name' => 'ERP connector',
                'credentials' => ['username' => 'operator', 'password' => 'PLACEHOLDER_PASSWORD'],
            ],
        ]);

        $this->assertSame(['payload_type' => 'json'], $logged);
        $this->assertStringNotContainsString('PLACEHOLDER', serialize($logged));
    }

    /**
     * The query container was not sanitized at all.
     */
    public function testRedactsSecretsInQueryString(): void
    {
        $logged = $this->captureLoggedOptions('GET', '/exports', [
            'query' => [
                'from' => '2026-01-01',
                'access_token' => 'PLACEHOLDER_ACCESS_TOKEN',
            ],
        ]);

        $this->assertSame([], $logged);
    }

    /**
     * Key matching ignores case and separators, so ApiKey, api-key and api_key all match.
     */
    public function testRedactionIgnoresCasingAndSeparators(): void
    {
        $logged = $this->captureLoggedOptions('POST', '/things', [
            'json' => [
                'ApiKey' => 'PLACEHOLDER_A',
                'api-key' => 'PLACEHOLDER_B',
                'CLIENT_SECRET' => 'PLACEHOLDER_C',
                'label' => 'kept',
            ],
        ]);

        $this->assertSame(['payload_type' => 'json'], $logged);
    }

    /**
     * In multipart the value sits under 'contents' while the field name sits under 'name',
     * so a generic key walk would miss a part literally named "password".
     */
    public function testRedactsSensitiveMultipartFieldByName(): void
    {
        $logged = $this->captureLoggedOptions('POST', '/login', [
            'multipart' => [
                ['name' => 'username', 'contents' => 'operator'],
                ['name' => 'password', 'contents' => 'PLACEHOLDER_PASSWORD'],
            ],
        ]);

        $this->assertSame(['payload_type' => 'multipart', 'payload_parts' => 2], $logged);
    }

    /**
     * A query string can carry a token, so the info-level request line drops it entirely.
     */
    public function testInfoLineOmitsQueryString(): void
    {
        $lines = [];

        $this->runWithRecordingLogger(
            function (string $level, string $message) use (&$lines): void {
                if ($level === 'info') {
                    $lines[] = $message;
                }
            },
            'GET',
            '/exports?access_token=PLACEHOLDER_ACCESS_TOKEN&from=2026-01-01',
            []
        );

        $this->assertSame(['Requesting GET /exports'], $lines);
    }

    public function testInfoLineUsesBoundedUriAndSafeMethodMetadata(): void
    {
        $lines = [];
        $method = "GET\r\nSENTINEL_METHOD";
        $uri = "ht\xFFtps://user:SENTINEL_BEFORE\xFESENTINEL_AFTER@api.example.com/safe"
            . "\r\n\x00\u{200B}\u{2028}\u{2029}\xC3\x28"
            . str_repeat('à', 300)
            . '?token=SENTINEL_QUERY#SENTINEL_FRAGMENT';
        $previousSubstitute = mb_substitute_character();
        mb_substitute_character(47);

        try {
            $this->runWithRecordingLogger(
                function (string $level, string $message) use (&$lines): void {
                    if ($level === 'info') {
                        $lines[] = $message;
                    }
                },
                $method,
                $uri,
                []
            );
        } finally {
            mb_substitute_character($previousSubstitute);
        }

        self::assertCount(1, $lines);
        self::assertStringStartsWith('Requesting UNKNOWN ', $lines[0]);
        self::assertStringContainsString('api.example.com/safe', $lines[0]);
        self::assertStringNotContainsString('SENTINEL_', $lines[0]);
        self::assertTrue(mb_check_encoding($lines[0], 'UTF-8'));
        self::assertDoesNotMatchRegularExpression('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $lines[0]);
        self::assertLessThanOrEqual(strlen('Requesting UNKNOWN ') + 512, strlen($lines[0]));
    }
}
