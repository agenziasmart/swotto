<?php

declare(strict_types=1);

namespace Swotto\CircuitBreaker;

/**
 * CircuitState enum.
 *
 * Represents the three possible states of a circuit breaker
 */
enum CircuitState: string
{
    /**
     * Normal operation - requests are allowed through.
     */
    case CLOSED = 'closed';

    /**
     * Failure state - requests fail fast without hitting the service.
     */
    case OPEN = 'open';

    /**
     * Testing recovery - limited requests allowed to test service health.
     */
    case HALF_OPEN = 'half_open';
}
