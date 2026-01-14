<?php

declare(strict_types=1);

namespace Swotto\Retry;

use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Swotto\Config\Configuration;
use Swotto\Contract\HttpClientInterface;
use Swotto\Exception\ApiException;
use Swotto\Exception\NetworkException;
use Swotto\Exception\RateLimitException;

/**
 * Retry HTTP Client Decorator.
 *
 * Implements exponential backoff with jitter for transient errors.
 * Wraps any HttpClientInterface and adds automatic retry logic.
 */
final class RetryHttpClient implements HttpClientInterface
{
    private HttpClientInterface $decoratedClient;

    private int $maxAttempts;

    private int $initialDelayMs;

    private int $maxDelayMs;

    private float $multiplier;

    private bool $jitterEnabled;

    private ?LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param HttpClientInterface $decoratedClient The HTTP client to wrap
     * @param Configuration $config Configuration with retry options
     * @param LoggerInterface|null $logger Optional logger for retry events
     */
    public function __construct(
        HttpClientInterface $decoratedClient,
        Configuration $config,
        ?LoggerInterface $logger = null
    ) {
        $this->decoratedClient = $decoratedClient;
        $this->maxAttempts = $config->get('retry_max_attempts', 3);
        $this->initialDelayMs = $config->get('retry_initial_delay_ms', 100);
        $this->maxDelayMs = $config->get('retry_max_delay_ms', 10000);
        $this->multiplier = (float) $config->get('retry_multiplier', 2.0);
        $this->jitterEnabled = $config->get('retry_jitter', true);
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function request(string $method, string $uri, array $options = []): array
    {
        return $this->executeWithRetry(
            fn () => $this->decoratedClient->request($method, $uri, $options),
            $method,
            $uri
        );
    }

    /**
     * {@inheritdoc}
     */
    public function requestRaw(string $method, string $uri, array $options = []): ResponseInterface
    {
        return $this->executeWithRetry(
            fn () => $this->decoratedClient->requestRaw($method, $uri, $options),
            $method,
            $uri
        );
    }

    /**
     * {@inheritdoc}
     */
    public function initialize(array $config): void
    {
        $this->decoratedClient->initialize($config);
    }

    /**
     * Execute an operation with retry logic.
     *
     * @template T
     * @param callable(): T $operation The operation to execute
     * @param string $method HTTP method for logging
     * @param string $uri URI for logging
     * @return T Operation result
     *
     * @throws \Exception The last exception if all retries fail
     */
    private function executeWithRetry(callable $operation, string $method, string $uri): mixed
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                $result = $operation();

                if ($attempt > 1) {
                    $this->log('info', 'Request succeeded after retry', [
                        'method' => $method,
                        'uri' => $uri,
                        'attempt' => $attempt,
                    ]);
                }

                return $result;
            } catch (\Exception $e) {
                $lastException = $e;

                if (!$this->isRetryable($e) || $attempt >= $this->maxAttempts) {
                    throw $e;
                }

                $delayMs = $this->calculateDelay($e, $attempt);

                $this->log('warning', 'Retrying request after transient error', [
                    'method' => $method,
                    'uri' => $uri,
                    'attempt' => $attempt,
                    'max_attempts' => $this->maxAttempts,
                    'delay_ms' => $delayMs,
                    'error' => $e->getMessage(),
                    'error_class' => get_class($e),
                ]);

                usleep($delayMs * 1000); // ms → microseconds
            }
        }

        // This should never be reached, but satisfies static analysis
        throw $lastException ?? new \RuntimeException('Unexpected retry loop exit');
    }

    /**
     * Determine if an exception is retryable.
     *
     * @param \Exception $e The exception to check
     * @return bool True if the request should be retried
     */
    private function isRetryable(\Exception $e): bool
    {
        // Network errors - always retry (includes ConnectionException)
        if ($e instanceof NetworkException) {
            return true;
        }

        // Rate limit (429) - retry with server delay
        if ($e instanceof RateLimitException) {
            return true;
        }

        // Server errors (5xx) - retry
        if ($e instanceof ApiException && $e->getStatusCode() >= 500) {
            return true;
        }

        // Client errors (4xx) - DON'T retry
        // 401, 403, 404, 422 won't change with retry
        return false;
    }

    /**
     * Calculate delay before next retry attempt.
     *
     * @param \Exception $e The exception that triggered the retry
     * @param int $attempt Current attempt number (1-based)
     * @return int Delay in milliseconds
     */
    private function calculateDelay(\Exception $e, int $attempt): int
    {
        // For 429, respect server's Retry-After header
        if ($e instanceof RateLimitException && $e->getRetryAfter() > 0) {
            return $e->getRetryAfter() * 1000; // seconds → ms
        }

        // Exponential backoff: initial * multiplier^(attempt-1)
        $delay = (int) ($this->initialDelayMs * pow($this->multiplier, $attempt - 1));

        // Cap at max delay
        $delay = min($delay, $this->maxDelayMs);

        // Add jitter (±25%) to prevent thundering herd
        if ($this->jitterEnabled && $delay > 0) {
            $jitter = (int) ($delay * 0.25);
            if ($jitter > 0) {
                $delay = $delay + random_int(-$jitter, $jitter);
            }
        }

        return max(1, $delay); // Minimum 1ms
    }

    /**
     * Log a message with context.
     *
     * @param string $level Log level (info, warning, etc.)
     * @param string $message Log message
     * @param array<string, mixed> $context Additional context
     * @return void
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $this->logger?->log($level, "[Swotto Retry] {$message}", $context);
    }
}
