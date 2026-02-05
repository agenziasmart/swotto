<?php

declare(strict_types=1);

namespace Swotto\Tests;

use PHPUnit\Framework\TestCase;
use Swotto\Exception\FileOperationException;
use Swotto\Exception\MemoryException;
use Swotto\Exception\SecurityException;
use Swotto\Exception\StreamingException;
use Swotto\Exception\SwottoException;

/**
 * ExceptionFactoryTest.
 *
 * Test factory methods for various exception classes.
 */
class ExceptionFactoryTest extends TestCase
{
    // ========== FileOperationException Factory Methods ==========

    public function testFileOperationExceptionCannotOpenFile(): void
    {
        $exception = FileOperationException::cannotOpenFile('/path/to/file.txt', 'wb');

        $this->assertInstanceOf(FileOperationException::class, $exception);
        $this->assertInstanceOf(SwottoException::class, $exception);
        $this->assertStringContainsString('Cannot open file', $exception->getMessage());
        $this->assertStringContainsString('/path/to/file.txt', $exception->getMessage());
        $this->assertStringContainsString('writing', $exception->getMessage());
        $this->assertEquals(500, $exception->getCode());

        $errorData = $exception->getErrorData();
        $this->assertEquals('/path/to/file.txt', $errorData['path']);
        $this->assertEquals('wb', $errorData['mode']);
    }

    public function testFileOperationExceptionCannotOpenFileForReading(): void
    {
        $exception = FileOperationException::cannotOpenFile('/path/to/file.txt', 'rb');

        $this->assertStringContainsString('reading', $exception->getMessage());
        $this->assertEquals('rb', $exception->getErrorData()['mode']);
    }

    public function testFileOperationExceptionWriteFailure(): void
    {
        $exception = FileOperationException::writeFailure('/path/to/file.txt', 1024);

        $this->assertInstanceOf(FileOperationException::class, $exception);
        $this->assertStringContainsString('Failed to write', $exception->getMessage());
        $this->assertEquals(500, $exception->getCode());

        $errorData = $exception->getErrorData();
        $this->assertEquals('/path/to/file.txt', $errorData['path']);
        $this->assertEquals(1024, $errorData['bytes_attempted']);
    }

    public function testFileOperationExceptionDiskSpaceExhausted(): void
    {
        $exception = FileOperationException::diskSpaceExhausted('/path/to/file.txt');

        $this->assertInstanceOf(FileOperationException::class, $exception);
        $this->assertStringContainsString('Disk space exhausted', $exception->getMessage());
        $this->assertEquals(507, $exception->getCode()); // Insufficient Storage

        $errorData = $exception->getErrorData();
        $this->assertEquals('/path/to/file.txt', $errorData['path']);
    }

    public function testFileOperationExceptionFileSystemError(): void
    {
        $exception = FileOperationException::fileSystemError('copy', '/source/file.txt', 'Permission denied');

        $this->assertInstanceOf(FileOperationException::class, $exception);
        $this->assertStringContainsString('File system error', $exception->getMessage());
        $this->assertStringContainsString('copy', $exception->getMessage());
        $this->assertStringContainsString('Permission denied', $exception->getMessage());
        $this->assertEquals(500, $exception->getCode());

        $errorData = $exception->getErrorData();
        $this->assertEquals('copy', $errorData['operation']);
        $this->assertEquals('/source/file.txt', $errorData['path']);
        $this->assertEquals('Permission denied', $errorData['reason']);
    }

    public function testFileOperationExceptionFileSystemErrorWithoutReason(): void
    {
        $exception = FileOperationException::fileSystemError('delete', '/file.txt');

        $this->assertStringContainsString('File system error', $exception->getMessage());
        $this->assertStringNotContainsString('()', $exception->getMessage());
        $this->assertEquals('', $exception->getErrorData()['reason']);
    }

    // ========== MemoryException Factory Methods ==========

