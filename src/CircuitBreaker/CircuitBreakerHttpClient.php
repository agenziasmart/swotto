<?php

declare(strict_types=1);

namespace Swotto\CircuitBreaker;

use Psr\Http\Message\ResponseInterface;
use Swotto\Contract\HttpClientInterface;
use Swotto\Exception\ApiException;
use Swotto\Exception\CircuitBreakerOpenException;
use Swotto\Exception\NetworkException;
use Swotto\Exception\SwottoException;

/**
 * CircuitBreakerHttpClient.
 *
 * HTTP Client decorator that implements circuit breaker pattern
 */
final class CircuitBreakerHttpClient implements HttpClientInterface
{
    /**
     * @var HttpClientInterface Decorated HTTP client
     */
    private HttpClientInterface $decoratedClient;

    /**
     * @var CircuitBreaker Circuit breaker instance
     */
    private CircuitBreaker $circuitBreaker;

    /**
     * Constructor.
     *
     * @param HttpClientInterface $decoratedClient HTTP client to decorate
     * @param CircuitBreaker $circuitBreaker Circuit breaker instance
     */
    public function __construct(
        HttpClientInterface $decoratedClient,
        CircuitBreaker $circuitBreaker
    ) {
        $this->decoratedClient = $decoratedClient;
        $this->circuitBreaker = $circuitBreaker;
    }

    /**
     * {@inheritdoc}
     */
    public function initialize(array $config): void
    {
        $this->decoratedClient->initialize($config);
    }

    /**
     * {@inheritdoc}
     */
    public function request(string $method, string $uri, array $options = []): array
    {
        // Check if circuit breaker allows the request
        if (!$this->circuitBreaker->shouldExecute()) {
            throw new CircuitBreakerOpenException(
                'Circuit breaker is OPEN - API temporarily unavailable',
                $this->circuitBreaker->getRecoveryTimeout(),
                503
            );
        }

        try {
            // Execute the request through decorated client
            $result = $this->decoratedClient->request($method, $uri, $options);

            // Record success
            $this->circuitBreaker->recordSuccess();

            return $result;
        } catch (SwottoException $e) {
            // Record failure ONLY for server errors (5xx) or network issues
            // Client errors (4xx) should NOT increment the circuit breaker
            if (
                $e instanceof NetworkException
                || ($e instanceof ApiException && $e->getStatusCode() >= 500)
            ) {
                $this->circuitBreaker->recordFailure();
            }
            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function requestRaw(string $method, string $uri, array $options = []): ResponseInterface
    {
        // Check if circuit breaker allows the request
        if (!$this->circuitBreaker->shouldExecute()) {
            throw new CircuitBreakerOpenException(
                'Circuit breaker is OPEN - API temporarily unavailable',
                $this->circuitBreaker->getRecoveryTimeout(),
                503
            );
        }

        try {
            // Execute the request through decorated client
            $result = $this->decoratedClient->requestRaw($method, $uri, $options);

            // Record success
            $this->circuitBreaker->recordSuccess();

            return $result;
        } catch (SwottoException $e) {
            // Record failure ONLY for server errors (5xx) or network issues
            // Client errors (4xx) should NOT increment the circuit breaker
            if (
                $e instanceof NetworkException
                || ($e instanceof ApiException && $e->getStatusCode() >= 500)
            ) {
                $this->circuitBreaker->recordFailure();
            }
            throw $e;
        }
    }
}
