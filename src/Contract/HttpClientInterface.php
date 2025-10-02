<?php

declare(strict_types=1);

namespace Swotto\Contract;

use Psr\Http\Message\ResponseInterface;

/**
 * Interface HttpClientInterface.
 *
 * Defines the contract for the underlying HTTP client implementation.
 * This allows for potential future HTTP client changes without changing
 * the main Swotto Client implementation.
 */
interface HttpClientInterface
{
    /**
     * Perform an HTTP request and return parsed data.
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE)
     * @param string $uri Request URI
     * @param array $options Request options
     * @return array Response data as array
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function request(string $method, string $uri, array $options = []): array;

    /**
     * Perform an HTTP request and return raw PSR-7 response.
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE)
     * @param string $uri Request URI
     * @param array $options Request options
     * @return ResponseInterface Raw PSR-7 response
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function requestRaw(string $method, string $uri, array $options = []): ResponseInterface;

    /**
     * Initialize the HTTP client with configuration.
     *
     * @param array $config Configuration options
     * @return void
     *
     * @throws \Swotto\Exception\ConfigurationException On invalid configuration
     */
    public function initialize(array $config): void;
}