    public function testMemoryExceptionResponseTooLarge(): void
    {
        $exception = MemoryException::responseTooLarge(60000000, 50000000);

        $this->assertInstanceOf(MemoryException::class, $exception);
        $this->assertInstanceOf(SwottoException::class, $exception);
        $this->assertStringContainsString('Response too large', $exception->getMessage());
        $this->assertStringContainsString('60,000,000', $exception->getMessage());
        $this->assertStringContainsString('50,000,000', $exception->getMessage());
        $this->assertStringContainsString('saveToFile()', $exception->getMessage());
        $this->assertEquals(413, $exception->getCode()); // Payload Too Large

        $errorData = $exception->getErrorData();
        $this->assertEquals(60000000, $errorData['actual_size']);
        $this->assertEquals(50000000, $errorData['max_size']);
    }

    public function testMemoryExceptionStreamingMemoryExhausted(): void
    {
        $exception = MemoryException::streamingMemoryExhausted(45000000, 50000000);

        $this->assertInstanceOf(MemoryException::class, $exception);
        $this->assertStringContainsString('Memory exhausted', $exception->getMessage());
        $this->assertStringContainsString('45,000,000', $exception->getMessage());
        $this->assertEquals(507, $exception->getCode()); // Insufficient Storage

        $errorData = $exception->getErrorData();
        $this->assertEquals(45000000, $errorData['bytes_read']);
        $this->assertEquals(50000000, $errorData['memory_limit']);
    }

    public function testMemoryExceptionBufferOverflow(): void
    {
        $exception = MemoryException::bufferOverflow('decompression', 65536);

        $this->assertInstanceOf(MemoryException::class, $exception);
        $this->assertStringContainsString('Buffer overflow', $exception->getMessage());
        $this->assertStringContainsString('decompression', $exception->getMessage());
        $this->assertEquals(500, $exception->getCode());

        $errorData = $exception->getErrorData();
        $this->assertEquals('decompression', $errorData['operation']);
        $this->assertEquals(65536, $errorData['buffer_size']);
    }

    public function testMemoryExceptionAllocationFailure(): void
    {
        $exception = MemoryException::allocationFailure(1073741824); // 1GB

        $this->assertInstanceOf(MemoryException::class, $exception);
        $this->assertStringContainsString('Failed to allocate', $exception->getMessage());
        $this->assertStringContainsString('1,073,741,824', $exception->getMessage());
        $this->assertEquals(500, $exception->getCode());

        $errorData = $exception->getErrorData();
        $this->assertEquals(1073741824, $errorData['requested_bytes']);
    }

    // ========== StreamingException Factory Methods ==========

    public function testStreamingExceptionReadFailure(): void
    {
        $exception = StreamingException::readFailure('Connection reset', 1024);

        $this->assertInstanceOf(StreamingException::class, $exception);
        $this->assertInstanceOf(SwottoException::class, $exception);
        $this->assertStringContainsString('Failed to read from stream', $exception->getMessage());
        $this->assertStringContainsString('Connection reset', $exception->getMessage());
        $this->assertEquals(500, $exception->getCode());

        $errorData = $exception->getErrorData();
        $this->assertEquals('Connection reset', $errorData['reason']);
        $this->assertEquals(1024, $errorData['bytes_read']);
    }

    public function testStreamingExceptionReadFailureWithoutReason(): void
    {
        $exception = StreamingException::readFailure();

        $this->assertStringContainsString('Failed to read from stream', $exception->getMessage());
        $this->assertStringNotContainsString(':', $exception->getMessage());
    }

    public function testStreamingExceptionWriteFailure(): void
    {
        $exception = StreamingException::writeFailure('Disk full', 2048);

        $this->assertInstanceOf(StreamingException::class, $exception);
        $this->assertStringContainsString('Failed to write to stream', $exception->getMessage());
        $this->assertStringContainsString('Disk full', $exception->getMessage());
        $this->assertEquals(500, $exception->getCode());

        $errorData = $exception->getErrorData();
        $this->assertEquals('Disk full', $errorData['reason']);
        $this->assertEquals(2048, $errorData['bytes_written']);
    }

    public function testStreamingExceptionUnexpectedEndOfStream(): void
    {
        $exception = StreamingException::unexpectedEndOfStream(10000, 5000);

        $this->assertInstanceOf(StreamingException::class, $exception);
        $this->assertStringContainsString('Unexpected end of stream', $exception->getMessage());
        $this->assertStringContainsString('10,000', $exception->getMessage());
        $this->assertStringContainsString('5,000', $exception->getMessage());
        $this->assertEquals(400, $exception->getCode());

        $errorData = $exception->getErrorData();
        $this->assertEquals(10000, $errorData['expected_bytes']);
        $this->assertEquals(5000, $errorData['actual_bytes']);
    }

