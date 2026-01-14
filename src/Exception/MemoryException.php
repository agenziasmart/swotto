<?php

declare(strict_types=1);

namespace Swotto\Exception;

/**
 * MemoryException.
 *
 * Thrown when memory-related limits are exceeded, such as:
 * - Response too large for in-memory processing
 * - Memory exhaustion during streaming
 * - Buffer overflow conditions
 */
class MemoryException extends SwottoException
{
    /**
     * Create exception for response too large for memory.
     *
     * @param int $actualSize The actual response size in bytes
     * @param int $maxSize The maximum allowed size in bytes
     * @return self
     */
    public static function responseTooLarge(int $actualSize, int $maxSize): self
    {
        return new self(
            sprintf(
                'Response too large for in-memory processing. '
                . 'Size: %s bytes, Maximum: %s bytes. Use saveToFile() instead.',
                number_format($actualSize),
                number_format($maxSize)
            ),
            ['actual_size' => $actualSize, 'max_size' => $maxSize],
            413 // Payload Too Large
        );
    }

    /**
     * Create exception for memory exhaustion during streaming.
     *
     * @param int $bytesRead Number of bytes read before exhaustion
     * @param int $memoryLimit The memory limit that was exceeded
     * @return self
     */
    public static function streamingMemoryExhausted(int $bytesRead, int $memoryLimit): self
    {
        return new self(
            sprintf(
                'Memory exhausted during streaming after reading %s bytes. Limit: %s bytes.',
                number_format($bytesRead),
                number_format($memoryLimit)
            ),
            ['bytes_read' => $bytesRead, 'memory_limit' => $memoryLimit],
            507 // Insufficient Storage
        );
    }

    /**
     * Create exception for buffer overflow.
     *
     * @param string $operation The operation that caused overflow
     * @param int $bufferSize The buffer size that was exceeded
     * @return self
     */
    public static function bufferOverflow(string $operation, int $bufferSize): self
    {
        return new self(
            sprintf('Buffer overflow during %s. Buffer size: %s bytes.', $operation, number_format($bufferSize)),
            ['operation' => $operation, 'buffer_size' => $bufferSize],
            500
        );
    }

    /**
     * Create exception for allocation failure.
     *
     * @param int $requestedBytes Number of bytes that couldn't be allocated
     * @return self
     */
    public static function allocationFailure(int $requestedBytes): self
    {
        return new self(
            sprintf('Failed to allocate %s bytes of memory.', number_format($requestedBytes)),
            ['requested_bytes' => $requestedBytes],
            500
        );
    }
}
