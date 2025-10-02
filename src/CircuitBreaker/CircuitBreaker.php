<?php

declare(strict_types=1);

namespace Swotto\CircuitBreaker;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * CircuitBreaker.
 *
 * Implements circuit breaker pattern for resilient API calls
 */
class CircuitBreaker
{
    /**
     * @var string Cache key prefix for state persistence
     */
    private const CACHE_KEY_PREFIX = 'swotto:cb:';

    /**
     * @var CircuitState Current circuit state
     */
    private CircuitState $state = CircuitState::CLOSED;

    /**
     * @var int Current failure count
     */
    private int $failures = 0;

    /**
     * @var int|null Timestamp when circuit was opened
     */
    private ?int $openedAt = null;

    /**
     * @var int Number of consecutive failures to open circuit
     */
    private int $failureThreshold;

    /**
     * @var int Seconds to wait before transitioning from OPEN to HALF_OPEN
     */
    private int $recoveryTimeout;

    /**
     * @var int Number of successful calls needed to close circuit from HALF_OPEN
     */
    private int $successThreshold;

    /**
     * @var int Success count in HALF_OPEN state
     */
    private int $halfOpenSuccesses = 0;

    /**
     * @var CacheInterface|null Optional cache for state persistence
     */
    private ?CacheInterface $cache;

    /**
     * @var LoggerInterface Logger instance
     */
    private LoggerInterface $logger;

    /**
     * @var string Cache key for this circuit breaker instance
     */
    private string $cacheKey;

    /**
     * Constructor.
     *
     * @param string $name Circuit breaker name (for cache key)
     * @param int $failureThreshold Number of failures to open circuit
     * @param int $recoveryTimeout Seconds to wait before trying recovery
     * @param int $successThreshold Successes needed to close circuit
     * @param CacheInterface|null $cache Optional cache for persistence
     * @param LoggerInterface|null $logger Optional logger
     */
    public function __construct(
        string $name = 'default',
        int $failureThreshold = 5,
        int $recoveryTimeout = 30,
        int $successThreshold = 2,
        ?CacheInterface $cache = null,
        ?LoggerInterface $logger = null
    ) {
        $this->failureThreshold = $failureThreshold;
        $this->recoveryTimeout = $recoveryTimeout;
        $this->successThreshold = $successThreshold;
        $this->cache = $cache;
        $this->logger = $logger ?? new NullLogger();
        $this->cacheKey = self::CACHE_KEY_PREFIX . $name . ':state';

        // Load state from cache if available
        $this->loadState();
    }

    /**
     * Check if a call should be allowed through the circuit.
     *
     * @return bool True if call should proceed, false if circuit is open
     */
    public function shouldExecute(): bool
    {
        $this->updateStateIfNeeded();

        return $this->state !== CircuitState::OPEN;
    }

    /**
     * Record a successful call.
     *
     * @return void
     */
    public function recordSuccess(): void
    {
        match ($this->state) {
            CircuitState::CLOSED => $this->onClosedSuccess(),
            CircuitState::HALF_OPEN => $this->onHalfOpenSuccess(),
            CircuitState::OPEN => null, // No-op in OPEN state
        };

        $this->saveState();
    }

    /**
     * Record a failed call.
     *
     * @return void
     */
    public function recordFailure(): void
    {
        match ($this->state) {
            CircuitState::CLOSED => $this->onClosedFailure(),
            CircuitState::HALF_OPEN => $this->onHalfOpenFailure(),
            CircuitState::OPEN => null, // No-op in OPEN state
        };

        $this->saveState();
    }

    /**
     * Get current circuit state.
     *
     * @return CircuitState Current state
     */
    public function getState(): CircuitState
    {
        $this->updateStateIfNeeded();

        return $this->state;
    }

    /**
     * Get current failure count.
     *
     * @return int Failure count
     */
    public function getFailureCount(): int
    {
        return $this->failures;
    }

    /**
     * Get recovery timeout in seconds.
     *
     * @return int Recovery timeout
     */
    public function getRecoveryTimeout(): int
    {
        return $this->recoveryTimeout;
    }

