<?php

declare(strict_types=1);

namespace Swotto\Response;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Swotto\Exception\FileOperationException;
use Swotto\Exception\MemoryException;
use Swotto\Exception\SecurityException;
use Swotto\Exception\StreamingException;

/**
 * SwottoResponse.
 *
 * Enterprise-grade response wrapper with security validations, memory management,
 * and content-type detection. Provides safe handling of various response formats
 * including JSON, CSV, PDF, and other binary content.
 */
final class SwottoResponse
{
    /**
     * Maximum size (in bytes) for in-memory processing.
     * Files larger than this must use streaming via saveToFile().
     */
    private const MAX_MEMORY_SIZE = 50 * 1024 * 1024; // 50MB

    /**
     * Threshold (in bytes) above which streaming is used even for in-memory operations.
     */
    private const STREAMING_THRESHOLD = 10 * 1024 * 1024; // 10MB

    /**
     * Chunk size for streaming operations.
     */
    private const CHUNK_SIZE = 8192; // 8KB

    /**
     * Cached parsed array data.
     */
    private ?array $cachedArray = null;

    /**
     * Cached string content.
     */
    private ?string $cachedString = null;

    /**
     * Detected content type (normalized).
     */
    private ?string $detectedContentType = null;

    public function __construct(
        private readonly ResponseInterface $response
    ) {
    }

    /**
     * Get response content as array (for JSON or CSV).
     *
     * @return array Parsed response data
     * @throws MemoryException If response is too large for memory
     * @throws StreamingException If JSON parsing fails
     */
    public function asArray(): array
    {
        if ($this->cachedArray !== null) {
            return $this->cachedArray;
        }

        $contentType = $this->getContentType();

        if (!$this->isJson() && !$this->isCsv()) {
            throw new \InvalidArgumentException(
                sprintf('Cannot parse content type "%s" as array', $contentType)
            );
        }

        $content = $this->asString();

        if ($this->isJson()) {
            $this->cachedArray = $this->parseJsonContent($content);
        } elseif ($this->isCsv()) {
            $this->cachedArray = $this->parseCsvContent($content);
        } else {
            $this->cachedArray = [];
        }

        return $this->cachedArray;
    }

    /**
     * Get response content as string with memory safety.
     *
     * @return string Response content as string
     * @throws MemoryException If response is too large for memory
     * @throws StreamingException If reading fails
     */
    public function asString(): string
    {
        if ($this->cachedString !== null) {
            return $this->cachedString;
        }

        $contentLength = $this->getContentLength();

        // Check memory limit
        if ($contentLength !== null && $contentLength > self::MAX_MEMORY_SIZE) {
            throw MemoryException::responseTooLarge($contentLength, self::MAX_MEMORY_SIZE);
        }

        // Use streaming for large responses
        if ($contentLength !== null && $contentLength > self::STREAMING_THRESHOLD) {
            $this->cachedString = $this->streamToString();
        } else {
            $stream = $this->response->getBody();
            // Rewind stream if possible to ensure we read from the beginning
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $this->cachedString = $stream->getContents();
        }

        return $this->cachedString;
    }

    /**
     * Save response content to file with security validations.
     *
     * @param string $path File path where to save the content
     * @return bool True on success
     * @throws SecurityException If path validation fails
     * @throws FileOperationException If file operations fail
     * @throws StreamingException If streaming fails
     */
    public function saveToFile(string $path): bool
    {
        $this->validatePath($path);

        $safePath = $this->buildSafePath($path);

        $handle = @fopen($safePath, 'wb');
        if ($handle === false) {
            throw FileOperationException::cannotOpenFile($safePath, 'wb');
        }

        try {
            $stream = $this->response->getBody();
            $bytesWritten = 0;

            while (!$stream->eof()) {
                $chunk = $stream->read(self::CHUNK_SIZE);
                if ($chunk === '') {
                    break;
                }

                $written = @fwrite($handle, $chunk);
                if ($written === false) {
                    throw StreamingException::writeFailure('fwrite returned false');
                }

                $bytesWritten += $written;
            }

            return true;
        } finally {
            @fclose($handle);
        }
    }

    /**
     * Get the raw stream interface for advanced operations.
     *
     * @return StreamInterface The raw PSR-7 stream
     */
    public function getStream(): StreamInterface
    {
        return $this->response->getBody();
    }

    /**
     * Check if response is JSON content.
     *
     * @return bool True if JSON content
     */
    public function isJson(): bool
    {
        return $this->normalizeContentType($this->getContentType()) === 'json';
    }

    /**
     * Check if response is CSV content.
     *
     * @return bool True if CSV content
     */
    public function isCsv(): bool
    {
        return $this->normalizeContentType($this->getContentType()) === 'csv';
    }

    /**
     * Check if response is PDF content.
     *
     * @return bool True if PDF content
     */
    public function isPdf(): bool
    {
        return $this->normalizeContentType($this->getContentType()) === 'pdf';
    }

    /**
     * Check if response is binary content.
     *
     * @return bool True if binary content
     */
    public function isBinary(): bool
    {
        $contentType = $this->normalizeContentType($this->getContentType());

        return in_array($contentType, ['pdf'], true) ||
               str_starts_with($contentType, 'image/') ||
               str_starts_with($contentType, 'video/') ||
               str_starts_with($contentType, 'audio/');
    }

    /**
     * Get the response content type.
     *
     * @return string Content type header value
     */
    public function getContentType(): string
    {
        return $this->response->getHeaderLine('Content-Type');
    }

