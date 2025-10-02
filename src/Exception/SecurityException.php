<?php

declare(strict_types=1);

namespace Swotto\Exception;

/**
 * SecurityException.
 *
 * Thrown when security violations are detected, such as:
 * - Path traversal attempts
 * - Invalid filename characters
 * - Access to restricted paths
 * - Content-type spoofing attempts
 */
class SecurityException extends SwottoException
{
    /**
     * Create exception for path traversal attempt.
     *
     * @param string $path The invalid path that was attempted
     * @return self
     */
    public static function pathTraversalDetected(string $path): self
    {
        return new self(
            sprintf('Path traversal attempt detected: %s', $path),
            [],
            403
        );
    }

    /**
     * Create exception for invalid filename characters.
     *
     * @param string $filename The filename with invalid characters
     * @return self
     */
    public static function invalidFilename(string $filename): self
    {
        return new self(
            sprintf('Invalid filename characters detected: %s', $filename),
            [],
            400
        );
    }

    /**
     * Create exception for invalid directory.
     *
     * @param string $directory The invalid directory path
     * @return self
     */
    public static function invalidDirectory(string $directory): self
    {
        return new self(
            sprintf('Invalid or non-existent directory: %s', $directory),
            [],
            400
        );
    }

    /**
     * Create exception for non-writable directory.
     *
     * @param string $directory The non-writable directory path
     * @return self
     */
    public static function directoryNotWritable(string $directory): self
    {
        return new self(
            sprintf('Directory is not writable: %s', $directory),
            [],
            403
        );
    }
}
