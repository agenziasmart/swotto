<?php

declare(strict_types=1);

namespace Swotto\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Swotto\Contract\HttpClientInterface;
use Swotto\Response\SwottoResponse;
use Swotto\SwottoClient;

class SwottoClientMethodsTest extends TestCase
{
    private HttpClientInterface $mockHttpClient;

    private LoggerInterface $mockLogger;

    private SwottoClient $client;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->mockHttpClient = $this->createMock(HttpClientInterface::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);

        $this->client = new SwottoClient(
            ['url' => 'https://api.example.com'],
            $this->mockLogger,
            $this->mockHttpClient
        );

        $this->tempDir = sys_get_temp_dir() . '/swotto_test_' . uniqid();
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
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
}
