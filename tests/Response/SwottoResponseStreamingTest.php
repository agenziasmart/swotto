<?php

declare(strict_types=1);

namespace Swotto\Tests\Response;

use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Swotto\Exception\MemoryException;
use Swotto\Exception\StreamingException;
use Swotto\Response\SwottoResponse;

/**
 * SwottoResponseStreamingTest.
 *
 * Regression tests for stream handling: rewinding, partial writes, truncated content,
 * the memory ceiling and non-array JSON. Each test pins down a defect that previously
 * returned success while losing or unbounding data.
 */
class SwottoResponseStreamingTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/swotto_streaming_' . uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tempDir)) {
            return;
        }

        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        rmdir($this->tempDir);
    }

    /**
     * Reading the response and then saving it used to produce a 0-byte file and return true.
     */
    public function testSaveToFileRewindsSeekableStreamAfterPreviousRead(): void
    {
        $content = '{"invoice":"2026-0001","total":1234.56}';
        $response = new SwottoResponse(
            new Response(200, ['Content-Type' => 'application/json'], $content)
        );

        $this->assertSame($content, $response->asString());

        $path = $this->tempDir . '/invoice.json';
        $this->assertTrue($response->saveToFile($path));
        $this->assertSame($content, file_get_contents($path));
    }

    /**
     * Same defect reached through asArray(), which consumes the stream via asString().
     */
    public function testSaveToFileWorksAfterAsArray(): void
    {
        $content = '{"rows":[1,2,3]}';
        $response = new SwottoResponse(
            new Response(200, ['Content-Type' => 'application/json'], $content)
        );

        $response->asArray();

        $path = $this->tempDir . '/rows.json';
        $this->assertTrue($response->saveToFile($path));
        $this->assertSame($content, file_get_contents($path));
    }

    /**
     * A consumed non-seekable stream cannot be recovered: fail loudly instead of
     * writing an empty file and reporting success.
     */
    public function testSaveToFileFailsOnConsumedNonSeekableStream(): void
    {
        $stream = new NoSeekStream(Utils::streamFor('payload'));

        $psrResponse = $this->createStub(ResponseInterface::class);
        $psrResponse->method('getBody')->willReturn($stream);
        $psrResponse->method('getHeaderLine')->willReturn('');

        $response = new SwottoResponse($psrResponse);
        $response->asString();

        $this->expectException(StreamingException::class);
        $this->expectExceptionMessage('not seekable and has already been consumed');

        $response->saveToFile($this->tempDir . '/never-written.bin');
    }

    /**
     * A body shorter than the advertised Content-Length is a truncated download and
     * must not be reported as a success.
     */
    public function testSaveToFileRejectsTruncatedContent(): void
    {
        $response = new SwottoResponse(
            new Response(200, ['Content-Length' => '1024'], 'only a few bytes')
        );

        $path = $this->tempDir . '/truncated.bin';

        try {
            $response->saveToFile($path);
            $this->fail('Expected StreamingException for truncated content');
        } catch (StreamingException $exception) {
            $this->assertStringContainsString('Unexpected end of stream', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($path, 'A failed save must not leave a partial file behind');
    }

    /**
     * A body matching Content-Length passes the integrity check.
     */
    public function testSaveToFileAcceptsContentMatchingContentLength(): void
    {
        $content = 'exactly this many bytes';
        $response = new SwottoResponse(
            new Response(200, ['Content-Length' => (string) strlen($content)], $content)
        );

        $path = $this->tempDir . '/complete.bin';

        $this->assertTrue($response->saveToFile($path));
        $this->assertSame($content, file_get_contents($path));
    }

    /**
     * A body larger than the chunk size exercises the multi-chunk write loop.
     */
    public function testSaveToFileWritesMultipleChunks(): void
    {
        $content = str_repeat('abcdefgh', 4096); // 32 KB, four chunks
        $response = new SwottoResponse(new Response(200, [], $content));

        $path = $this->tempDir . '/multi-chunk.bin';

        $this->assertTrue($response->saveToFile($path));
        $this->assertSame(strlen($content), filesize($path));
        $this->assertSame($content, file_get_contents($path));
    }

    /**
     * The memory ceiling used to depend entirely on Content-Length, so a chunked
     * response without the header bypassed it completely.
     */
    public function testAsStringEnforcesMemoryLimitWithoutContentLength(): void
    {
        $psrResponse = $this->createStub(ResponseInterface::class);
        $psrResponse->method('getBody')->willReturn($this->createOversizedStream());
        $psrResponse->method('getHeaderLine')->willReturn('');

        $response = new SwottoResponse($psrResponse);

        $this->expectException(MemoryException::class);

        $response->asString();
    }

    /**
     * An oversized Content-Length is still rejected before a single byte is read.
     */
    public function testAsStringRejectsOversizedContentLengthUpFront(): void
    {
        $response = new SwottoResponse(
            new Response(200, ['Content-Length' => (string) (60 * 1024 * 1024)], 'small body')
        );

        $this->expectException(MemoryException::class);

        $response->asString();
    }

    /**
     * A non-numeric Content-Length must not be coerced to 0 and treated as a real length.
     */
    public function testNonNumericContentLengthIsIgnored(): void
    {
        $response = new SwottoResponse(
            new Response(200, ['Content-Length' => 'not-a-number'], 'body')
        );

        $this->assertNull($response->getContentLength());
        $this->assertSame('body', $response->asString());
    }

    /**
     * Valid but scalar JSON cannot satisfy the array contract: it used to escape as a
     * PHP TypeError instead of a Swotto exception.
     */
    public function testAsArrayRejectsScalarJson(): void
    {
        $response = new SwottoResponse(
            new Response(200, ['Content-Type' => 'application/json'], '42')
        );

        $this->expectException(StreamingException::class);
        $this->expectExceptionMessage('Expected a JSON object or array, int given');

        $response->asArray();
    }

    /**
     * Same contract for a JSON string literal.
     */
    public function testAsArrayRejectsJsonStringLiteral(): void
    {
        $response = new SwottoResponse(
            new Response(200, ['Content-Type' => 'application/json'], '"just a string"')
        );

        $this->expectException(StreamingException::class);
        $this->expectExceptionMessage('Expected a JSON object or array, string given');

        $response->asArray();
    }

    /**
     * JSON `null` stays an empty array: it is a legitimate empty payload, not a type error.
     */
    public function testAsArrayTreatsJsonNullAsEmptyArray(): void
    {
        $response = new SwottoResponse(
            new Response(200, ['Content-Type' => 'application/json'], 'null')
        );

        $this->assertSame([], $response->asArray());
    }

    /**
     * Build a stream that reports more than the 50 MB ceiling without ever holding it
     * all in memory on the producing side.
     */
    private function createOversizedStream(): StreamInterface
    {
        $chunk = str_repeat('x', 8192);
        $chunksToServe = (int) ceil((51 * 1024 * 1024) / strlen($chunk));
        $served = 0;

        $stream = $this->createStub(StreamInterface::class);
        $stream->method('isSeekable')->willReturn(false);
        $stream->method('eof')->willReturnCallback(
            static function () use (&$served, $chunksToServe): bool {
                return $served >= $chunksToServe;
            }
        );
        $stream->method('read')->willReturnCallback(
            static function () use (&$served, $chunk): string {
                ++$served;

                return $chunk;
            }
        );

        return $stream;
    }
}
