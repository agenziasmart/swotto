<?php

declare(strict_types=1);

namespace Swotto\Exception;

/**
 * FileOperationException.
 *
 * Thrown when file I/O operations fail, such as:
 * - Unable to open file for writing
 * - Disk space exhausted
 * - Permission denied for file operations
 * - File system errors
 */
class FileOperationException extends SwottoException
{
    /**
     * Create exception for file open failure.
     *
     * @param string $path The file path that failed to open
     * @param string $mode The file mode (e.g., 'wb', 'rb')
     * @return self
     */
    public static function cannotOpenFile(string $path, string $mode = 'wb'): self
    {
        return new self(
            sprintf('Cannot open file for %s: %s', $mode === 'wb' ? 'writing' : 'reading', $path),
            ['path' => $path, 'mode' => $mode],
            500
        );
    }

    /**
     * Create exception for file write failure.
     *
     * @param string $path The file path where write failed
     * @param int $bytesAttempted Number of bytes attempted to write
     * @return self
     */
    public static function writeFailure(string $path, int $bytesAttempted = 0): self
    {
        return new self(
            sprintf('Failed to write to file: %s', $path),
            ['path' => $path, 'bytes_attempted' => $bytesAttempted],
            500
        );
    }

    /**
     * Create exception for disk space issues.
     *
     * @param string $path The file path where space ran out
     * @return self
     */
    public static function diskSpaceExhausted(string $path): self
    {
        return new self(
            sprintf('Disk space exhausted while writing to: %s', $path),
            ['path' => $path],
            507 // Insufficient Storage
        );
    }

    /**
     * Create exception for general file system errors.
     *
     * @param string $operation The operation that failed
     * @param string $path The file path involved
     * @param string $reason Additional reason information
     * @return self
     */
    public static function fileSystemError(string $operation, string $path, string $reason = ''): self
    {
        $message = sprintf('File system error during %s: %s', $operation, $path);
        if (!empty($reason)) {
            $message .= sprintf(' (%s)', $reason);
        }

        return new self(
            $message,
            ['operation' => $operation, 'path' => $path, 'reason' => $reason],
            500
        );
    }
}
