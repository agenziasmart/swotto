<?php

declare(strict_types=1);

namespace Swotto;

use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use Swotto\Config\Configuration;
use Swotto\Contract\ClientInterface;
use Swotto\Contract\HttpClientInterface;
use Swotto\Exception\AuthenticationException;
use Swotto\Exception\ConnectionException;
use Swotto\Exception\SecurityException;
use Swotto\Http\GuzzleHttpClient;
use Swotto\Response\SwottoResponse;
use Swotto\Trait\PopTrait;

/**
 * Client.
 *
 * Main Swotto API Client implementation
 */
final class Client implements ClientInterface
{
    use PopTrait;

    /**
     * @var HttpClientInterface HTTP client implementation
     */
    private HttpClientInterface $httpClient;

    /**
     * @var LoggerInterface Logger instance
     */
    private LoggerInterface $logger;

    /**
     * @var Configuration Client configuration
     */
    private Configuration $config;

    /**
     * @var CacheInterface|null Cache implementation for Circuit Breaker persistence
     */
    private ?CacheInterface $cache;

    /**
     * Constructor.
     *
     * @param array<string, mixed> $config Configuration options
     * @param LoggerInterface|null $logger Optional logger
     * @param HttpClientInterface|null $httpClient Optional HTTP client implementation
     * @param CacheInterface|null $cache Optional cache implementation for Circuit Breaker persistence
     *
     * @throws \Swotto\Exception\ConfigurationException On invalid configuration
     */
    public function __construct(
        array $config = [],
        ?LoggerInterface $logger = null,
        ?HttpClientInterface $httpClient = null,
        ?CacheInterface $cache = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->config = new Configuration($config);
        $this->cache = $cache;

        $this->httpClient = $httpClient ?? $this->createHttpClient();
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $uri, array $options = []): array
    {
        return $this->httpClient->request('GET', $uri, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function post(string $uri, mixed $data = [], array $options = []): array
    {
        return $this->requestWithAutoDetection('POST', $uri, $data, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function patch(string $uri, mixed $data = [], array $options = []): array
    {
        return $this->requestWithAutoDetection('PATCH', $uri, $data, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function put(string $uri, mixed $data = [], array $options = []): array
    {
        return $this->requestWithAutoDetection('PUT', $uri, $data, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $uri, array $options = []): array
    {
        return $this->httpClient->request('DELETE', $uri, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function checkConnection(): bool
    {
        try {
            $this->get('ping');

            return true;
        } catch (ConnectionException $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function checkAuth(?array $options = null): ?array
    {
        // Skip API call if no access token - user is not authenticated
        if (!$this->hasAccessToken()) {
            $this->logger->debug('checkAuth skipped - no access token configured');

            return null;
        }

        try {
            return $this->get('auth', $options ?? []);
        } catch (AuthenticationException $e) {
            // Token invalid or expired - normal behavior
            $this->logger->debug('checkAuth failed - token invalid or expired', [
                'status' => $e->getStatusCode(),
            ]);

            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function checkSession(?array $options = null): array
    {
        return $this->get('session', $options ?? []);
    }

    /**
     * {@inheritdoc}
     */
    public function setSessionId(string $sessionId): void
    {
        $this->updateConfig(['session_id' => $sessionId]);
    }

    /**
     * {@inheritdoc}
     */
    public function setLanguage(string $language): void
    {
        $this->updateConfig(['language' => $language]);
    }

    /**
     * {@inheritdoc}
     */
    public function setAccept(string $accept): void
    {
        $this->updateConfig(['accept' => $accept]);
    }

    /**
     * {@inheritdoc}
     */
    public function fetchPop(string $uri, array $query = []): array
    {
        $response = $this->get($uri, ['query' => $query]);

        return $response['data'] ?? [];
    }

    /**
     * Update client configuration.
     *
     * @param array<string, mixed> $newConfig New configuration options
     * @return void
     */
    private function updateConfig(array $newConfig): void
    {
        $this->config = $this->config->update($newConfig);
        $this->httpClient->initialize($this->config->toArray());
    }

    /**
     * Set client original user agent.
     *
     * @param string $userAgent Original client user agent
     * @return void
     */
    public function setClientUserAgent(string $userAgent): void
    {
        $this->updateConfig(['client_user_agent' => $userAgent]);
    }

    /**
     * Set client original IP.
     *
     * @param string $ip Original client IP
     * @return void
     */
    public function setClientIp(string $ip): void
    {
        $this->updateConfig(['client_ip' => $ip]);
    }

    /**
     * Send a GET request and parse the response.
     *
     * @param string $uri The URI to request
     * @param array $options Request options to apply
     * @return array The parsed response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function getParsed(string $uri, array $options = []): array
    {
        $response = $this->get($uri, $options);

        return $this->parseSwottoResponse($response);
    }

    /**
     * Send a POST request and parse the response.
     *
     * @param string $uri The URI to request
     * @param mixed $data Data to send (array for JSON auto-detection)
     * @param array $options Request options to apply
     * @return array The parsed response data with 'data', 'paginator', 'success' keys
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function postParsed(string $uri, mixed $data = [], array $options = []): array
    {
        $response = $this->post($uri, $data, $options);

        return $this->parseSwottoResponse($response);
    }

    /**
     * Send a PATCH request and parse the response.
     *
     * @param string $uri The URI to request
     * @param mixed $data Data to send (array for JSON auto-detection)
     * @param array $options Request options to apply
     * @return array The parsed response data with 'data', 'paginator', 'success' keys
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function patchParsed(string $uri, mixed $data = [], array $options = []): array
    {
        $response = $this->patch($uri, $data, $options);

        return $this->parseSwottoResponse($response);
    }

    /**
     * Send a PUT request and parse the response.
     *
     * @param string $uri The URI to request
     * @param mixed $data Data to send (array for JSON auto-detection)
     * @param array $options Request options to apply
     * @return array The parsed response data with 'data', 'paginator', 'success' keys
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function putParsed(string $uri, mixed $data = [], array $options = []): array
    {
        $response = $this->put($uri, $data, $options);

        return $this->parseSwottoResponse($response);
    }

    /**
     * Send a DELETE request and parse the response.
     *
     * @param string $uri The URI to request
     * @param array $options Request options to apply
     * @return array The parsed response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function deleteParsed(string $uri, array $options = []): array
    {
        $response = $this->delete($uri, $options);

        return $this->parseSwottoResponse($response);
    }

    /**
     * Parse Swotto API response (mirrors DataHandlerTrait::parseDataResponse).
     *
     * @param array $response Raw API response
     * @return array Parsed response with data, paginator, and success
     */
    private function parseSwottoResponse(array $response): array
    {
        $parsedResponse = [
            'data' => [],
            'paginator' => [],
            'success' => false,
        ];

        // Success flag
        $parsedResponse['success'] = isset($response['success']) && $response['success'] === true;

        // Extract data
        if (isset($response['data']) && !empty($response['data'])) {
            $parsedResponse['data'] = $response['data'];
        }

        // Build paginator from meta.pagination
        if (isset($response['meta']) && !empty($response['meta'])) {
            if (isset($response['meta']['pagination']) && !empty($response['meta']['pagination'])) {
                $parsedResponse['paginator'] = $this->buildPaginator($response['meta']['pagination']);
            }
        }

        return $parsedResponse;
    }

    /**
     * Build paginator array (compatible with SW4 APP paginator helper).
     *
     * @param array $pagination Pagination metadata from API response
     * @return array Formatted paginator data
     */
    private function buildPaginator(array $pagination): array
    {
        if (empty($pagination)) {
            return [];
        }

        $delta = 1;
        $current = $pagination['current_page'] ?? null;
        $perPage = $pagination['per_page'] ?? null;
        $last = $pagination['total_pages'] ?? null;
        $results = $pagination['total'] ?? null;
        $range = [];

        if ($last > 1) {
            for ($i = max(2, ($current - $delta)); $i <= min(($last - 1), ($current + $delta)); $i += 1) {
                $range[] = $i;
            }
            if (($current - $delta) > 2) {
                array_unshift($range, count($range) == ($last - 3) ? 2 : '...');
            }
            if (($current + $delta) < ($last - 1)) {
                $range[] = count($range) == ($last - 3) ? ($last - 1) : '...';
            }
            array_unshift($range, 1);
            $range[] = $last;
        }

        return [
            'current' => $current,
            'last' => $last,
            'per_page' => $perPage,
            'results' => $results,
            'range' => $range,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function setAccessToken(string $token): void
    {
        $this->updateConfig(['access_token' => $token]);
    }

    /**
     * {@inheritdoc}
     */
    public function clearAccessToken(): void
    {
        $this->updateConfig(['access_token' => null]);
    }

    /**
     * {@inheritdoc}
     */
    public function getAccessToken(): ?string
    {
        return $this->config->get('access_token');
    }

    /**
     * {@inheritdoc}
     */
    public function hasAccessToken(): bool
    {
        return $this->config->hasAccessToken();
    }

    /**
     * Get response as SwottoResponse object for advanced content handling.
     *
     * @param string $uri The URI to request
     * @param array $options Request options to apply
     * @return SwottoResponse Smart response wrapper
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function getResponse(string $uri, array $options = []): SwottoResponse
    {
        $rawResponse = $this->httpClient->requestRaw('GET', $uri, $options);

        return new SwottoResponse($rawResponse);
    }

    /**
     * Download content directly to file with security validation.
     *
     * @param string $uri The URI to request
     * @param string $filePath Destination file path
     * @param array $options Request options to apply
     * @return bool True on successful download
     *
     * @throws SecurityException If path validation fails
     * @throws \Swotto\Exception\FileOperationException If file operations fail
     * @throws \Swotto\Exception\SwottoException On other errors
     */
    public function downloadToFile(string $uri, string $filePath, array $options = []): bool
    {
        $response = $this->getResponse($uri, $options);

        return $response->saveToFile($filePath);
    }

    /**
     * Create HTTP client with optional Retry and Circuit Breaker decorators.
     *
     * Decorator chain order (bottom to top):
     * 1. GuzzleHttpClient (base, makes actual HTTP calls)
     * 2. RetryHttpClient (exponential backoff for transient errors)
     * 3. CircuitBreakerHttpClient (fail-fast on persistent failures)
     *
     * @return HttpClientInterface HTTP client instance
     */
    private function createHttpClient(): HttpClientInterface
    {
        // Step 1: Base HTTP client
        $client = new GuzzleHttpClient($this->config, $this->logger);

        // Step 2: Wrap with retry decorator (if enabled)
        if ($this->config->get('retry_enabled', false)) {
            $client = new \Swotto\Retry\RetryHttpClient(
                $client,
                $this->config,
                $this->logger
            );
        }

        // Step 3: Wrap with circuit breaker decorator (if enabled)
        if (!$this->config->get('circuit_breaker_enabled', false)) {
            return $client;
        }

        $circuitBreaker = new \Swotto\CircuitBreaker\CircuitBreaker(
            name: $this->config->getBaseUrl(),
            failureThreshold: $this->config->get('circuit_breaker_failure_threshold', 5),
            recoveryTimeout: $this->config->get('circuit_breaker_recovery_timeout', 30),
            successThreshold: 2,
            cache: $this->cache,
            logger: $this->logger
        );

        return new \Swotto\CircuitBreaker\CircuitBreakerHttpClient($client, $circuitBreaker);
    }

    /**
     * Request with auto-detection for data type.
     *
     * Automatically converts array data to JSON for POST/PUT/PATCH requests.
     *
     * @param string $method HTTP method
     * @param string $uri URI to request
     * @param mixed $data Data to send (array for JSON auto-detection, or resource/StreamInterface)
     * @param array $options Additional Guzzle options
     * @return array Response data
     */
    private function requestWithAutoDetection(string $method, string $uri, mixed $data, array $options): array
    {
        // Auto-detect JSON for array data
        $hasBodyOption = isset($options['json'])
            || isset($options['form_params'])
            || isset($options['multipart'])
            || isset($options['body']);

        if (is_array($data) && !empty($data) && !$hasBodyOption) {
            $options['json'] = $data;
        }

        return $this->httpClient->request($method, $uri, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function postFile(
        string $uri,
        $fileResource,
        string $fieldName = 'file',
        array $metadata = [],
        array $options = []
    ): array {
        $multipart = $this->buildMultipartData($fileResource, $fieldName, $metadata);
        $options['multipart'] = $multipart;

        return $this->httpClient->request('POST', $uri, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function postFiles(
        string $uri,
        array $files,
        array $metadata = [],
        array $options = []
    ): array {
        $multipart = [];

        // Add all files
        foreach ($files as $fieldName => $fileResource) {
            $multipart[] = [
                'name' => $fieldName,
                'contents' => $fileResource,
            ];
        }

        // Add metadata
        foreach ($metadata as $key => $value) {
            $multipart[] = [
                'name' => $key,
                'contents' => is_array($value) ? json_encode($value) : (string) $value,
            ];
        }

        $options['multipart'] = $multipart;

        return $this->httpClient->request('POST', $uri, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function putFile(
        string $uri,
        $fileResource,
        string $fieldName = 'file',
        array $metadata = [],
        array $options = []
    ): array {
        $multipart = $this->buildMultipartData($fileResource, $fieldName, $metadata);
        $options['multipart'] = $multipart;

        return $this->httpClient->request('PUT', $uri, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function patchFile(
        string $uri,
        $fileResource,
        string $fieldName = 'file',
        array $metadata = [],
        array $options = []
    ): array {
        $multipart = $this->buildMultipartData($fileResource, $fieldName, $metadata);
        $options['multipart'] = $multipart;

        return $this->httpClient->request('PATCH', $uri, $options);
    }

    /**
     * Build multipart form data for file upload.
     *
     * @param resource|StreamInterface $fileResource File resource or PSR-7 stream
     * @param string $fieldName Field name for the file
     * @param array $metadata Additional form fields
     * @return array Multipart form data array
     *
     * @throws \InvalidArgumentException If $fileResource is not a valid type
     */
    private function buildMultipartData(mixed $fileResource, string $fieldName, array $metadata): array
    {
        // Validate file resource type
        if (!is_resource($fileResource) && !$fileResource instanceof StreamInterface) {
            throw new \InvalidArgumentException(
                sprintf(
                    'File resource must be a PHP resource or StreamInterface, %s given',
                    get_debug_type($fileResource)
                )
            );
        }

        $multipart = [
            [
                'name' => $fieldName,
                'contents' => $fileResource,
            ],
        ];

        // Add metadata
        foreach ($metadata as $key => $value) {
            $multipart[] = [
                'name' => $key,
                'contents' => is_array($value) ? json_encode($value) : (string) $value,
            ];
        }

        return $multipart;
    }
}
