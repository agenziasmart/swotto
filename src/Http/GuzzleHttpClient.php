<?php

declare(strict_types=1);

namespace Swotto\Http;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use Swotto\CircuitBreaker\CircuitBreaker;
use Swotto\CircuitBreaker\CircuitBreakerHttpClient;
use Swotto\Config\Configuration;
use Swotto\Contract\HttpClientInterface;
use Swotto\Exception\ApiException;
use Swotto\Exception\AuthenticationException;
use Swotto\Exception\ConnectionException;
use Swotto\Exception\ForbiddenException;
use Swotto\Exception\NetworkException;
use Swotto\Exception\NotFoundException;
use Swotto\Exception\RateLimitException;
use Swotto\Exception\ValidationException;

/**
 * GuzzleHttpClient.
 *
 * HTTP Client implementation using Guzzle
 */
class GuzzleHttpClient implements HttpClientInterface
{
    /**
     * @var string SDK version
     */
    private const VERSION = '2.3.0';

    /**
     * @var int Default request timeout
     */
    private const DEFAULT_TIMEOUT = 10;

    /**
     * @var bool Allow redirects by default
     */
    private const ALLOW_REDIRECTS = true;

    /**
     * @var bool Verify SSL by default
     */
    private const VERIFY_SSL = true;

    /**
     * @var GuzzleClient Guzzle HTTP client instance
     */
    private GuzzleClient $client;

    /**
     * @var LoggerInterface Logger instance
     */
    private LoggerInterface $logger;

    /**
     * @var Configuration Client configuration
     */
    private Configuration $config;

    /**
     * Constructor.
     *
     * @param Configuration $config Configuration instance
     * @param LoggerInterface|null $logger Optional logger
     */
    public function __construct(Configuration $config, ?LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
        $this->initialize($config->toArray());
    }

