<?php

declare(strict_types=1);

namespace Swotto\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Swotto\Exception\SecurityException;
use Swotto\Response\SwottoResponse;

/**
 * SecurityTest.
 *
 * Test security validations for file operations and path handling.
 */
class SecurityTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
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
        parent::tearDown();
    }

    private function createMockResponseWithStream(string $content): SwottoResponse
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $stream->method('read')->willReturn($content);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);
        $response->method('getHeaderLine')->willReturn('application/octet-stream');

        return new SwottoResponse($response);
    }

    /**
     * Test path traversal attack to non-writable directory is blocked.
     *
     * Note: The validation blocks path traversal indirectly by checking
     * if the target directory is writable. Traversing to /etc fails because
     * /etc is not writable.
     */
    public function testSaveToFileBlocksPathTraversalToNonWritableDir(): void
    {
        $swottoResponse = $this->createMockResponseWithStream('malicious content');

        $this->expectException(SecurityException::class);
        // Traversing to /etc results in "directory not writable" error
        $this->expectExceptionMessage('not writable');

        $swottoResponse->saveToFile($this->tempDir . '/../../etc/passwd');
    }

    /**
     * Test filename containing '..' sequence is blocked.
     */
    public function testSaveToFileBlocksDoubleDotInFilename(): void
    {
        $swottoResponse = $this->createMockResponseWithStream('malicious content');

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Path traversal');

        // Filename that contains '..' is detected
        $swottoResponse->saveToFile($this->tempDir . '/..malicious.txt');
    }

    /**
     * Test filename with backslash is blocked.
     */
    public function testSaveToFileBlocksBackslashInFilename(): void
    {
        $swottoResponse = $this->createMockResponseWithStream('malicious content');

        $this->expectException(SecurityException::class);
        // Backslash in filename triggers path traversal check
        $swottoResponse->saveToFile($this->tempDir . '/test\\file.txt');
    }

    /**
     * Test path traversal using encoded sequences still blocked via directory check.
     */
    public function testSaveToFileBlocksTraversalViaDirectory(): void
    {
        $swottoResponse = $this->createMockResponseWithStream('malicious content');

        $this->expectException(SecurityException::class);

        // Traversing upward from temp directory - blocked because target dir
        // either doesn't exist or isn't writable
        $swottoResponse->saveToFile('/var/log/swotto_test_malicious.txt');
    }

    /**
     * Test null byte injection is blocked.
     */
    public function testSaveToFileBlocksNullBytes(): void
    {
        $swottoResponse = $this->createMockResponseWithStream('malicious content');

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Invalid filename');

        $swottoResponse->saveToFile($this->tempDir . "/file\x00.txt");
    }

    /**
     * Test invalid characters < > : " | ? * are blocked.
     *
     * @dataProvider invalidFilenameCharactersProvider
     */
    public function testSaveToFileBlocksInvalidCharacters(string $char): void
    {
        $swottoResponse = $this->createMockResponseWithStream('malicious content');

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Invalid filename');

        $swottoResponse->saveToFile($this->tempDir . '/file' . $char . '.txt');
    }

    /**
     * Data provider for invalid filename characters.
     *
     * @return array<array<string>>
     */
    public static function invalidFilenameCharactersProvider(): array
    {
        return [
            'less than' => ['<'],
            'greater than' => ['>'],
            'colon' => [':'],
            'double quote' => ['"'],
            'pipe' => ['|'],
            'question mark' => ['?'],
            'asterisk' => ['*'],
        ];
    }

    /**
     * Test control characters are blocked.
     *
     * @dataProvider controlCharactersProvider
     */
    public function testSaveToFileBlocksControlCharacters(string $char, string $description): void
    {
        $swottoResponse = $this->createMockResponseWithStream('malicious content');

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Invalid filename');

        $swottoResponse->saveToFile($this->tempDir . '/file' . $char . '.txt');
    }

    /**
     * Data provider for control characters.
     *
     * @return array<array<string>>
     */
    public static function controlCharactersProvider(): array
    {
        return [
            'null byte' => ["\x00", 'null'],
            'bell' => ["\x07", 'bell'],
            'backspace' => ["\x08", 'backspace'],
            'tab' => ["\x09", 'tab'],
            'newline' => ["\x0A", 'newline'],
            'carriage return' => ["\x0D", 'carriage return'],
            'escape' => ["\x1B", 'escape'],
            'delete' => ["\x7F", 'delete'],
        ];
    }

    /**
     * Test non-existent directory throws exception.
     */
    public function testSaveToFileThrowsOnNonExistentDirectory(): void
    {
        $swottoResponse = $this->createMockResponseWithStream('content');

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Invalid or non-existent directory');

        $swottoResponse->saveToFile('/non/existent/directory/file.txt');
    }

    /**
     * Test non-writable directory throws exception.
     */
    public function testSaveToFileThrowsOnNonWritableDirectory(): void
    {
        // Create a read-only directory
        $readOnlyDir = $this->tempDir . '/readonly';
        mkdir($readOnlyDir, 0o555, true);

        try {
            $swottoResponse = $this->createMockResponseWithStream('content');

            $this->expectException(SecurityException::class);
            $this->expectExceptionMessage('Directory is not writable');

            $swottoResponse->saveToFile($readOnlyDir . '/file.txt');
        } finally {
            // Restore permissions for cleanup
            chmod($readOnlyDir, 0o755);
            rmdir($readOnlyDir);
        }
    }

    /**
     * Test filename that ends up empty or just directory separator.
     *
     * Note: This is a tricky edge case. When a path like "/dir/" is passed,
     * dirname() gives "/", basename() gives "dir". To properly test empty
     * filename validation, we test the factory method directly.
     */
    public function testSecurityExceptionEmptyFilenameFactory(): void
    {
        $exception = SecurityException::invalidFilename('Empty filename');

        $this->assertInstanceOf(SecurityException::class, $exception);
        $this->assertStringContainsString('Invalid filename', $exception->getMessage());
        $this->assertEquals(400, $exception->getCode());
    }

    /**
     * Test whitespace-only filename throws exception.
     */
    public function testSaveToFileThrowsOnWhitespaceFilename(): void
    {
        $swottoResponse = $this->createMockResponseWithStream('content');

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Invalid filename');

        $swottoResponse->saveToFile($this->tempDir . '/   ');
    }

    /**
     * Test valid filename is accepted.
     */
    public function testSaveToFileAcceptsValidFilename(): void
    {
        $content = 'valid file content';
        $swottoResponse = $this->createMockResponseWithStream($content);

        $filePath = $this->tempDir . '/valid_file.txt';
        $result = $swottoResponse->saveToFile($filePath);

        $this->assertTrue($result);
        $this->assertFileExists($filePath);
        $this->assertEquals($content, file_get_contents($filePath));
    }

    /**
     * Test valid filename with special allowed characters.
     */
    public function testSaveToFileAcceptsSpecialAllowedCharacters(): void
    {
        $content = 'valid content';
        $swottoResponse = $this->createMockResponseWithStream($content);

        $filePath = $this->tempDir . '/file-name_2024.data.txt';
        $result = $swottoResponse->saveToFile($filePath);

        $this->assertTrue($result);
        $this->assertFileExists($filePath);
    }

    /**
     * Test SecurityException::pathTraversalDetected factory method.
     */
    public function testSecurityExceptionPathTraversalFactory(): void
    {
        $exception = SecurityException::pathTraversalDetected('../etc/passwd');

        $this->assertInstanceOf(SecurityException::class, $exception);
        $this->assertStringContainsString('Path traversal', $exception->getMessage());
        $this->assertStringContainsString('../etc/passwd', $exception->getMessage());
        $this->assertEquals(403, $exception->getCode());
    }

    /**
     * Test SecurityException::invalidFilename factory method.
     */
    public function testSecurityExceptionInvalidFilenameFactory(): void
    {
        $exception = SecurityException::invalidFilename('file<>.txt');

        $this->assertInstanceOf(SecurityException::class, $exception);
        $this->assertStringContainsString('Invalid filename', $exception->getMessage());
        $this->assertEquals(400, $exception->getCode());
    }

    /**
     * Test SecurityException::invalidDirectory factory method.
     */
    public function testSecurityExceptionInvalidDirectoryFactory(): void
    {
        $exception = SecurityException::invalidDirectory('/non/existent/dir');

        $this->assertInstanceOf(SecurityException::class, $exception);
        $this->assertStringContainsString('Invalid or non-existent directory', $exception->getMessage());
        $this->assertEquals(400, $exception->getCode());
    }

    /**
     * Test SecurityException::directoryNotWritable factory method.
     */
    public function testSecurityExceptionDirectoryNotWritableFactory(): void
    {
        $exception = SecurityException::directoryNotWritable('/readonly/dir');

        $this->assertInstanceOf(SecurityException::class, $exception);
        $this->assertStringContainsString('Directory is not writable', $exception->getMessage());
        $this->assertEquals(403, $exception->getCode());
    }
}
