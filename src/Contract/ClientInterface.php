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
     * Send a POST request.
     *
     * @param string $uri The URI to request
     * @param array $options Request options to apply
     * @return array The response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function post(string $uri, array $options = []): array;

    /**
     * Send a PATCH request.
     *
     * @param string $uri The URI to request
     * @param array $options Request options to apply
     * @return array The response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function patch(string $uri, array $options = []): array;

    /**
     * Send a PUT request.
     *
     * @param string $uri The URI to request
     * @param array $options Request options to apply
     * @return array The response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function put(string $uri, array $options = []): array;

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
}