    /**
     * Get response content length.
     *
     * @return int|null Content length in bytes, null if not available
     */
    public function getContentLength(): ?int
    {
        $contentLength = $this->response->getHeaderLine('Content-Length');

        return $contentLength !== '' ? (int) $contentLength : null;
    }

    /**
     * Get suggested filename from Content-Disposition header.
     *
     * @return string|null Suggested filename or null if not available
     */
    public function getFilename(): ?string
    {
        $disposition = $this->response->getHeaderLine('Content-Disposition');

        if (preg_match('/filename[^;=\n]*=(([\'"]).*?\2|[^;\n]*)/', $disposition, $matches)) {
            return trim($matches[1], '"\'');
        }

        return null;
    }

    /**
     * Get all response headers.
     *
     * @return array<string, string[]> Response headers
     */
    public function getHeaders(): array
    {
        return $this->response->getHeaders();
    }

    /**
     * Get response status code.
     *
     * @return int HTTP status code
     */
    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }

    /**
     * Validate file path for security.
     *
     * @param string $path File path to validate
     * @throws SecurityException If path is invalid or unsafe
     */
    private function validatePath(string $path): void
    {
        // Get directory and validate it exists
        $directory = dirname($path);
        $realDir = realpath($directory);

        if ($realDir === false || !is_dir($realDir)) {
            throw SecurityException::invalidDirectory($directory);
        }

        if (!is_writable($realDir)) {
            throw SecurityException::directoryNotWritable($realDir);
        }

        // Validate filename
        $filename = basename($path);

        // Check for path traversal
        if (str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            throw SecurityException::pathTraversalDetected($filename);
        }

        // Check for invalid characters (null bytes, control characters, etc.)
        if (preg_match('/[\x00-\x1f\x7f<>:"|?*]/', $filename)) {
            throw SecurityException::invalidFilename($filename);
        }

        // Prevent empty filename
        if (trim($filename) === '') {
            throw SecurityException::invalidFilename('Empty filename');
        }
    }

    /**
     * Build safe file path.
     *
     * @param string $path Original path
     * @return string Safe path
     */
    private function buildSafePath(string $path): string
    {
        $realDir = realpath(dirname($path));
        $filename = basename($path);

        return $realDir . DIRECTORY_SEPARATOR . $filename;
    }

    /**
     * Stream content to string with memory protection.
     *
     * @return string Content as string
     * @throws MemoryException If memory limit exceeded during streaming
     * @throws StreamingException If stream read fails
     */
    private function streamToString(): string
    {
        $stream = $this->response->getBody();
        $content = '';
        $totalRead = 0;

        while (!$stream->eof()) {
            $chunk = $stream->read(self::CHUNK_SIZE);
            if ($chunk === '') {
                break;
            }

            $totalRead += strlen($chunk);

            if ($totalRead > self::MAX_MEMORY_SIZE) {
                throw MemoryException::streamingMemoryExhausted($totalRead, self::MAX_MEMORY_SIZE);
            }

            $content .= $chunk;
        }

        return $content;
    }

    /**
     * Parse JSON content safely.
     *
     * @param string $content JSON string
     * @return array Parsed JSON data
     * @throws StreamingException If JSON parsing fails
     */
    private function parseJsonContent(string $content): array
    {
        if (trim($content) === '') {
            return [];
        }

        $decoded = json_decode($content, true);
        $error = json_last_error();

        if ($error !== JSON_ERROR_NONE) {
            throw new StreamingException(
                sprintf('Invalid JSON response: %s', json_last_error_msg()),
                ['json_error_code' => $error],
                400
            );
        }

        return $decoded ?? [];
    }

    /**
     * Parse CSV content to array.
     *
     * @param string $content CSV string
     * @return array Parsed CSV data
     */
    private function parseCsvContent(string $content): array
    {
        $lines = explode("\n", trim($content));
        $firstLine = array_shift($lines);
        if ($firstLine === '') {
            return [];
        }

        $headers = str_getcsv($firstLine);
        // str_getcsv always returns non-empty array in PHP 8.0+
        // Check if result contains only empty strings (invalid CSV header)
        $nonEmptyHeaders = array_filter($headers, fn ($h) => $h !== null && $h !== '');
        if ($nonEmptyHeaders === []) {
            return [];
        }

        $data = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $row = str_getcsv($line);

            // Pad or trim row to match headers count
            $row = array_pad($row, count($headers), '');
            $row = array_slice($row, 0, count($headers));

            // Ensure we have valid keys for array_combine
            $safeHeaders = array_map(fn ($h) => (string) ($h ?? ''), $headers);
            $combinedRow = array_combine($safeHeaders, $row);
            if ($combinedRow !== false) {
                $data[] = $combinedRow;
            }
        }

        return $data;
    }

    /**
     * Normalize content type for consistent detection.
     *
     * @param string $contentType Raw content type
     * @return string Normalized content type
     */
    private function normalizeContentType(string $contentType): string
    {
        if ($this->detectedContentType !== null) {
            return $this->detectedContentType;
        }

        // Remove charset and other parameters
        $parts = explode(';', strtolower($contentType));
        $mainType = trim($parts[0]);

        // Normalize common variations
        $typeMap = [
            'application/json' => 'json',
            'text/json' => 'json',
            'application/x-json' => 'json',
            'text/csv' => 'csv',
            'application/csv' => 'csv',
            'text/comma-separated-values' => 'csv',
            'application/pdf' => 'pdf',
            'application/x-pdf' => 'pdf',
        ];

        $this->detectedContentType = $typeMap[$mainType] ?? $mainType;

        return $this->detectedContentType;
    }
}
