<?php

declare(strict_types=1);

namespace Swotto\Tests\Response;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Swotto\Response\SwottoResponse;

/**
 * SwottoResponseBasicTest.
 *
 * Basic unit tests for SwottoResponse functionality
 */
class SwottoResponseBasicTest extends TestCase
{
    private function createMockResponse(string $contentType, string $content): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getContents')->willReturn($content);
        $stream->method('__toString')->willReturn($content);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);
        $response->method('getHeaderLine')
            ->willReturnCallback(function ($header) use ($contentType) {
                if ($header === 'Content-Type') {
                    return $contentType;
                }

                return '';
            });

        return $response;
    }

    public function testCanCreateResponse(): void
    {
        $mockResponse = $this->createMockResponse('application/json', '{}');
        $swottoResponse = new SwottoResponse($mockResponse);

        $this->assertInstanceOf(SwottoResponse::class, $swottoResponse);
    }

    public function testIsJsonDetection(): void
    {
        $mockResponse = $this->createMockResponse('application/json', '{"test": "value"}');
        $swottoResponse = new SwottoResponse($mockResponse);

        $this->assertTrue($swottoResponse->isJson());
        $this->assertFalse($swottoResponse->isCsv());
        $this->assertFalse($swottoResponse->isPdf());
    }

    public function testIsCsvDetection(): void
    {
        $mockResponse = $this->createMockResponse('text/csv', 'col1,col2');
        $swottoResponse = new SwottoResponse($mockResponse);

        $this->assertTrue($swottoResponse->isCsv());
        $this->assertFalse($swottoResponse->isJson());
        $this->assertFalse($swottoResponse->isPdf());
    }

    public function testIsPdfDetection(): void
    {
        $mockResponse = $this->createMockResponse('application/pdf', 'PDF content');
        $swottoResponse = new SwottoResponse($mockResponse);

        $this->assertTrue($swottoResponse->isPdf());
        $this->assertFalse($swottoResponse->isJson());
        $this->assertFalse($swottoResponse->isCsv());
    }

    public function testGetContentType(): void
    {
        $mockResponse = $this->createMockResponse('application/json; charset=utf-8', '{}');
        $swottoResponse = new SwottoResponse($mockResponse);

        $contentType = $swottoResponse->getContentType();

        $this->assertEquals('application/json; charset=utf-8', $contentType);
    }

    public function testBasicAsArrayParsing(): void
    {
        $jsonData = ['name' => 'Test', 'value' => 123];
        $mockResponse = $this->createMockResponse('application/json', (string) json_encode($jsonData));
        $swottoResponse = new SwottoResponse($mockResponse);

        $result = $swottoResponse->asArray();

        $this->assertEquals($jsonData, $result);
    }

    public function testAsStringReturnsContent(): void
    {
        $content = 'Simple text content';
        $mockResponse = $this->createMockResponse('text/plain', $content);
        $swottoResponse = new SwottoResponse($mockResponse);

        $result = $swottoResponse->asString();

        $this->assertEquals($content, $result);
    }
}
