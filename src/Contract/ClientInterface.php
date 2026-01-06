<?php

declare(strict_types=1);

namespace Swotto\Contract;

/**
 * Interface ClientInterface.
 *
 * Defines the contract for Swotto API Client
 */
interface ClientInterface
{
    /**
     * Send a GET request.
     *
     * @param string $uri The URI to request
     * @param array $options Request options to apply
     * @return array The response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function get(string $uri, array $options = []): array;

    /**
     * Send a POST request with auto-detection for JSON data.
     *
     * @param string $uri The URI to request
     * @param mixed $data Data to send (array for JSON auto-detection, or resource for file)
     * @param array $options Request options to apply (optional)
     * @return array The response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function post(string $uri, mixed $data = [], array $options = []): array;

    /**
     * Send a PATCH request with auto-detection for JSON data.
     *
     * @param string $uri The URI to request
     * @param mixed $data Data to send (array for JSON auto-detection, or resource for file)
     * @param array $options Request options to apply (optional)
     * @return array The response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function patch(string $uri, mixed $data = [], array $options = []): array;

    /**
     * Send a PUT request with auto-detection for JSON data.
     *
     * @param string $uri The URI to request
     * @param mixed $data Data to send (array for JSON auto-detection, or resource for file)
     * @param array $options Request options to apply (optional)
     * @return array The response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function put(string $uri, mixed $data = [], array $options = []): array;

    /**
     * Send a DELETE request.
     *
     * @param string $uri The URI to request
     * @param array $options Request options to apply
     * @return array The response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function delete(string $uri, array $options = []): array;

    /**
     * Check if the connection to API is working.
     *
     * @return bool True if connection is working
     *
     * @throws \Swotto\Exception\SwottoException On error other than connection issues
     */
    public function checkConnection(): bool;

    /**
     * Check if authentication is working.
     *
     * @param array|null $options Request options to apply
     * @return array Authentication status data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function checkAuth(?array $options = null): array;

    /**
     * Check session status.
     *
     * @param array|null $options Request options to apply
     * @return array Session data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function checkSession(?array $options = null): array;

    /**
     * Set session ID for future requests.
     *
     * @param string $sessionId The session ID
     * @return void
     */
    public function setSessionId(string $sessionId): void;

    /**
     * Set language for future requests.
     *
     * @param string $language The language code
     * @return void
     */
    public function setLanguage(string $language): void;

    /**
     * Set accept header for future requests.
     *
     * @param string $accept The accept header value
     * @return void
     */
    public function setAccept(string $accept): void;

    /**
     * Fetch data from a POP endpoint.
     *
     * @param string $uri The POP URI
     * @param array|null $query Query parameters
     * @return array The retrieved data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function fetchPop(string $uri, ?array $query = []): array;

    /**
     * Send a GET request and parse the response.
     *
     * @param string $uri The URI to request
     * @param array $options Request options to apply
     * @return array The parsed response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function getParsed(string $uri, array $options = []): array;

    /**
     * Send a POST request and parse the response.
     *
     * @param string $uri The URI to request
     * @param array $options Request options to apply
     * @return array The parsed response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function postParsed(string $uri, array $options = []): array;

    /**
     * Send a PATCH request and parse the response.
     *
     * @param string $uri The URI to request
     * @param array $options Request options to apply
     * @return array The parsed response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function patchParsed(string $uri, array $options = []): array;

    /**
     * Send a PUT request and parse the response.
     *
     * @param string $uri The URI to request
     * @param array $options Request options to apply
     * @return array The parsed response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function putParsed(string $uri, array $options = []): array;

    /**
     * Send a DELETE request and parse the response.
     *
     * @param string $uri The URI to request
     * @param array $options Request options to apply
     * @return array The parsed response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function deleteParsed(string $uri, array $options = []): array;

    /**
     * Set access token for future requests.
     *
     * @param string $token The access token (Bearer token)
     * @return void
     */
    public function setAccessToken(string $token): void;

    /**
     * Clear access token.
     *
     * @return void
     */
    public function clearAccessToken(): void;

    /**
     * Get current access token.
     *
     * @return string|null The current access token or null if not set
     */
    public function getAccessToken(): ?string;

    /**
     * Upload a single file with optional metadata.
     *
     * @param string $uri The URI to request
     * @param resource|\Psr\Http\Message\StreamInterface $fileResource File resource or PSR-7 stream
     * @param string $fieldName Field name for the file (default: 'file')
     * @param array $metadata Additional form fields to send
     * @param array $options Additional request options
     * @return array The response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function postFile(
        string $uri,
        $fileResource,
        string $fieldName = 'file',
        array $metadata = [],
        array $options = []
    ): array;

    /**
     * Upload multiple files with optional metadata.
     *
     * @param string $uri The URI to request
     * @param array $files Array of file resources, keyed by field name
     * @param array $metadata Additional form fields to send
     * @param array $options Additional request options
     * @return array The response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function postFiles(
        string $uri,
        array $files,
        array $metadata = [],
        array $options = []
    ): array;

    /**
     * Upload a single file via PUT with optional metadata.
     *
     * @param string $uri The URI to request
     * @param resource|\Psr\Http\Message\StreamInterface $fileResource File resource or PSR-7 stream
     * @param string $fieldName Field name for the file (default: 'file')
     * @param array $metadata Additional form fields to send
     * @param array $options Additional request options
     * @return array The response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function putFile(
        string $uri,
        $fileResource,
        string $fieldName = 'file',
        array $metadata = [],
        array $options = []
    ): array;

    /**
     * Upload a single file via PATCH with optional metadata.
     *
     * @param string $uri The URI to request
     * @param resource|\Psr\Http\Message\StreamInterface $fileResource File resource or PSR-7 stream
     * @param string $fieldName Field name for the file (default: 'file')
     * @param array $metadata Additional form fields to send
     * @param array $options Additional request options
     * @return array The response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function patchFile(
        string $uri,
        $fileResource,
        string $fieldName = 'file',
        array $metadata = [],
        array $options = []
    ): array;

    /**
     * Set client original user agent for X-Client-User-Agent header.
     *
     * @param string $userAgent Original client user agent
     * @return void
     */
    public function setClientUserAgent(string $userAgent): void;

    /**
     * Set client original IP for X-Client-Ip header.
     *
     * @param string $ip Original client IP
     * @return void
     */
    public function setClientIp(string $ip): void;

    /**
     * Get response as SwottoResponse object for advanced content handling.
     *
     * @param string $uri The URI to request
     * @param array $options Request options to apply
     * @return \Swotto\Response\SwottoResponse Smart response wrapper
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function getResponse(string $uri, array $options = []): \Swotto\Response\SwottoResponse;

    /**
     * Download content directly to file with security validation.
     *
     * @param string $uri The URI to request
     * @param string $filePath Destination file path
     * @param array $options Request options to apply
     * @return bool True on successful download
     *
     * @throws \Swotto\Exception\SecurityException If path validation fails
     * @throws \Swotto\Exception\FileOperationException If file operations fail
     * @throws \Swotto\Exception\SwottoException On other errors
     */
    public function downloadToFile(string $uri, string $filePath, array $options = []): bool;
}
