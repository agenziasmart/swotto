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
     * Chunk size for streaming operations.
     */
    private const CHUNK_SIZE = 8192; // 8KB

    /**
     * Exact content types treated as binary, beyond the image/video/audio/font families.
     *
     * @var array<int, string>
     */
    private const BINARY_CONTENT_TYPES = [
        'application/octet-stream',
        'application/zip',
        'application/x-zip-compressed',
        'application/gzip',
        'application/x-tar',
        'application/x-7z-compressed',
        'application/x-rar-compressed',
        'application/msword',
        'application/vnd.ms-excel',
        'application/vnd.ms-powerpoint',
        'application/rtf',
    ];

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

        // Content-Length, when present, lets us reject an oversized response before
        // reading a single byte. It is an optimisation, never the only safeguard:
        // chunked responses and proxies that drop the header would slip past it.
        if ($contentLength !== null && $contentLength > self::MAX_MEMORY_SIZE) {
            throw MemoryException::responseTooLarge($contentLength, self::MAX_MEMORY_SIZE);
        }

        // Always read in chunks so the limit applies to the bytes actually received.
        $this->cachedString = $this->streamToString();

        return $this->cachedString;
    }

    /**
     * Save response content to file with security validations.
     *
     * The stream is rewound when seekable, so saving after `asString()` or `asArray()`
     * still writes the full content. A non-seekable stream that has already been consumed
     * cannot be saved: the method fails explicitly rather than producing an empty file.
     *
     * On failure the partial file is removed, so a caller never finds a truncated
     * artifact left behind by a call that threw.
     *
     * @param string $path File path where to save the content
     * @return bool True on success
     * @throws SecurityException If path validation fails
     * @throws FileOperationException If file operations fail
     * @throws StreamingException If the stream was consumed or the content is truncated
     */
    public function saveToFile(string $path): bool
    {
        $this->validatePath($path);

        $safePath = $this->buildSafePath($path);
        $stream = $this->response->getBody();

        if ($stream->isSeekable()) {
            $stream->rewind();
        } elseif ($stream->eof()) {
            throw StreamingException::readFailure(
                'the response stream is not seekable and has already been consumed; '
                . 'call saveToFile() before reading the response'
            );
        }

        $handle = @fopen($safePath, 'wb');
        if ($handle === false) {
            throw FileOperationException::cannotOpenFile($safePath, 'wb');
        }

        try {
            $bytesWritten = 0;

            while (!$stream->eof()) {
                $chunk = $stream->read(self::CHUNK_SIZE);
                if ($chunk === '') {
                    break;
                }

                $bytesWritten += $this->writeChunk($handle, $chunk);
            }

            $expectedLength = $this->getContentLength();
            if ($expectedLength !== null && $expectedLength !== $bytesWritten) {
                throw StreamingException::unexpectedEndOfStream($expectedLength, $bytesWritten);
            }
        } catch (\Throwable $exception) {
            fclose($handle);
            @unlink($safePath);

            throw $exception;
        }

        fclose($handle);

        return true;
    }

    /**
     * Write a full chunk to the file handle, looping over partial writes.
     *
     * `fwrite()` may write fewer bytes than requested without failing; returning early
     * on the first call would silently drop the remainder of the chunk.
     *
     * @param resource $handle Open file handle
     * @param string $chunk Chunk to write
     * @return int Bytes written (always the full chunk length)
     * @throws StreamingException If the handle stops accepting bytes
     */
    private function writeChunk($handle, string $chunk): int
    {
        $length = strlen($chunk);
        $written = 0;

        while ($written < $length) {
            $result = @fwrite($handle, substr($chunk, $written));

            if ($result === false || $result === 0) {
                throw StreamingException::writeFailure(
                    'fwrite stopped accepting data before the chunk was complete',
                    $written
                );
            }

            $written += $result;
        }

        return $written;
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

        if (in_array($contentType, ['pdf'], true)) {
            return true;
        }

        foreach (['image/', 'video/', 'audio/', 'font/'] as $prefix) {
            if (str_starts_with($contentType, $prefix)) {
                return true;
            }
        }

        // The generic binary type plus the archive and Office families an ERP actually
        // exports: without these, a spreadsheet download was reported as non-binary.
        return in_array($contentType, self::BINARY_CONTENT_TYPES, true)
            || str_starts_with($contentType, 'application/vnd.openxmlformats-officedocument.')
            || str_starts_with($contentType, 'application/vnd.oasis.opendocument.');
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
     * A missing, non-numeric or negative header yields null: an unusable value must not
     * be silently coerced to 0, which downstream checks would read as a real length.
     *
     * @return int|null Content length in bytes, null if not available or not usable
     */
    public function getContentLength(): ?int
    {
        $contentLength = trim($this->response->getHeaderLine('Content-Length'));

        if ($contentLength === '' || preg_match('/^\d+$/', $contentLength) !== 1) {
            return null;
        }

        return (int) $contentLength;
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

        // Rewind when possible so a previous read does not silently yield an empty string.
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

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

        if ($decoded === null) {
            return [];
        }

        // Syntactically valid but scalar JSON (`42`, `"text"`, `true`) cannot satisfy the
        // array contract. Raising a domain exception beats letting a TypeError escape.
        if (!is_array($decoded)) {
            throw new StreamingException(
                sprintf('Expected a JSON object or array, %s given', get_debug_type($decoded)),
                ['decoded_type' => get_debug_type($decoded)],
                400
            );
        }

        return $decoded;
    }

    /**
     * Parse CSV content to array.
     *
     * @param string $content CSV string
     * @return array Parsed CSV data
     */
    private function parseCsvContent(string $content): array
    {
        if (trim($content) === '') {
            return [];
        }

        // Splitting on "\n" before parsing would tear apart a quoted field containing a
        // line break — valid CSV that an ERP export produces routinely. fgetcsv understands
        // quoting, so the record boundaries are found by the parser, not by us.
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return [];
        }

        try {
            fwrite($handle, $content);
            rewind($handle);

            $headers = fgetcsv($handle, 0, ',', '"', '\\');
            if (!is_array($headers)) {
                return [];
            }

            $safeHeaders = array_map(static fn ($h) => (string) ($h ?? ''), $headers);
            if (array_filter($safeHeaders, static fn (string $h): bool => $h !== '') === []) {
                return [];
            }

            $columnCount = count($safeHeaders);
            $data = [];

            while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                if ($row === [null]) {
                    // A blank line yields [null]; skip it rather than emit an empty record.
                    continue;
                }

                $row = array_map(static fn ($value) => (string) ($value ?? ''), $row);
                $row = array_slice(array_pad($row, $columnCount, ''), 0, $columnCount);

                $data[] = array_combine($safeHeaders, $row);
            }

            return $data;
        } finally {
            fclose($handle);
        }
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
