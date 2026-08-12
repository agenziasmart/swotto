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
use Swotto\Http\LogSanitizer;

/**
 * Retry HTTP Client Decorator.
 *
 * Implements exponential backoff with jitter for transient errors.
 * Wraps any HttpClientInterface and adds automatic retry logic.
 */
final class RetryHttpClient implements HttpClientInterface
{
    /**
     * HTTP methods that can be replayed safely.
     *
     * A network error is ambiguous by definition: the request may well have reached the
     * server. Replaying a POST on that basis can duplicate an order, a document or an
     * upload, so only methods defined as safe or idempotent by RFC 9110 are retried
     * automatically. Everything else needs the caller to opt in per request.
     *
     * @var array<int, string>
     */
    private const IDEMPOTENT_METHODS = ['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS', 'TRACE'];

    /**
     * Per-call option that opts a non-idempotent request into retrying.
     */
    private const OPT_IN_OPTION = 'retry_non_idempotent';

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
        $retryable = $this->mayRetry($method, $options);
        unset($options[self::OPT_IN_OPTION]);

        return $this->executeWithRetry(
            fn () => $this->decoratedClient->request($method, $uri, $options),
            $method,
            $uri,
            $retryable
        );
    }

    /**
     * {@inheritdoc}
     */
    public function requestRaw(string $method, string $uri, array $options = []): ResponseInterface
    {
        $retryable = $this->mayRetry($method, $options);
        unset($options[self::OPT_IN_OPTION]);

        return $this->executeWithRetry(
            fn () => $this->decoratedClient->requestRaw($method, $uri, $options),
            $method,
            $uri,
            $retryable
        );
    }

    /**
     * Decide whether this request may be replayed at all.
     *
     * @param string $method HTTP method
     * @param array<string, mixed> $options Request options
     * @return bool True if the request is safe to retry
     */
    private function mayRetry(string $method, array $options): bool
    {
        if (in_array(strtoupper($method), self::IDEMPOTENT_METHODS, true)) {
            return true;
        }

        return ($options[self::OPT_IN_OPTION] ?? false) === true;
    }

    /**
     * Execute an operation with retry logic.
     *
     * @template T
     * @param callable(): T $operation The operation to execute
     * @param string $method HTTP method for logging
     * @param string $uri URI for logging
     * @param bool $retryable Whether this request may be replayed at all
     * @return T Operation result
     *
     * @throws \Exception The last exception if all retries fail
     */
    private function executeWithRetry(
        callable $operation,
        string $method,
        string $uri,
        bool $retryable = true
    ): mixed {
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                $result = $operation();

                if ($attempt > 1) {
                    $this->log('info', 'Request succeeded after retry', [
                        'method' => LogSanitizer::httpMethod($method),
                        'uri' => LogSanitizer::uri($uri),
                        'attempt' => $attempt,
                    ]);
                }

                return $result;
            } catch (\Exception $e) {
                $lastException = $e;

                if (!$retryable || !$this->isRetryable($e) || $attempt >= $this->maxAttempts) {
                    throw $e;
                }

                $delayMs = $this->calculateDelay($e, $attempt);

                $this->log('warning', 'Retrying request after transient error', [
                    'method' => LogSanitizer::httpMethod($method),
                    'uri' => LogSanitizer::uri($uri),
                    'attempt' => $attempt,
                    'max_attempts' => $this->maxAttempts,
                    'delay_ms' => $delayMs,
                    ...$this->failureLogContext($e),
                ]);

                usleep($delayMs * 1000);
            }
        }

        throw $lastException ?? new \RuntimeException('Unexpected retry loop exit');
    }

    /**
     * Build retry diagnostics without copying upstream-controlled exception text.
     *
     * @return array{failure_type: string, exception_type: class-string, status?: int}
     */
    private function failureLogContext(\Exception $exception): array
    {
        $context = [
            'failure_type' => $exception instanceof NetworkException ? 'network' : 'http',
            'exception_type' => $exception::class,
        ];

        if ($exception instanceof ApiException) {
            $context['status'] = $exception->getStatusCode();
        }

        return $context;
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
        // A server-supplied Retry-After is honoured, but never beyond the configured cap:
        // an unbounded value (Retry-After: 86400 is legitimate) would park the worker for
        // a day on a single response.
        if ($e instanceof RateLimitException && $e->getRetryAfter() > 0) {
            return min($e->getRetryAfter() * 1000, $this->maxDelayMs);
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
