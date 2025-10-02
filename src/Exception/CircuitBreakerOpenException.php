<?php

declare(strict_types=1);

namespace Swotto\Exception;

/**
 * CircuitBreakerOpenException.
 *
 * Thrown when circuit breaker is in OPEN state and requests are failing fast
 */
class CircuitBreakerOpenException extends SwottoException
{
    /**
     * @var int Seconds to wait before circuit might recover
     */
    private int $retryAfter;

    /**
     * Constructor.
     *
     * @param string $message Error message
     * @param int $retryAfter Seconds to wait before retrying
     * @param int $code Error code
     */
    public function __construct(
        string $message = 'Circuit breaker is OPEN - service temporarily unavailable',
        int $retryAfter = 30,
        int $code = 503
    ) {
        parent::__construct($message, [], $code);
        $this->retryAfter = $retryAfter;
    }

    /**
     * Get seconds to wait before retrying.
     *
     * @return int Seconds to wait
     */
    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }

    /**
     * {@inheritdoc}
     */
    public function getErrorData(): array
    {
        return array_merge(
            parent::getErrorData(),
            [
                'retry_after' => $this->retryAfter,
                'circuit_breaker_state' => 'open',
            ]
        );
    }
}
