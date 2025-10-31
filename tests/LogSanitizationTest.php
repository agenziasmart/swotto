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
        $this->mockLogger = $this->createMock(LoggerInterface::class);
        $this->httpClient = new GuzzleHttpClient($this->config, $this->mockLogger);
    }

    public function testSanitizeMultipartBinaryData(): void
    {
        $response = new Response(200, [], json_encode(['success' => true]));

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
        $this->mockLogger->expects($this->once())
            ->method('info')
            ->with(
                'Requesting POST /upload',
                $this->callback(function ($loggedOptions) {
                    // Verify multipart is present
                    $this->assertArrayHasKey('multipart', $loggedOptions);

                    // Verify file contents are sanitized
                    $this->assertIsString($loggedOptions['multipart'][0]['contents']);
                    $this->assertStringContainsString('<binary data:', $loggedOptions['multipart'][0]['contents']);
                    $this->assertStringContainsString('bytes>', $loggedOptions['multipart'][0]['contents']);

                    // Verify non-binary data is preserved
                    $this->assertEquals('User profile picture', $loggedOptions['multipart'][1]['contents']);

                    return true;
                })
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
        $response = new Response(200, [], json_encode(['success' => true]));

        $optionsWithSensitiveHeaders = [
            'headers' => [
                'Authorization' => 'Bearer secret-token-12345',
                'Cookie' => 'session_id=abc123def456',
                'X-Api-Key' => 'api-key-xyz789',
                'Content-Type' => 'application/json',
            ],
        ];

        // Expect logger to mask sensitive headers but preserve non-sensitive ones
        $this->mockLogger->expects($this->once())
            ->method('info')
            ->with(
                'Requesting GET /user',
                $this->callback(function ($loggedOptions) {
                    $this->assertArrayHasKey('headers', $loggedOptions);

                    // Verify sensitive headers are masked
                    $this->assertEquals('****', $loggedOptions['headers']['Authorization']);
                    $this->assertEquals('****', $loggedOptions['headers']['Cookie']);
                    $this->assertEquals('****', $loggedOptions['headers']['X-Api-Key']);

                    // Verify non-sensitive headers are preserved
                    $this->assertEquals('application/json', $loggedOptions['headers']['Content-Type']);

                    return true;
                })
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
        $response = new Response(200, [], json_encode(['success' => true]));

        $optionsWithPassword = [
            'form_params' => [
                'username' => 'john_doe',
                'password' => 'super-secret-password',
                'token' => 'auth-token-123',
                'email' => 'john@example.com',
            ],
        ];

        // Expect logger to mask password and token but preserve other fields
        $this->mockLogger->expects($this->once())
            ->method('info')
            ->with(
                'Requesting POST /login',
                $this->callback(function ($loggedOptions) {
                    $this->assertArrayHasKey('form_params', $loggedOptions);

                    // Verify sensitive fields are masked
                    $this->assertEquals('****', $loggedOptions['form_params']['password']);
                    $this->assertEquals('****', $loggedOptions['form_params']['token']);

                    // Verify non-sensitive fields are preserved
                    $this->assertEquals('john_doe', $loggedOptions['form_params']['username']);
                    $this->assertEquals('john@example.com', $loggedOptions['form_params']['email']);

                    return true;
                })
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
        $response = new Response(200, [], json_encode(['success' => true]));

        $optionsWithSensitiveJson = [
            'json' => [
                'username' => 'jane_doe',
                'password' => 'secret-pass-456',
                'api_key' => 'key-abc-xyz',
                'profile' => ['name' => 'Jane', 'age' => 30],
            ],
        ];

        // Expect logger to mask password and api_key in JSON body
        $this->mockLogger->expects($this->once())
            ->method('info')
            ->with(
                'Requesting POST /api/register',
                $this->callback(function ($loggedOptions) {
                    $this->assertArrayHasKey('json', $loggedOptions);

                    // Verify sensitive fields are masked
                    $this->assertEquals('****', $loggedOptions['json']['password']);
                    $this->assertEquals('****', $loggedOptions['json']['api_key']);

                    // Verify non-sensitive fields are preserved
                    $this->assertEquals('jane_doe', $loggedOptions['json']['username']);
                    $this->assertEquals(['name' => 'Jane', 'age' => 30], $loggedOptions['json']['profile']);

                    return true;
                })
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
        $response = new Response(200, [], json_encode(['success' => true]));

        // Create a stream with content
        $tempFile = tmpfile();
        $streamContent = 'Large binary stream content here...';
        fwrite($tempFile, $streamContent);
        rewind($tempFile);
        $stream = new Stream($tempFile);

        $optionsWithStream = [
            'body' => $stream,
        ];

        // Expect logger to replace stream with size indicator
        $this->mockLogger->expects($this->once())
            ->method('info')
            ->with(
                'Requesting PUT /document',
                $this->callback(function ($loggedOptions) {
                    $this->assertArrayHasKey('body', $loggedOptions);

                    // Verify stream is sanitized
                    $this->assertIsString($loggedOptions['body']);
                    $this->assertStringContainsString('<stream:', $loggedOptions['body']);
                    $this->assertStringContainsString('bytes>', $loggedOptions['body']);

                    return true;
                })
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
        $response = new Response(200, [], json_encode(['success' => true]));

        $optionsWithDevapp = [
            'headers' => [
                'X-Devapp' => 'ff73121d-0dae-4a10-af37-5d9ee0a3c5b0',
                'Content-Type' => 'application/json',
            ],
        ];

        // Expect logger to mask X-Devapp header (SW4-specific)
        $this->mockLogger->expects($this->once())
            ->method('info')
            ->with(
                'Requesting GET /organizations',
                $this->callback(function ($loggedOptions) {
                    $this->assertArrayHasKey('headers', $loggedOptions);

                    // Verify X-Devapp is masked
                    $this->assertEquals('****', $loggedOptions['headers']['X-Devapp']);

                    // Verify non-sensitive headers are preserved
                    $this->assertEquals('application/json', $loggedOptions['headers']['Content-Type']);

                    return true;
                })
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
        $this->mockLogger->expects($this->once())
            ->method('info')
            ->with(
                'Raw request GET /raw',
                $this->callback(function ($loggedOptions) {
                    $this->assertEquals('****', $loggedOptions['headers']['Authorization']);

                    return true;
                })
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
        $response = new Response(200, [], json_encode(['success' => true]));

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

        // Expect logger to receive sanitized binary string
        $this->mockLogger->expects($this->once())
            ->method('info')
            ->with(
                'Requesting POST /upload',
                $this->callback(function ($loggedOptions) {
                    // Verify multipart array exists
                    $this->assertArrayHasKey('multipart', $loggedOptions);

                    // Verify binary STRING is sanitized (THE FIX)
                    $this->assertIsString($loggedOptions['multipart'][0]['contents']);
                    $this->assertMatchesRegularExpression(
                        '/^<binary data: \d+ bytes>$/',
                        $loggedOptions['multipart'][0]['contents'],
                        'Binary string should be sanitized to "<binary data: X bytes>" format'
                    );
                    $this->assertStringContainsString('1008 bytes', $loggedOptions['multipart'][0]['contents']);

                    // Verify NO binary content leaked
                    $this->assertStringNotContainsString("\x00", $loggedOptions['multipart'][0]['contents']);
                    $this->assertStringNotContainsString("\xFF", $loggedOptions['multipart'][0]['contents']);
                    $this->assertStringNotContainsString('PNG', $loggedOptions['multipart'][0]['contents']);

                    // Verify text metadata is preserved (not sanitized)
                    $this->assertEquals('User profile picture', $loggedOptions['multipart'][1]['contents']);

                    return true;
                })
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
        $response = new Response(200, [], json_encode(['success' => true]));

        $utf8Text = 'User uploaded photo 📸 Successfully! 🎉 你好世界';

        $multipartOptions = [
            'multipart' => [
                [
                    'name' => 'description',
                    'contents' => $utf8Text,  // UTF-8 with emoji - should NOT be sanitized
                ],
            ],
        ];

        // Expect UTF-8 text to be preserved completely
        $this->mockLogger->expects($this->once())
            ->method('info')
            ->with(
                'Requesting POST /upload',
                $this->callback(function ($loggedOptions) use ($utf8Text) {
                    // Verify UTF-8 text is preserved exactly
                    $this->assertEquals($utf8Text, $loggedOptions['multipart'][0]['contents']);
                    $this->assertStringNotContainsString('<binary data:', $loggedOptions['multipart'][0]['contents']);

                    return true;
                })
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
}
