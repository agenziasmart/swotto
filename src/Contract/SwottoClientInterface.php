<?php

declare(strict_types=1);

namespace Swotto\Contract;

use Swotto\Response\SwottoResponse;

/**
 * Interface SwottoClientInterface.
 *
 * Defines the contract for Swotto API Client.
 * 12 methods: 5 HTTP verbs, 4 file upload, 2 advanced, 1 health.
 */
interface SwottoClientInterface
{
    /**
     * Send a GET request.
     *
     * @param string $uri The URI to request
     * @param array<string, mixed> $options Per-call options (merged with defaults). Supports:
     *   - bearer_token: (string) Override Authorization header
     *   - language: (string) Override Accept-Language header
     *   - session_id: (string) Override x-sid header
     *   - client_ip: (string) Set Client-Ip header
     *   - client_user_agent: (string) Set User-Agent header
     *   - query: (array) Query parameters
     *   - headers: (array) Additional headers
     * @return array<string, mixed> The response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function get(string $uri, array $options = []): array;

    /**
     * Send a POST request with auto-detection for JSON data.
     *
     * @param string $uri The URI to request
     * @param mixed $data Data to send (array for JSON auto-detection)
     * @param array<string, mixed> $options Per-call options (merged with defaults)
     * @return array<string, mixed> The response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function post(string $uri, mixed $data = [], array $options = []): array;

    /**
     * Send a PUT request with auto-detection for JSON data.
     *
     * @param string $uri The URI to request
     * @param mixed $data Data to send (array for JSON auto-detection)
     * @param array<string, mixed> $options Per-call options (merged with defaults)
     * @return array<string, mixed> The response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function put(string $uri, mixed $data = [], array $options = []): array;

    /**
     * Send a PATCH request with auto-detection for JSON data.
     *
     * @param string $uri The URI to request
     * @param mixed $data Data to send (array for JSON auto-detection)
     * @param array<string, mixed> $options Per-call options (merged with defaults)
     * @return array<string, mixed> The response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function patch(string $uri, mixed $data = [], array $options = []): array;

    /**
     * Send a DELETE request.
     *
     * @param string $uri The URI to request
     * @param array<string, mixed> $options Per-call options (merged with defaults)
     * @return array<string, mixed> The response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function delete(string $uri, array $options = []): array;

    /**
     * Upload a single file via POST with optional metadata.
     *
     * @param string $uri The URI to request
     * @param resource|\Psr\Http\Message\StreamInterface $fileResource File resource or PSR-7 stream
     * @param string $fieldName Field name for the file (default: 'file')
     * @param array<string, mixed> $metadata Additional form fields to send
     * @param array<string, mixed> $options Per-call options (merged with defaults)
     * @param string|null $filename Original filename
     * @param string|null $contentType File MIME type
     * @return array<string, mixed> The response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function postFile(
        string $uri,
        $fileResource,
        string $fieldName = 'file',
        array $metadata = [],
        array $options = [],
        ?string $filename = null,
        ?string $contentType = null,
    ): array;

    /**
     * Upload a single file via PUT with optional metadata.
     *
     * @param string $uri The URI to request
     * @param resource|\Psr\Http\Message\StreamInterface $fileResource File resource or PSR-7 stream
     * @param string $fieldName Field name for the file (default: 'file')
     * @param array<string, mixed> $metadata Additional form fields to send
     * @param array<string, mixed> $options Per-call options (merged with defaults)
     * @param string|null $filename Original filename
     * @param string|null $contentType File MIME type
     * @return array<string, mixed> The response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function putFile(
        string $uri,
        $fileResource,
        string $fieldName = 'file',
        array $metadata = [],
        array $options = [],
        ?string $filename = null,
        ?string $contentType = null,
    ): array;

    /**
     * Upload a single file via PATCH with optional metadata.
     *
     * @param string $uri The URI to request
     * @param resource|\Psr\Http\Message\StreamInterface $fileResource File resource or PSR-7 stream
     * @param string $fieldName Field name for the file (default: 'file')
     * @param array<string, mixed> $metadata Additional form fields to send
     * @param array<string, mixed> $options Per-call options (merged with defaults)
     * @param string|null $filename Original filename
     * @param string|null $contentType File MIME type
     * @return array<string, mixed> The response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function patchFile(
        string $uri,
        $fileResource,
        string $fieldName = 'file',
        array $metadata = [],
        array $options = [],
        ?string $filename = null,
        ?string $contentType = null,
    ): array;

    /**
     * Upload multiple files via POST with optional metadata.
     *
     * Files can be provided in two formats:
     * - Simple: ['fieldName' => $resource]
     * - Extended: ['fieldName' => ['contents' => $resource, 'filename' => '...', 'content_type' => '...']]
     *
     * @param string $uri The URI to request
     * @param array<string, mixed> $files Array of files
     * @param array<string, mixed> $metadata Additional form fields
     * @param array<string, mixed> $options Per-call options (merged with defaults)
     * @return array<string, mixed> The response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function postFiles(
        string $uri,
        array $files,
        array $metadata = [],
        array $options = [],
    ): array;

    /**
     * Get response as SwottoResponse object for advanced content handling.
     *
     * @param string $uri The URI to request
     * @param array<string, mixed> $options Per-call options (merged with defaults)
     * @return SwottoResponse Smart response wrapper
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function getResponse(string $uri, array $options = []): SwottoResponse;

    /**
     * Download content directly to file with security validation.
     *
     * @param string $uri The URI to request
     * @param string $filePath Destination file path
     * @param array<string, mixed> $options Per-call options (merged with defaults)
     * @return bool True on successful download
     *
     * @throws \Swotto\Exception\SecurityException If path validation fails
     * @throws \Swotto\Exception\FileOperationException If file operations fail
     * @throws \Swotto\Exception\SwottoException On other errors
     */
    public function downloadToFile(string $uri, string $filePath, array $options = []): bool;

    /**
     * Check if the connection to API is working.
     *
     * @return bool True if connection is working
     *
     * @throws \Swotto\Exception\SwottoException On error other than connection issues
     */
    public function checkConnection(): bool;
}