    public function testStreamingExceptionStreamCorruption(): void
    {
        $exception = StreamingException::streamCorruption('Invalid checksum', 4096);

        $this->assertInstanceOf(StreamingException::class, $exception);
        $this->assertStringContainsString('Stream corruption detected', $exception->getMessage());
        $this->assertStringContainsString('Invalid checksum', $exception->getMessage());
        $this->assertStringContainsString('position 4096', $exception->getMessage());
        $this->assertEquals(400, $exception->getCode());

        $errorData = $exception->getErrorData();
        $this->assertEquals('Invalid checksum', $errorData['corruption']);
        $this->assertEquals(4096, $errorData['position']);
    }

    public function testStreamingExceptionStreamCorruptionWithoutPosition(): void
    {
        $exception = StreamingException::streamCorruption('Data mismatch', 0);

        $this->assertStringContainsString('Data mismatch', $exception->getMessage());
        $this->assertStringNotContainsString('position', $exception->getMessage());
    }

    public function testStreamingExceptionStreamTimeout(): void
    {
        $exception = StreamingException::streamTimeout(30);

        $this->assertInstanceOf(StreamingException::class, $exception);
        $this->assertStringContainsString('Stream operation timed out', $exception->getMessage());
        $this->assertStringContainsString('30 seconds', $exception->getMessage());
        $this->assertEquals(408, $exception->getCode()); // Request Timeout

        $errorData = $exception->getErrorData();
        $this->assertEquals(30, $errorData['timeout_seconds']);
    }

    // ========== SecurityException Factory Methods ==========

    public function testSecurityExceptionPathTraversalDetected(): void
    {
        $exception = SecurityException::pathTraversalDetected('../../../etc/passwd');

        $this->assertInstanceOf(SecurityException::class, $exception);
        $this->assertInstanceOf(SwottoException::class, $exception);
        $this->assertStringContainsString('Path traversal', $exception->getMessage());
        $this->assertStringContainsString('../../../etc/passwd', $exception->getMessage());
        $this->assertEquals(403, $exception->getCode());
    }

    public function testSecurityExceptionInvalidFilename(): void
    {
        $exception = SecurityException::invalidFilename('file<script>.txt');

        $this->assertInstanceOf(SecurityException::class, $exception);
        $this->assertStringContainsString('Invalid filename', $exception->getMessage());
        $this->assertEquals(400, $exception->getCode());
    }

    public function testSecurityExceptionInvalidDirectory(): void
    {
        $exception = SecurityException::invalidDirectory('/non/existent/path');

        $this->assertInstanceOf(SecurityException::class, $exception);
        $this->assertStringContainsString('Invalid or non-existent directory', $exception->getMessage());
        $this->assertEquals(400, $exception->getCode());
    }

    public function testSecurityExceptionDirectoryNotWritable(): void
    {
        $exception = SecurityException::directoryNotWritable('/readonly/dir');

        $this->assertInstanceOf(SecurityException::class, $exception);
        $this->assertStringContainsString('Directory is not writable', $exception->getMessage());
        $this->assertEquals(403, $exception->getCode());
    }

    // ========== Exception Hierarchy Tests ==========

    public function testAllExceptionsExtendSwottoException(): void
    {
        $exceptions = [
            new FileOperationException('test'),
            new MemoryException('test'),
            new StreamingException('test'),
            new SecurityException('test'),
        ];

        foreach ($exceptions as $exception) {
            $this->assertInstanceOf(
                SwottoException::class,
                $exception,
                get_class($exception) . ' should extend SwottoException'
            );
        }
    }

    public function testAllExceptionsAreThrowable(): void
    {
        $exceptions = [
            FileOperationException::cannotOpenFile('/test'),
            MemoryException::responseTooLarge(100, 50),
            StreamingException::readFailure('test'),
            SecurityException::pathTraversalDetected('../'),
        ];

        foreach ($exceptions as $exception) {
            $this->assertInstanceOf(
                \Throwable::class,
                $exception,
                get_class($exception) . ' should be Throwable'
            );
        }
    }
}
