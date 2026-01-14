<?php

declare(strict_types=1);

namespace Swotto\Tests\Response;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Swotto\Exception\StreamingException;
use Swotto\Response\SwottoResponse;

/**
 * SwottoResponseAdvancedTest.
 *
 * Advanced tests for SwottoResponse - binary detection, headers, CSV parsing, streaming.
 */
class SwottoResponseAdvancedTest extends TestCase
{
    private function createMockResponse(
        string $contentType,
        string $content,
        array $headers = []
    ): ResponseInterface {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getContents')->willReturn($content);
        $stream->method('__toString')->willReturn($content);
        $stream->method('isSeekable')->willReturn(true);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);
        $response->method('getStatusCode')->willReturn($headers['statusCode'] ?? 200);
        $response->method('getHeaders')->willReturn($headers['headers'] ?? []);
        $response->method('getHeaderLine')
            ->willReturnCallback(function ($header) use ($contentType, $headers) {
                if ($header === 'Content-Type') {
                    return $contentType;
                }
                if ($header === 'Content-Length' && isset($headers['contentLength'])) {
                    return (string) $headers['contentLength'];
                }
                if ($header === 'Content-Disposition' && isset($headers['contentDisposition'])) {
                    return $headers['contentDisposition'];
                }

                return '';
            });

