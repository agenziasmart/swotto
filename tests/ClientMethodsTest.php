<?php

declare(strict_types=1);

namespace Swotto\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Swotto\Client;
use Swotto\Contract\HttpClientInterface;
use Swotto\Response\SwottoResponse;

/**
 * ClientMethodsTest.
 *
 * Test Client methods: checkSession, getResponse, downloadToFile, setClientUserAgent, setClientIp.
 */
class ClientMethodsTest extends TestCase
{
    private HttpClientInterface $mockHttpClient;

    private LoggerInterface $mockLogger;

    private Client $client;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->mockHttpClient = $this->createMock(HttpClientInterface::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);

        $this->client = new Client(
            ['url' => 'https://api.example.com'],
            $this->mockLogger,
            $this->mockHttpClient
        );

        $this->tempDir = sys_get_temp_dir() . '/swotto_test_' . uniqid();
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
            rmdir($this->tempDir);
        }
    }

    // ========== checkSession Tests ==========

    /**
     * Test checkSession returns session data.
     */
    public function testCheckSessionReturnsData(): void
    {
        $expectedData = [
            'user' => ['id' => 1, 'name' => 'Test User'],
            'organization' => ['id' => 10, 'name' => 'Test Org'],
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'session', [])
            ->willReturn($expectedData);

        $result = $this->client->checkSession();

        $this->assertEquals($expectedData, $result);
    }

    /**
     * Test checkSession with custom options.
     */
    public function testCheckSessionWithOptions(): void
    {
        $expectedData = ['user' => ['id' => 1]];
        $options = ['headers' => ['X-Custom' => 'value']];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'session', $options)
            ->willReturn($expectedData);

        $result = $this->client->checkSession($options);

        $this->assertEquals($expectedData, $result);
    }

    /**
     * Test checkSession with null options (uses empty array).
     */
    public function testCheckSessionWithNullOptions(): void
    {
        $expectedData = ['session' => 'active'];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'session', [])
            ->willReturn($expectedData);

        $result = $this->client->checkSession(null);

        $this->assertEquals($expectedData, $result);
    }

    // ========== setClientUserAgent Tests ==========

    /**
     * Test setClientUserAgent updates configuration.
     */
    public function testSetClientUserAgent(): void
    {
        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Custom Browser';

        $this->mockHttpClient->expects($this->once())
            ->method('initialize')
            ->with($this->callback(function ($config) use ($userAgent) {
                return $config['client_user_agent'] === $userAgent;
            }));

        $this->client->setClientUserAgent($userAgent);
    }

    /**
     * Test setClientUserAgent with empty string.
     */
    public function testSetClientUserAgentEmptyString(): void
    {
        $this->mockHttpClient->expects($this->once())
            ->method('initialize')
            ->with($this->callback(function ($config) {
                return $config['client_user_agent'] === '';
            }));

        $this->client->setClientUserAgent('');
    }

    // ========== setClientIp Tests ==========

    /**
     * Test setClientIp updates configuration.
     */
    public function testSetClientIp(): void
    {
        $ip = '192.168.1.100';

        $this->mockHttpClient->expects($this->once())
            ->method('initialize')
            ->with($this->callback(function ($config) use ($ip) {
                return $config['client_ip'] === $ip;
            }));

        $this->client->setClientIp($ip);
    }

    /**
     * Test setClientIp with IPv6.
     */
    public function testSetClientIpWithIpv6(): void
    {
        $ip = '2001:0db8:85a3:0000:0000:8a2e:0370:7334';

        $this->mockHttpClient->expects($this->once())
            ->method('initialize')
            ->with($this->callback(function ($config) use ($ip) {
                return $config['client_ip'] === $ip;
            }));

        $this->client->setClientIp($ip);
    }

    // ========== getResponse Tests ==========

    /**
     * Test getResponse returns SwottoResponse.
     */
    public function testGetResponseReturnsSwottoResponse(): void
    {
        $mockStream = $this->createMock(StreamInterface::class);
        $mockStream->method('getContents')->willReturn('{"data": "test"}');

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getBody')->willReturn($mockStream);
        $mockResponse->method('getHeaderLine')->willReturn('application/json');

        $this->mockHttpClient->expects($this->once())
            ->method('requestRaw')
            ->with('GET', 'documents/123', [])
            ->willReturn($mockResponse);

        $result = $this->client->getResponse('documents/123');

        $this->assertInstanceOf(SwottoResponse::class, $result);
    }

    /**
     * Test getResponse with options.
     */
    public function testGetResponseWithOptions(): void
    {
        $mockStream = $this->createMock(StreamInterface::class);
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getBody')->willReturn($mockStream);
        $mockResponse->method('getHeaderLine')->willReturn('application/pdf');

        $options = ['timeout' => 60];

        $this->mockHttpClient->expects($this->once())
            ->method('requestRaw')
            ->with('GET', 'reports/pdf', $options)
            ->willReturn($mockResponse);

        $result = $this->client->getResponse('reports/pdf', $options);

        $this->assertInstanceOf(SwottoResponse::class, $result);
    }

    // ========== downloadToFile Tests ==========

    /**
     * Test downloadToFile successfully saves file.
     */
    public function testDownloadToFileSavesFile(): void
    {
        $content = 'PDF file content here';
        $filePath = $this->tempDir . '/downloaded.pdf';

        $mockStream = $this->createMock(StreamInterface::class);
        $mockStream->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $mockStream->method('read')->willReturn($content);

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getBody')->willReturn($mockStream);
        $mockResponse->method('getHeaderLine')->willReturn('application/pdf');

        $this->mockHttpClient->expects($this->once())
            ->method('requestRaw')
            ->with('GET', 'reports/123', [])
            ->willReturn($mockResponse);

        $result = $this->client->downloadToFile('reports/123', $filePath);

        $this->assertTrue($result);
        $this->assertFileExists($filePath);
        $this->assertEquals($content, file_get_contents($filePath));
    }

    /**
     * Test downloadToFile with options.
     */
    public function testDownloadToFileWithOptions(): void
    {
        $content = 'File content';
        $filePath = $this->tempDir . '/file.txt';
        $options = ['headers' => ['Accept' => 'application/octet-stream']];

        $mockStream = $this->createMock(StreamInterface::class);
        $mockStream->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $mockStream->method('read')->willReturn($content);

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getBody')->willReturn($mockStream);
        $mockResponse->method('getHeaderLine')->willReturn('application/octet-stream');

        $this->mockHttpClient->expects($this->once())
            ->method('requestRaw')
            ->with('GET', 'files/456', $options)
            ->willReturn($mockResponse);

        $result = $this->client->downloadToFile('files/456', $filePath, $options);

        $this->assertTrue($result);
    }

    /**
     * Test downloadToFile throws on invalid path.
     */
    public function testDownloadToFileThrowsOnInvalidPath(): void
    {
        $mockStream = $this->createMock(StreamInterface::class);
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getBody')->willReturn($mockStream);
        $mockResponse->method('getHeaderLine')->willReturn('application/pdf');

        $this->mockHttpClient->expects($this->once())
            ->method('requestRaw')
            ->willReturn($mockResponse);

        $this->expectException(\Swotto\Exception\SecurityException::class);

        $this->client->downloadToFile('reports/123', '/non/existent/dir/file.pdf');
    }

    // ========== Access Token Tests ==========

    /**
     * Test setAccessToken and getAccessToken.
     */
    public function testAccessTokenMethods(): void
    {
        $token = 'test-bearer-token-12345';

        // Initialize is called when setting token
        $this->mockHttpClient->expects($this->exactly(2))
            ->method('initialize');

        $this->client->setAccessToken($token);
        $this->assertEquals($token, $this->client->getAccessToken());
        $this->assertTrue($this->client->hasAccessToken());

        $this->client->clearAccessToken();
        $this->assertNull($this->client->getAccessToken());
        $this->assertFalse($this->client->hasAccessToken());
    }

    /**
     * Test hasAccessToken returns false with empty token.
     */
    public function testHasAccessTokenFalseWithEmptyToken(): void
    {
        $client = new Client(
            ['url' => 'https://api.example.com', 'access_token' => ''],
            $this->mockLogger,
            $this->mockHttpClient
        );

        $this->assertFalse($client->hasAccessToken());
    }

    /**
     * Test hasAccessToken returns true with valid token.
     */
    public function testHasAccessTokenTrueWithValidToken(): void
    {
        $client = new Client(
            ['url' => 'https://api.example.com', 'access_token' => 'valid-token'],
            $this->mockLogger,
            $this->mockHttpClient
        );

        $this->assertTrue($client->hasAccessToken());
    }
}