    /**
     * {@inheritdoc}
     */
    public function initialize(array $config): void
    {
        // SDK User-Agent
        $sdkAuthor = 'Swotto-SDK/' . self::VERSION . ' PHP/' . PHP_VERSION;
        $headers = array_merge(
            [
            'x-author' => $sdkAuthor,
            ],
            $this->config->getHeaders()
        );

        $httpConfig = [
          'base_uri' => $this->config->getBaseUrl(),
          'headers' => $headers,
          'timeout' => self::DEFAULT_TIMEOUT,
          'allow_redirects' => self::ALLOW_REDIRECTS,
          'verify' => $this->config->get('verify_ssl', self::VERIFY_SSL),
        ];

        try {
            $this->client = new GuzzleClient($httpConfig);
        } catch (\Exception $e) {
            throw new ConnectionException(
                $e->getMessage(),
                $this->config->getBaseUrl()
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function request(string $method, string $uri, array $options = []): array
    {
        $this->logger->info("Requesting {$method} {$uri}", $options);

        try {
            $response = $this->client->request($method, $uri, $options);

            // Return empty array if response body is empty
            if ($response->getBody()->getSize() === 0) {
                return [];
            }

            return json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $exception) {
            return $this->handleException($exception, $uri);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function requestRaw(string $method, string $uri, array $options = []): ResponseInterface
    {
        $this->logger->info("Raw request {$method} {$uri}", $options);

        try {
            return $this->client->request($method, $uri, $options);
        } catch (\Exception $exception) {
            $this->handleRawException($exception, $uri);
        }
    }

    /**
     * Handle exceptions that might occur during API requests.
     *
     * @param \Exception $exception The caught exception
     * @param string $uri The requested URI
     * @return array Never returns, always throws an exception
     *
     * @throws ApiException|ConnectionException|NetworkException|\Exception
     */
    private function handleException(\Exception $exception, string $uri): array
    {
        $this->logger->error("Error while requesting {$uri}: {$exception->getMessage()}");

        if ($exception instanceof RequestException) {
            $code = $exception->getCode();
            $body = [];

            if ($exception->hasResponse()) {
                $response = $exception->getResponse();
                if ($response !== null) {
                    $body = json_decode($response->getBody()->getContents(), true);

                    // Handle different error codes
                    switch ($code) {
                        case 400:
                            throw new ValidationException(
                                $body['message'] ?? 'Invalid field',
                                $body ?? [],
                                $code
                            );

                        case 401:
                            throw new AuthenticationException(
                                $body['message'] ?? 'Unauthorized',
                                $body ?? [],
                                $code
                            );

                        case 403:
                            throw new ForbiddenException(
                                $body['message'] ?? 'Forbidden',
                                $body ?? [],
                                $code
                            );

                        case 404:
                            throw new NotFoundException(
                                $body['message'] ?? 'Not Found',
                                $body ?? [],
                                $code
                            );

                        case 429:
                            $retryAfter = (int) ($response->getHeader('Retry-After')[0] ?? 0);
                            throw new RateLimitException(
                                $body['message'] ?? 'Too Many Requests',
                                $body ?? [],
                                $retryAfter
                            );
                    }

                    throw new ApiException(
                        $body['message'] ?? $exception->getMessage(),
                        $body ?? [],
                        $code
                    );
                }
            }

            throw new NetworkException(
                "Network error while requesting {$uri}: {$exception->getMessage()}",
                [],
                $code
            );
        }

        if ($exception instanceof \GuzzleHttp\Exception\ConnectException) {
            throw new ConnectionException(
                $exception->getMessage(),
                $this->config->getBaseUrl(),
                array_slice(explode("\n", $exception->getTraceAsString()), 0, 10),
                $exception->getCode()
            );
        }

        // Rethrow any other exceptions
        throw $exception;
    }

    /**
     * Handle exceptions for raw requests, preserving response data when possible.
     *
     * @param \Exception $exception The caught exception
     * @param string $uri The requested URI
     * @return never Always throws an exception
     *
     * @throws ApiException|ConnectionException|NetworkException|\Exception
     */
    private function handleRawException(\Exception $exception, string $uri): never
    {
        $this->logger->error("Error while requesting {$uri}: {$exception->getMessage()}");

        if ($exception instanceof RequestException) {
            $code = $exception->getCode();
            $body = [];

            if ($exception->hasResponse()) {
                $response = $exception->getResponse();
                if ($response !== null) {
                    // Try to extract body for error details, but don't assume JSON
                    try {
                        $bodyContent = $response->getBody()->getContents();
                        $body = json_decode($bodyContent, true) ?? ['raw_body' => $bodyContent];
                    } catch (\Exception) {
                        $body = ['error' => 'Could not read response body'];
                    }

                    // Handle different error codes
                    switch ($code) {
                        case 400:
                            throw new ValidationException(
                                $body['message'] ?? 'Invalid field',
                                $body,
                                $code
                            );

                        case 401:
                            throw new AuthenticationException(
                                $body['message'] ?? 'Unauthorized',
                                $body,
                                $code
                            );

                        case 403:
                            throw new ForbiddenException(
                                $body['message'] ?? 'Forbidden',
                                $body,
                                $code
                            );

                        case 404:
                            throw new NotFoundException(
                                $body['message'] ?? 'Not Found',
                                $body,
                                $code
                            );

                        case 429:
                            $retryAfter = (int) ($response->getHeader('Retry-After')[0] ?? 0);
                            throw new RateLimitException(
                                $body['message'] ?? 'Too Many Requests',
                                $body,
                                $retryAfter
                            );
                    }

                    throw new ApiException(
                        $body['message'] ?? $exception->getMessage(),
                        $body,
                        $code
                    );
                }
            }

            throw new NetworkException(
                "Network error while requesting {$uri}: {$exception->getMessage()}",
                [],
                $code
            );
        }

        if ($exception instanceof \GuzzleHttp\Exception\ConnectException) {
            throw new ConnectionException(
                $exception->getMessage(),
                $this->config->getBaseUrl(),
                array_slice(explode("\n", $exception->getTraceAsString()), 0, 10),
                $exception->getCode()
            );
        }

        // Rethrow any other exceptions
        throw $exception;
    }

    /**
     * Create a new GuzzleHttpClient instance with circuit breaker support.
     *
     * @param Configuration $config Configuration instance
     * @param LoggerInterface|null $logger Optional logger
     * @param CacheInterface|null $cache Optional cache for circuit breaker state
     * @return HttpClientInterface HTTP client with optional circuit breaker
     */
    public static function withCircuitBreaker(
        Configuration $config,
        ?LoggerInterface $logger = null,
        ?CacheInterface $cache = null
    ): HttpClientInterface {
        $baseClient = new self($config, $logger);

        // Return base client if circuit breaker is disabled
        if (!$config->get('circuit_breaker_enabled', false)) {
            return $baseClient;
        }

        // Create circuit breaker with configuration
        $circuitBreaker = new CircuitBreaker(
            name: $config->getBaseUrl(), // Use base URL as unique identifier
            failureThreshold: $config->get('circuit_breaker_failure_threshold', 5),
            recoveryTimeout: $config->get('circuit_breaker_recovery_timeout', 30),
            successThreshold: 2, // Fixed for now
            cache: $cache,
            logger: $logger
        );

        return new CircuitBreakerHttpClient($baseClient, $circuitBreaker);
    }
}
