<?php

declare(strict_types=1);

namespace Swotto\Exception;

/**
 * StreamingException.
 *
 * Thrown when streaming operations fail, such as:
 * - Stream read failures
 * - Stream write failures
 * - Stream corruption
 * - Unexpected end of stream
 */
class StreamingException extends SwottoException
{
    /**
     * Create exception for stream read failure.
     *
     * @param string $reason Additional reason for the failure
     * @param int $bytesRead Number of bytes successfully read before failure
     * @return self
     */
    public static function readFailure(string $reason = '', int $bytesRead = 0): self
    {
        $message = 'Failed to read from stream';
        if (!empty($reason)) {
            $message .= sprintf(': %s', $reason);
        }

        return new self(
            $message,
            ['reason' => $reason, 'bytes_read' => $bytesRead],
            500
        );
    }

    /**
     * Create exception for stream write failure.
     *
     * @param string $reason Additional reason for the failure
     * @param int $bytesWritten Number of bytes successfully written before failure
     * @return self
     */
    public static function writeFailure(string $reason = '', int $bytesWritten = 0): self
    {
        $message = 'Failed to write to stream';
        if (!empty($reason)) {
            $message .= sprintf(': %s', $reason);
        }

        return new self(
            $message,
            ['reason' => $reason, 'bytes_written' => $bytesWritten],
            500
        );
    }

    /**
     * Create exception for unexpected end of stream.
     *
     * @param int $expectedBytes Number of bytes that were expected
     * @param int $actualBytes Number of bytes actually received
     * @return self
     */
    public static function unexpectedEndOfStream(int $expectedBytes, int $actualBytes): self
    {
        return new self(
            sprintf(
                'Unexpected end of stream. Expected %s bytes, got %s bytes.',
                number_format($expectedBytes),
                number_format($actualBytes)
            ),
            ['expected_bytes' => $expectedBytes, 'actual_bytes' => $actualBytes],
            400
        );
    }

    /**
     * Create exception for stream corruption.
     *
     * @param string $corruption Description of the corruption detected
     * @param int $position Position where corruption was detected
     * @return self
     */
    public static function streamCorruption(string $corruption, int $position = 0): self
    {
        $message = sprintf('Stream corruption detected: %s', $corruption);
        if ($position > 0) {
            $message .= sprintf(' at position %d', $position);
        }

        return new self(
            $message,
            ['corruption' => $corruption, 'position' => $position],
            400
        );
    }

    /**
     * Create exception for stream timeout.
     *
     * @param int $timeoutSeconds The timeout that was exceeded
     * @return self
     */
    public static function streamTimeout(int $timeoutSeconds): self
    {
        return new self(
            sprintf('Stream operation timed out after %d seconds.', $timeoutSeconds),
            ['timeout_seconds' => $timeoutSeconds],
            408 // Request Timeout
        );
    }
}
