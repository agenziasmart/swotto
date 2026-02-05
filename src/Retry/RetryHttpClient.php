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
    private readonly HttpClientInterface $decoratedClient;

    private readonly int $maxAttempts;

    private readonly int $initialDelayMs;

    private readonly int $maxDelayMs;

    private readonly float $multiplier;

    private readonly bool $jitterEnabled;

    private readonly ?LoggerInterface $logger;

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

                usleep($delayMs * 1000);
            }
        }

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
        if ($e instanceof NetworkException) {
            return true;
        }

        if ($e instanceof RateLimitException) {
            return true;
        }

        if ($e instanceof ApiException && $e->getStatusCode() >= 500) {
            return true;
        }

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
        if ($e instanceof RateLimitException && $e->getRetryAfter() > 0) {
            return $e->getRetryAfter() * 1000;
        }

        $delay = (int) ($this->initialDelayMs * pow($this->multiplier, $attempt - 1));
        $delay = min($delay, $this->maxDelayMs);

        if ($this->jitterEnabled && $delay > 0) {
            $jitter = (int) ($delay * 0.25);
            if ($jitter > 0) {
                $delay = $delay + random_int(-$jitter, $jitter);
            }
        }

        return max(1, $delay);
    }

    /**
     * Log a message with context.
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array<string, mixed> $context Additional context
     * @return void
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $this->logger?->log($level, "[Swotto Retry] {$message}", $context);
    }
}
