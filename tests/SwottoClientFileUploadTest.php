<?php

declare(strict_types=1);

namespace Swotto\Tests;

use GuzzleHttp\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Swotto\Contract\HttpClientInterface;
use Swotto\SwottoClient;

/**
 * SwottoClientFileUploadTest.
 *
 * Test file upload methods: postFile, postFiles, putFile, patchFile.
 */
class SwottoClientFileUploadTest extends TestCase
{
    private HttpClientInterface $mockHttpClient;

    private LoggerInterface $mockLogger;

    private SwottoClient $client;

    protected function setUp(): void
    {
        $this->mockHttpClient = $this->createMock(HttpClientInterface::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);

        $this->client = new SwottoClient(
            ['url' => 'https://api.example.com'],
            $this->mockLogger,
            $this->mockHttpClient
        );
    }

    /**
     * Test postFile with file resource.
     */
    public function testPostFileWithResource(): void
    {
        $expectedResponse = ['data' => ['id' => 123, 'filename' => 'test.txt']];
        $tempFile = tmpfile();
        fwrite($tempFile, 'test content');
        rewind($tempFile);

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'upload',
                $this->callback(function ($options) {
                    // Verify multipart structure
                    $this->assertArrayHasKey('multipart', $options);
                    $this->assertIsArray($options['multipart']);
                    $this->assertCount(1, $options['multipart']);
                    $this->assertEquals('file', $options['multipart'][0]['name']);
                    $this->assertIsResource($options['multipart'][0]['contents']);

                    return true;
                })
            )
            ->willReturn($expectedResponse);

        $result = $this->client->postFile('upload', $tempFile);

        $this->assertEquals($expectedResponse, $result);