    /**
     * Handle success in CLOSED state.
     *
     * @return void
     */
    private function onClosedSuccess(): void
    {
        // Reset failure count on success
        if ($this->failures > 0) {
            $this->failures = 0;
            $this->logger->debug('Circuit breaker: Reset failure count on success');
        }
    }

    /**
     * Handle failure in CLOSED state.
     *
     * @return void
     */
    private function onClosedFailure(): void
    {
        $this->failures++;
        $this->logger->debug("Circuit breaker: Failure recorded, count: {$this->failures}");

        if ($this->failures >= $this->failureThreshold) {
            $this->transitionToOpen();
        }
    }

    /**
     * Handle success in HALF_OPEN state.
     *
     * @return void
     */
    private function onHalfOpenSuccess(): void
    {
        $this->halfOpenSuccesses++;
        $this->logger->debug("Circuit breaker: Half-open success, count: {$this->halfOpenSuccesses}");

        if ($this->halfOpenSuccesses >= $this->successThreshold) {
            $this->transitionToClosed();
        }
    }

    /**
     * Handle failure in HALF_OPEN state.
     *
     * @return void
     */
    private function onHalfOpenFailure(): void
    {
        $this->logger->debug('Circuit breaker: Half-open failure, returning to OPEN');
        $this->transitionToOpen();
    }

    /**
     * Transition circuit to OPEN state.
     *
     * @return void
     */
    private function transitionToOpen(): void
    {
        $this->state = CircuitState::OPEN;
        $this->openedAt = time();
        $this->halfOpenSuccesses = 0;
        $this->logger->warning("Circuit breaker: Transitioned to OPEN state after {$this->failures} failures");
    }

    /**
     * Transition circuit to CLOSED state.
     *
     * @return void
     */
    private function transitionToClosed(): void
    {
        $this->state = CircuitState::CLOSED;
        $this->failures = 0;
        $this->openedAt = null;
        $this->halfOpenSuccesses = 0;
        $this->logger->info('Circuit breaker: Transitioned to CLOSED state - service recovered');
    }

    /**
     * Transition circuit to HALF_OPEN state.
     *
     * @return void
     */
    private function transitionToHalfOpen(): void
    {
        $this->state = CircuitState::HALF_OPEN;
        $this->halfOpenSuccesses = 0;
        $this->logger->info('Circuit breaker: Transitioned to HALF_OPEN state - testing recovery');
    }

    /**
     * Update circuit state based on time and current conditions.
     *
     * @return void
     */
    private function updateStateIfNeeded(): void
    {
        if ($this->state === CircuitState::OPEN && $this->shouldAttemptRecovery()) {
            $this->transitionToHalfOpen();
        }
    }

    /**
     * Check if circuit should attempt recovery from OPEN state.
     *
     * @return bool True if recovery should be attempted
     */
    private function shouldAttemptRecovery(): bool
    {
        return $this->openedAt !== null &&
               (time() - $this->openedAt) >= $this->recoveryTimeout;
    }

    /**
     * Load circuit state from cache.
     *
     * @return void
     */
    private function loadState(): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            $data = $this->cache->get($this->cacheKey);
            if (is_array($data)) {
                $this->state = CircuitState::from($data['state'] ?? 'closed');
                $this->failures = $data['failures'] ?? 0;
                $this->openedAt = $data['opened_at'] ?? null;
                $this->halfOpenSuccesses = $data['half_open_successes'] ?? 0;
            }
        } catch (\Throwable $e) {
            $this->logger->warning("Circuit breaker: Failed to load state from cache: {$e->getMessage()}");
        }
    }

    /**
     * Save circuit state to cache.
     *
     * @return void
     */
    private function saveState(): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            $data = [
                'state' => $this->state->value,
                'failures' => $this->failures,
                'opened_at' => $this->openedAt,
                'half_open_successes' => $this->halfOpenSuccesses,
            ];

            $this->cache->set($this->cacheKey, $data, $this->recoveryTimeout * 2);
        } catch (\Throwable $e) {
            $this->logger->warning("Circuit breaker: Failed to save state to cache: {$e->getMessage()}");
        }
    }
}
