<?php

declare(strict_types=1);

namespace Swotto;

use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Swotto\Config\Configuration;
use Swotto\Contract\ClientInterface;
use Swotto\Contract\HttpClientInterface;
use Swotto\Exception\ConnectionException;
use Swotto\Http\GuzzleHttpClient;
use Swotto\Response\SwottoResponse;

/**
 * Client.
 *
 * Main Swotto API Client implementation.
 * Immutable, worker-safe. Uses defaultOptions + merge pattern (Stripe-inspired).
 */
final class Client implements ClientInterface
{
    private readonly HttpClientInterface $httpClient;

    private readonly LoggerInterface $logger;

    private readonly Configuration $config;

    /**
     * @var array<string, mixed> Default per-call options extracted from config
     */
    private readonly array $defaultOptions;

    /**
     * Constructor.
     *
     * @param array<string, mixed> $config Configuration options
     * @param LoggerInterface|null $logger Optional logger
     * @param HttpClientInterface|null $httpClient Optional HTTP client implementation
     *
     * @throws \Swotto\Exception\ConfigurationException On invalid configuration
     */
    public function __construct(
        array $config = [],
        ?LoggerInterface $logger = null,
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->config = new Configuration($config);
        $this->defaultOptions = $this->extractDefaultOptions($config);
        $this->httpClient = $httpClient ?? $this->createHttpClient();
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $uri, array $options = []): array
    {
        return $this->httpClient->request('GET', $uri, $this->mergeOptions($options));
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
    public function put(string $uri, mixed $data = [], array $options = []): array
    {
        return $this->requestWithAutoDetection('PUT', $uri, $data, $options);
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
    public function delete(string $uri, array $options = []): array
    {
        return $this->httpClient->request('DELETE', $uri, $this->mergeOptions($options));
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
    public function getResponse(string $uri, array $options = []): SwottoResponse
    {
        $rawResponse = $this->httpClient->requestRaw('GET', $uri, $this->mergeOptions($options));

        return new SwottoResponse($rawResponse);
    }

    /**
     * {@inheritdoc}
     */
    public function downloadToFile(string $uri, string $filePath, array $options = []): bool
    {
        $response = $this->getResponse($uri, $options);

        return $response->saveToFile($filePath);
    }

    /**
     * {@inheritdoc}
     */
    public function postFile(
        string $uri,
        $fileResource,
        string $fieldName = 'file',
        array $metadata = [],
        array $options = [],
        ?string $filename = null,
        ?string $contentType = null,
    ): array {
        $multipart = $this->buildMultipartData($fileResource, $fieldName, $metadata, $filename, $contentType);
        $options['multipart'] = $multipart;

        return $this->httpClient->request('POST', $uri, $this->mergeOptions($options));
    }

    /**
     * {@inheritdoc}
     */
    public function postFiles(
        string $uri,
        array $files,
        array $metadata = [],
        array $options = [],
    ): array {
        $multipart = [];

        foreach ($files as $fieldName => $file) {
            if (is_array($file) && array_key_exists('contents', $file)) {
                $entry = [
                    'name' => $fieldName,
                    'contents' => $file['contents'],
                ];
                if (array_key_exists('filename', $file)) {
                    $entry['filename'] = $file['filename'];
                }
                if (array_key_exists('content_type', $file)) {
                    $entry['headers'] = ['Content-Type' => $file['content_type']];
                }
                $multipart[] = $entry;
            } else {
                $multipart[] = [
                    'name' => $fieldName,
                    'contents' => $file,
                ];
            }
        }

        foreach ($metadata as $key => $value) {
            $multipart[] = [
                'name' => $key,
                'contents' => is_array($value) ? json_encode($value) : (string) $value,
            ];
        }

        $options['multipart'] = $multipart;

        return $this->httpClient->request('POST', $uri, $this->mergeOptions($options));
    }

    /**
     * {@inheritdoc}
     */
    public function putFile(
        string $uri,
        $fileResource,
        string $fieldName = 'file',
        array $metadata = [],
        array $options = [],
        ?string $filename = null,
        ?string $contentType = null,
    ): array {
        $multipart = $this->buildMultipartData($fileResource, $fieldName, $metadata, $filename, $contentType);
        $options['multipart'] = $multipart;

        return $this->httpClient->request('PUT', $uri, $this->mergeOptions($options));
    }

    /**
     * {@inheritdoc}
     */
    public function patchFile(
        string $uri,
        $fileResource,
        string $fieldName = 'file',
        array $metadata = [],
        array $options = [],
        ?string $filename = null,
        ?string $contentType = null,
    ): array {
        $multipart = $this->buildMultipartData($fileResource, $fieldName, $metadata, $filename, $contentType);
        $options['multipart'] = $multipart;

        return $this->httpClient->request('PATCH', $uri, $this->mergeOptions($options));
    }

    /**
     * Extract default per-call options from config.
     *
     * These options are merged with per-call options on every request.
     * Per-call options take precedence over defaults.
     *
     * @param array<string, mixed> $config Configuration array
     * @return array<string, mixed> Default options for per-call merging
     */
    private function extractDefaultOptions(array $config): array
    {
        $defaults = [];

        if (isset($config['bearer_token']) && $config['bearer_token'] !== '') {
            $defaults['bearer_token'] = $config['bearer_token'];
        }

        if (isset($config['language']) && $config['language'] !== '') {
            $defaults['language'] = $config['language'];
        }

        if (isset($config['session_id']) && $config['session_id'] !== '') {
            $defaults['session_id'] = $config['session_id'];
        }

        if (isset($config['client_ip']) && $config['client_ip'] !== '') {
            $defaults['client_ip'] = $config['client_ip'];
        }

        if (isset($config['client_user_agent']) && $config['client_user_agent'] !== '') {
            $defaults['client_user_agent'] = $config['client_user_agent'];
        }

        return $defaults;
    }

    /**
     * Merge default options with per-call options.
     *
     * Per-call options take precedence over defaults.
     *
     * @param array<string, mixed> $perCallOptions Per-call options
     * @return array<string, mixed> Merged options
     */
    private function mergeOptions(array $perCallOptions): array
    {
        if (empty($this->defaultOptions)) {
            return $perCallOptions;
        }

        return array_merge($this->defaultOptions, $perCallOptions);
    }

    /**
     * Request with auto-detection for data type.
     *
     * @param string $method HTTP method
     * @param string $uri URI to request
     * @param mixed $data Data to send
     * @param array<string, mixed> $options Additional options
     * @return array<string, mixed> Response data
     */
    private function requestWithAutoDetection(string $method, string $uri, mixed $data, array $options): array
    {
        $hasBodyOption = isset($options['json'])
            || isset($options['form_params'])
            || isset($options['multipart'])
            || isset($options['body']);

        if (is_array($data) && !empty($data) && !$hasBodyOption) {
            $options['json'] = $data;
        }

        return $this->httpClient->request($method, $uri, $this->mergeOptions($options));
    }

    /**
     * Create HTTP client with optional Retry decorator.
     *
     * @return HttpClientInterface HTTP client instance
     */
    private function createHttpClient(): HttpClientInterface
    {
        $client = new GuzzleHttpClient($this->config, $this->logger);

        if ($this->config->get('retry_enabled', false)) {
            $client = new \Swotto\Retry\RetryHttpClient(
                $client,
                $this->config,
                $this->logger
            );
        }

        return $client;
    }

    /**
     * Build multipart form data for file upload.
     *
     * @param resource|StreamInterface $fileResource File resource or PSR-7 stream
     * @param string $fieldName Field name for the file
     * @param array<string, mixed> $metadata Additional form fields
     * @param string|null $filename Original filename
     * @param string|null $contentType File MIME type
     * @return array<int, array<string, mixed>> Multipart form data array
     *
     * @throws \InvalidArgumentException If $fileResource is not a valid type
     */
    private function buildMultipartData(
        mixed $fileResource,
        string $fieldName,
        array $metadata,
        ?string $filename = null,
        ?string $contentType = null,
    ): array {
        if (!is_resource($fileResource) && !$fileResource instanceof StreamInterface) {
            throw new \InvalidArgumentException(
                sprintf(
                    'File resource must be a PHP resource or StreamInterface, %s given',
                    get_debug_type($fileResource)
                )
            );
        }

        $fileEntry = [
            'name' => $fieldName,
            'contents' => $fileResource,
        ];

        if ($filename !== null) {
            $fileEntry['filename'] = $filename;
        }

        if ($contentType !== null) {
            $fileEntry['headers'] = ['Content-Type' => $contentType];
        }

        $multipart = [$fileEntry];

        foreach ($metadata as $key => $value) {
            $multipart[] = [
                'name' => $key,
                'contents' => is_array($value) ? json_encode($value) : (string) $value,
            ];
        }

        return $multipart;
    }
}