        return $response;
    }

    /**
     * Test isBinary for PDF content.
     */
    public function testIsBinaryForPdf(): void
    {
        $mockResponse = $this->createMockResponse('application/pdf', 'PDF content');
        $swottoResponse = new SwottoResponse($mockResponse);

        $this->assertTrue($swottoResponse->isBinary());
        $this->assertTrue($swottoResponse->isPdf());
    }

    /**
     * Test isBinary for image content types.
     *
     * @dataProvider imageContentTypesProvider
     */
    public function testIsBinaryForImages(string $contentType): void
    {
        $mockResponse = $this->createMockResponse($contentType, 'binary image data');
        $swottoResponse = new SwottoResponse($mockResponse);

        $this->assertTrue($swottoResponse->isBinary());
    }

    /**
     * Data provider for image content types.
     *
     * @return array<array<string>>
     */
    public static function imageContentTypesProvider(): array
    {
        return [
            'png' => ['image/png'],
            'jpeg' => ['image/jpeg'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'svg' => ['image/svg+xml'],
        ];
    }

    /**
     * Test isBinary for video content types.
     *
     * @dataProvider videoContentTypesProvider
     */
    public function testIsBinaryForVideos(string $contentType): void
    {
        $mockResponse = $this->createMockResponse($contentType, 'binary video data');
        $swottoResponse = new SwottoResponse($mockResponse);

        $this->assertTrue($swottoResponse->isBinary());
    }

    /**
     * Data provider for video content types.
     *
     * @return array<array<string>>
     */
    public static function videoContentTypesProvider(): array
    {
        return [
            'mp4' => ['video/mp4'],
            'webm' => ['video/webm'],
            'avi' => ['video/x-msvideo'],
        ];
    }

    /**
     * Test isBinary for audio content types.
     *
     * @dataProvider audioContentTypesProvider
     */
    public function testIsBinaryForAudio(string $contentType): void
    {
        $mockResponse = $this->createMockResponse($contentType, 'binary audio data');
        $swottoResponse = new SwottoResponse($mockResponse);

        $this->assertTrue($swottoResponse->isBinary());
    }

    /**
     * Data provider for audio content types.
     *
     * @return array<array<string>>
     */
    public static function audioContentTypesProvider(): array
    {
        return [
            'mp3' => ['audio/mpeg'],
            'wav' => ['audio/wav'],
            'ogg' => ['audio/ogg'],
        ];
    }

    /**
     * Test isBinary returns false for text content.
     */
    public function testIsBinaryFalseForText(): void
    {
        $mockResponse = $this->createMockResponse('text/plain', 'plain text');
        $swottoResponse = new SwottoResponse($mockResponse);

        $this->assertFalse($swottoResponse->isBinary());
    }

    /**
     * Test isBinary returns false for JSON content.
     */
    public function testIsBinaryFalseForJson(): void
    {
        $mockResponse = $this->createMockResponse('application/json', '{"test": true}');
        $swottoResponse = new SwottoResponse($mockResponse);

        $this->assertFalse($swottoResponse->isBinary());
    }

    /**
     * Test getContentLength returns integer.
     */
    public function testGetContentLengthReturnsInteger(): void
    {
        $mockResponse = $this->createMockResponse(
            'application/json',
            '{"test": true}',
            ['contentLength' => 14]
        );
        $swottoResponse = new SwottoResponse($mockResponse);

        $length = $swottoResponse->getContentLength();

        $this->assertIsInt($length);
        $this->assertEquals(14, $length);
    }

    /**
     * Test getContentLength returns null when not set.
     */
    public function testGetContentLengthReturnsNullWhenNotSet(): void
    {
        $mockResponse = $this->createMockResponse('application/json', '{}');
        $swottoResponse = new SwottoResponse($mockResponse);

        $length = $swottoResponse->getContentLength();

        $this->assertNull($length);
    }

    /**
     * Test getFilename extracts filename from Content-Disposition.
     */
    public function testGetFilenameFromContentDisposition(): void
    {
        $mockResponse = $this->createMockResponse(
            'application/pdf',
            'PDF content',
            ['contentDisposition' => 'attachment; filename="report.pdf"']
        );
        $swottoResponse = new SwottoResponse($mockResponse);

        $filename = $swottoResponse->getFilename();

        $this->assertEquals('report.pdf', $filename);
    }

    /**
     * Test getFilename with single quotes.
     */
    public function testGetFilenameWithSingleQuotes(): void
    {
        $mockResponse = $this->createMockResponse(
            'application/pdf',
            'PDF content',
            ['contentDisposition' => "attachment; filename='document.pdf'"]
        );
        $swottoResponse = new SwottoResponse($mockResponse);

        $filename = $swottoResponse->getFilename();

        $this->assertEquals('document.pdf', $filename);
    }

    /**
     * Test getFilename without quotes.
     */
    public function testGetFilenameWithoutQuotes(): void
    {
        $mockResponse = $this->createMockResponse(
            'application/pdf',
            'PDF content',
            ['contentDisposition' => 'attachment; filename=invoice.pdf']
        );
        $swottoResponse = new SwottoResponse($mockResponse);

        $filename = $swottoResponse->getFilename();

        $this->assertEquals('invoice.pdf', $filename);
    }

    /**
     * Test getFilename returns null when no Content-Disposition.
     */
    public function testGetFilenameReturnsNullWhenNoHeader(): void
    {
        $mockResponse = $this->createMockResponse('application/pdf', 'PDF content');
        $swottoResponse = new SwottoResponse($mockResponse);

        $filename = $swottoResponse->getFilename();

        $this->assertNull($filename);
    }

    /**
     * Test getHeaders returns array.
     */
    public function testGetHeadersReturnsArray(): void
    {
        $headers = [
            'Content-Type' => ['application/json'],
            'X-Custom' => ['value1', 'value2'],
        ];

        $mockResponse = $this->createMockResponse(
            'application/json',
            '{}',
            ['headers' => $headers]
        );
        $swottoResponse = new SwottoResponse($mockResponse);

        $result = $swottoResponse->getHeaders();

        $this->assertEquals($headers, $result);
    }

    /**
     * Test getStatusCode returns integer.
     */
    public function testGetStatusCodeReturnsInteger(): void
    {
        $mockResponse = $this->createMockResponse(
            'application/json',
            '{}',
            ['statusCode' => 201]
        );
        $swottoResponse = new SwottoResponse($mockResponse);

        $statusCode = $swottoResponse->getStatusCode();

        $this->assertIsInt($statusCode);
        $this->assertEquals(201, $statusCode);
    }

    /**
     * Test getStream returns StreamInterface.
     */
    public function testGetStreamReturnsStreamInterface(): void
    {
        $mockResponse = $this->createMockResponse('application/json', '{}');
        $swottoResponse = new SwottoResponse($mockResponse);

        $stream = $swottoResponse->getStream();

        $this->assertInstanceOf(StreamInterface::class, $stream);
    }

    /**
     * Test CSV parsing in asArray.
     */
    public function testCsvParsingInAsArray(): void
    {
        $csvContent = "name,email,age\nJohn,john@example.com,30\nJane,jane@example.com,25";
        $mockResponse = $this->createMockResponse('text/csv', $csvContent);
        $swottoResponse = new SwottoResponse($mockResponse);

        $result = $swottoResponse->asArray();

        $this->assertCount(2, $result);
        $this->assertEquals('John', $result[0]['name']);
        $this->assertEquals('john@example.com', $result[0]['email']);
        $this->assertEquals('30', $result[0]['age']);
        $this->assertEquals('Jane', $result[1]['name']);
    }

    /**
     * Test CSV parsing with empty content.
     */
    public function testCsvParsingWithEmptyContent(): void
    {
        $mockResponse = $this->createMockResponse('text/csv', '');
        $swottoResponse = new SwottoResponse($mockResponse);

        $result = $swottoResponse->asArray();

        $this->assertEquals([], $result);
    }

    /**
     * Test CSV parsing with only headers.
     */
    public function testCsvParsingWithOnlyHeaders(): void
    {
        $csvContent = 'name,email,age';
        $mockResponse = $this->createMockResponse('text/csv', $csvContent);
        $swottoResponse = new SwottoResponse($mockResponse);

        $result = $swottoResponse->asArray();

        $this->assertEquals([], $result);
    }

    /**
     * Test CSV parsing with rows having fewer columns.
     */
    public function testCsvParsingWithFewerColumns(): void
    {
        $csvContent = "name,email,age\nJohn,john@example.com";
        $mockResponse = $this->createMockResponse('text/csv', $csvContent);
        $swottoResponse = new SwottoResponse($mockResponse);

        $result = $swottoResponse->asArray();

        $this->assertCount(1, $result);
        $this->assertEquals('John', $result[0]['name']);
        $this->assertEquals('john@example.com', $result[0]['email']);
        $this->assertEquals('', $result[0]['age']); // Padded with empty string
    }

    /**
     * Test CSV parsing with rows having more columns.
     */
    public function testCsvParsingWithMoreColumns(): void
    {
        $csvContent = "name,email\nJohn,john@example.com,extra_data,more_data";
        $mockResponse = $this->createMockResponse('text/csv', $csvContent);
        $swottoResponse = new SwottoResponse($mockResponse);

        $result = $swottoResponse->asArray();

        $this->assertCount(1, $result);
        $this->assertCount(2, $result[0]); // Only 2 columns
        $this->assertEquals('John', $result[0]['name']);
        $this->assertEquals('john@example.com', $result[0]['email']);
    }

    /**
     * Test JSON parsing with various content type variations.
     *
     * @dataProvider jsonContentTypesProvider
     */
    public function testJsonContentTypeVariations(string $contentType): void
    {
        $jsonData = ['test' => 'value'];
        $mockResponse = $this->createMockResponse($contentType, '{"test":"value"}');
        $swottoResponse = new SwottoResponse($mockResponse);

        $this->assertTrue($swottoResponse->isJson());
        $this->assertEquals($jsonData, $swottoResponse->asArray());
    }

    /**
     * Data provider for JSON content types.
     *
     * @return array<array<string>>
     */
    public static function jsonContentTypesProvider(): array
    {
        return [
            'application/json' => ['application/json'],
            'text/json' => ['text/json'],
            'application/x-json' => ['application/x-json'],
            'application/json with charset' => ['application/json; charset=utf-8'],
        ];
    }

    /**
     * Test CSV content type variations.
     *
     * @dataProvider csvContentTypesProvider
     */
    public function testCsvContentTypeVariations(string $contentType): void
    {
        $csvContent = "col1,col2\nval1,val2";
        $mockResponse = $this->createMockResponse($contentType, $csvContent);
        $swottoResponse = new SwottoResponse($mockResponse);

        $this->assertTrue($swottoResponse->isCsv());
    }

    /**
     * Data provider for CSV content types.
     *
     * @return array<array<string>>
     */
    public static function csvContentTypesProvider(): array
    {
        return [
            'text/csv' => ['text/csv'],
            'application/csv' => ['application/csv'],
            'text/comma-separated-values' => ['text/comma-separated-values'],
        ];
    }

    /**
     * Test PDF content type variations.
     *
     * @dataProvider pdfContentTypesProvider
     */
    public function testPdfContentTypeVariations(string $contentType): void
    {
        $mockResponse = $this->createMockResponse($contentType, 'PDF content');
        $swottoResponse = new SwottoResponse($mockResponse);

        $this->assertTrue($swottoResponse->isPdf());
    }

    /**
     * Data provider for PDF content types.
     *
     * @return array<array<string>>
     */
    public static function pdfContentTypesProvider(): array
    {
        return [
            'application/pdf' => ['application/pdf'],
            'application/x-pdf' => ['application/x-pdf'],
        ];
    }

    /**
     * Test asArray throws for unsupported content type.
     */
    public function testAsArrayThrowsForUnsupportedContentType(): void
    {
        $mockResponse = $this->createMockResponse('text/html', '<html></html>');
        $swottoResponse = new SwottoResponse($mockResponse);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot parse content type');

        $swottoResponse->asArray();
    }

    /**
     * Test invalid JSON throws StreamingException.
     */
    public function testInvalidJsonThrowsStreamingException(): void
    {
        $mockResponse = $this->createMockResponse('application/json', '{invalid json}');
        $swottoResponse = new SwottoResponse($mockResponse);

        $this->expectException(StreamingException::class);
        $this->expectExceptionMessage('Invalid JSON response');

        $swottoResponse->asArray();
    }

    /**
     * Test response caching - asString called twice returns cached result.
     */
    public function testAsStringCachesResult(): void
    {
        $content = 'test content';
        $stream = $this->createMock(StreamInterface::class);
        $stream->expects($this->once())->method('getContents')->willReturn($content);
        $stream->method('isSeekable')->willReturn(true);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);
        $response->method('getHeaderLine')->willReturn('');

        $swottoResponse = new SwottoResponse($response);

        $result1 = $swottoResponse->asString();
        $result2 = $swottoResponse->asString();

        $this->assertEquals($content, $result1);
        $this->assertEquals($result1, $result2);
    }

    /**
     * Test response caching - asArray called twice returns cached result.
     */
    public function testAsArrayCachesResult(): void
    {
        $jsonData = ['cached' => true];
        $stream = $this->createMock(StreamInterface::class);
        $stream->expects($this->once())->method('getContents')->willReturn(json_encode($jsonData));
        $stream->method('isSeekable')->willReturn(true);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);
        $response->method('getHeaderLine')
            ->willReturnCallback(function ($header) {
                return $header === 'Content-Type' ? 'application/json' : '';
            });

        $swottoResponse = new SwottoResponse($response);

        $result1 = $swottoResponse->asArray();
        $result2 = $swottoResponse->asArray();

        $this->assertEquals($jsonData, $result1);
        $this->assertEquals($result1, $result2);
    }
}