        fclose($tempFile);
    }

    /**
     * Test postFile with StreamInterface.
     */
    public function testPostFileWithStreamInterface(): void
    {
        $expectedResponse = ['data' => ['id' => 456, 'filename' => 'stream.txt']];

        // Create a Guzzle Stream from a temp resource
        $tempFile = tmpfile();
        fwrite($tempFile, 'stream content');
        rewind($tempFile);
        $stream = new Stream($tempFile);

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'upload',
                $this->callback(function ($options) {
                    $this->assertArrayHasKey('multipart', $options);
                    $this->assertCount(1, $options['multipart']);
                    $this->assertEquals('file', $options['multipart'][0]['name']);
                    $this->assertInstanceOf(StreamInterface::class, $options['multipart'][0]['contents']);

                    return true;
                })
            )
            ->willReturn($expectedResponse);

        $result = $this->client->postFile('upload', $stream);

        $this->assertEquals($expectedResponse, $result);
    }

    /**
     * Test postFile with custom field name.
     */
    public function testPostFileWithCustomFieldName(): void
    {
        $expectedResponse = ['data' => ['id' => 789]];
        $tempFile = tmpfile();
        fwrite($tempFile, 'test content');
        rewind($tempFile);

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'upload',
                $this->callback(function ($options) {
                    $this->assertArrayHasKey('multipart', $options);
                    $this->assertEquals('document', $options['multipart'][0]['name']);

                    return true;
                })
            )
            ->willReturn($expectedResponse);

        $result = $this->client->postFile('upload', $tempFile, 'document');

        $this->assertEquals($expectedResponse, $result);

        fclose($tempFile);
    }

    /**
     * Test postFile with metadata.
     */
    public function testPostFileWithMetadata(): void
    {
        $expectedResponse = ['data' => ['id' => 111]];
        $tempFile = tmpfile();
        fwrite($tempFile, 'test content');
        rewind($tempFile);

        $metadata = [
            'title' => 'Test Document',
            'description' => 'A test file upload',
            'tags' => ['test', 'upload'],
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'upload',
                $this->callback(function ($options) {
                    $this->assertArrayHasKey('multipart', $options);
                    // File + 3 metadata fields
                    $this->assertCount(4, $options['multipart']);

                    // Check file entry
                    $this->assertEquals('file', $options['multipart'][0]['name']);

                    // Check metadata entries
                    $foundTitle = false;
                    $foundDescription = false;
                    $foundTags = false;

                    foreach ($options['multipart'] as $part) {
                        if ($part['name'] === 'title') {
                            $this->assertEquals('Test Document', $part['contents']);
                            $foundTitle = true;
                        }
                        if ($part['name'] === 'description') {
                            $this->assertEquals('A test file upload', $part['contents']);
                            $foundDescription = true;
                        }
                        if ($part['name'] === 'tags') {
                            // Arrays are JSON-encoded
                            $this->assertEquals('["test","upload"]', $part['contents']);
                            $foundTags = true;
                        }
                    }

                    $this->assertTrue($foundTitle, 'Title metadata not found');
                    $this->assertTrue($foundDescription, 'Description metadata not found');
                    $this->assertTrue($foundTags, 'Tags metadata not found');

                    return true;
                })
            )
            ->willReturn($expectedResponse);

        $result = $this->client->postFile('upload', $tempFile, 'file', $metadata);

        $this->assertEquals($expectedResponse, $result);

        fclose($tempFile);
    }

    /**
     * Test postFiles with multiple files.
     */
    public function testPostFilesMultipleFiles(): void
    {
        $expectedResponse = ['data' => ['uploaded' => 3]];

        $tempFile1 = tmpfile();
        fwrite($tempFile1, 'content 1');
        rewind($tempFile1);

        $tempFile2 = tmpfile();
        fwrite($tempFile2, 'content 2');
        rewind($tempFile2);

        $tempFile3 = tmpfile();
        fwrite($tempFile3, 'content 3');
        rewind($tempFile3);

        $files = [
            'file1' => $tempFile1,
            'file2' => $tempFile2,
            'file3' => $tempFile3,
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'bulk-upload',
                $this->callback(function ($options) {
                    $this->assertArrayHasKey('multipart', $options);
                    $this->assertCount(3, $options['multipart']);

                    $names = array_column($options['multipart'], 'name');
                    $this->assertContains('file1', $names);
                    $this->assertContains('file2', $names);
                    $this->assertContains('file3', $names);

                    return true;
                })
            )
            ->willReturn($expectedResponse);

        $result = $this->client->postFiles('bulk-upload', $files);

        $this->assertEquals($expectedResponse, $result);

        fclose($tempFile1);
        fclose($tempFile2);
        fclose($tempFile3);
    }

    /**
     * Test postFiles with metadata.
     */
    public function testPostFilesWithMetadata(): void
    {
        $expectedResponse = ['data' => ['uploaded' => 2]];

        $tempFile1 = tmpfile();
        fwrite($tempFile1, 'content 1');
        rewind($tempFile1);

        $tempFile2 = tmpfile();
        fwrite($tempFile2, 'content 2');
        rewind($tempFile2);

        $files = [
            'document1' => $tempFile1,
            'document2' => $tempFile2,
        ];

        $metadata = [
            'folder_id' => '12345',
            'overwrite' => 'true',
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'bulk-upload',
                $this->callback(function ($options) {
                    $this->assertArrayHasKey('multipart', $options);
                    // 2 files + 2 metadata fields
                    $this->assertCount(4, $options['multipart']);

                    return true;
                })
            )
            ->willReturn($expectedResponse);

        $result = $this->client->postFiles('bulk-upload', $files, $metadata);

        $this->assertEquals($expectedResponse, $result);

        fclose($tempFile1);
        fclose($tempFile2);
    }

    /**
     * Test putFile method.
     */
    public function testPutFile(): void
    {
        $expectedResponse = ['data' => ['id' => 123, 'updated' => true]];
        $tempFile = tmpfile();
        fwrite($tempFile, 'updated content');
        rewind($tempFile);

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with(
                'PUT',
                'documents/123',
                $this->callback(function ($options) {
                    $this->assertArrayHasKey('multipart', $options);
                    $this->assertCount(1, $options['multipart']);
                    $this->assertEquals('file', $options['multipart'][0]['name']);

                    return true;
                })
            )
            ->willReturn($expectedResponse);

        $result = $this->client->putFile('documents/123', $tempFile);

        $this->assertEquals($expectedResponse, $result);

        fclose($tempFile);
    }

    /**
     * Test putFile with metadata.
     */
    public function testPutFileWithMetadata(): void
    {
        $expectedResponse = ['data' => ['id' => 123]];
        $tempFile = tmpfile();
        fwrite($tempFile, 'updated content');
        rewind($tempFile);

        $metadata = ['version' => '2.0'];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with(
                'PUT',
                'documents/123',
                $this->callback(function ($options) {
                    $this->assertArrayHasKey('multipart', $options);
                    $this->assertCount(2, $options['multipart']); // file + metadata

                    return true;
                })
            )
            ->willReturn($expectedResponse);

        $result = $this->client->putFile('documents/123', $tempFile, 'file', $metadata);

        $this->assertEquals($expectedResponse, $result);

        fclose($tempFile);
    }

    /**
     * Test patchFile method.
     */
    public function testPatchFile(): void
    {
        $expectedResponse = ['data' => ['id' => 456, 'patched' => true]];
        $tempFile = tmpfile();
        fwrite($tempFile, 'patched content');
        rewind($tempFile);

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with(
                'PATCH',
                'documents/456',
                $this->callback(function ($options) {
                    $this->assertArrayHasKey('multipart', $options);
                    $this->assertCount(1, $options['multipart']);
                    $this->assertEquals('file', $options['multipart'][0]['name']);

                    return true;
                })
            )
            ->willReturn($expectedResponse);

        $result = $this->client->patchFile('documents/456', $tempFile);

        $this->assertEquals($expectedResponse, $result);

        fclose($tempFile);
    }

    /**
     * Test patchFile with custom field name.
     */
    public function testPatchFileWithCustomFieldName(): void
    {
        $expectedResponse = ['data' => ['id' => 789]];
        $tempFile = tmpfile();
        fwrite($tempFile, 'patched content');
        rewind($tempFile);

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with(
                'PATCH',
                'documents/789',
                $this->callback(function ($options) {
                    $this->assertArrayHasKey('multipart', $options);
                    $this->assertEquals('attachment', $options['multipart'][0]['name']);

                    return true;
                })
            )
            ->willReturn($expectedResponse);

        $result = $this->client->patchFile('documents/789', $tempFile, 'attachment');

        $this->assertEquals($expectedResponse, $result);

        fclose($tempFile);
    }

    /**
     * Test patchFile with metadata.
     */
    public function testPatchFileWithMetadata(): void
    {
        $expectedResponse = ['data' => ['id' => 999]];
        $tempFile = tmpfile();
        fwrite($tempFile, 'patched content');
        rewind($tempFile);

        $metadata = ['note' => 'Partial update'];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with(
                'PATCH',
                'documents/999',
                $this->callback(function ($options) {
                    $this->assertArrayHasKey('multipart', $options);
                    $this->assertCount(2, $options['multipart']); // file + metadata

                    return true;
                })
            )
            ->willReturn($expectedResponse);

        $result = $this->client->patchFile('documents/999', $tempFile, 'file', $metadata);

        $this->assertEquals($expectedResponse, $result);

        fclose($tempFile);
    }

    /**
     * Test postFile with additional options.
     */
    public function testPostFileWithOptions(): void
    {
        $expectedResponse = ['data' => ['id' => 555]];
        $tempFile = tmpfile();
        fwrite($tempFile, 'test content');
        rewind($tempFile);

        $options = [
            'timeout' => 60,
            'headers' => ['X-Custom-Header' => 'value'],
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'upload',
                $this->callback(function ($opts) {
                    $this->assertArrayHasKey('multipart', $opts);
                    $this->assertArrayHasKey('timeout', $opts);
                    $this->assertEquals(60, $opts['timeout']);
                    $this->assertArrayHasKey('headers', $opts);
                    $this->assertEquals('value', $opts['headers']['X-Custom-Header']);

                    return true;
                })
            )
            ->willReturn($expectedResponse);

        $result = $this->client->postFile('upload', $tempFile, 'file', [], $options);

        $this->assertEquals($expectedResponse, $result);

        fclose($tempFile);
    }

    /**
     * Test postFiles with additional options.
     */
    public function testPostFilesWithOptions(): void
    {
        $expectedResponse = ['data' => ['uploaded' => 1]];
        $tempFile = tmpfile();
        fwrite($tempFile, 'content');
        rewind($tempFile);

        $files = ['file1' => $tempFile];
        $options = ['timeout' => 120];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'bulk-upload',
                $this->callback(function ($opts) {
                    $this->assertArrayHasKey('multipart', $opts);
                    $this->assertArrayHasKey('timeout', $opts);
                    $this->assertEquals(120, $opts['timeout']);

                    return true;
                })
            )
            ->willReturn($expectedResponse);

        $result = $this->client->postFiles('bulk-upload', $files, [], $options);

        $this->assertEquals($expectedResponse, $result);

        fclose($tempFile);
    }

    /**
     * Test postFile throws InvalidArgumentException for invalid file type.
     */
    public function testPostFileThrowsExceptionForInvalidType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('File resource must be a PHP resource or StreamInterface');

        // Pass a string instead of resource or StreamInterface
        // @phpstan-ignore-next-line - Intentionally passing invalid type to test exception
        $this->client->postFile('upload', 'not-a-resource');
    }

    /**
     * Test putFile throws InvalidArgumentException for invalid file type.
     */
    public function testPutFileThrowsExceptionForInvalidType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('File resource must be a PHP resource or StreamInterface');

        // Pass an array instead of resource or StreamInterface
        // @phpstan-ignore-next-line - Intentionally passing invalid type to test exception
        $this->client->putFile('upload', ['invalid' => 'type']);
    }

    /**
     * Test patchFile throws InvalidArgumentException for invalid file type.
     */
    public function testPatchFileThrowsExceptionForInvalidType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('File resource must be a PHP resource or StreamInterface');

        // Pass an integer instead of resource or StreamInterface
        // @phpstan-ignore-next-line - Intentionally passing invalid type to test exception
        $this->client->patchFile('upload', 12345);
    }
}
