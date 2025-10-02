<?php

declare(strict_types=1);

namespace Swotto\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Swotto\CircuitBreaker\CircuitBreaker;
use Swotto\CircuitBreaker\CircuitState;

/**
 * CircuitBreakerTest.
 *
 * Basic unit tests for CircuitBreaker pattern
 */
class CircuitBreakerTest extends TestCase
{
    private CircuitBreaker $circuitBreaker;

    protected function setUp(): void
    {
        $this->circuitBreaker = new CircuitBreaker(
            name: 'test',
            failureThreshold: 3,
            recoveryTimeout: 5,
            successThreshold: 2,
            cache: null,
            logger: new NullLogger()
        );
    }

    public function testInitialStateClosed(): void
    {
        $this->assertEquals(CircuitState::CLOSED, $this->circuitBreaker->getState());
        $this->assertTrue($this->circuitBreaker->shouldExecute());
        $this->assertEquals(0, $this->circuitBreaker->getFailureCount());
    }

    public function testFailureCountIncrementsInClosedState(): void
    {
        $this->circuitBreaker->recordFailure();
        $this->assertEquals(1, $this->circuitBreaker->getFailureCount());
        $this->assertEquals(CircuitState::CLOSED, $this->circuitBreaker->getState());

        $this->circuitBreaker->recordFailure();
        $this->assertEquals(2, $this->circuitBreaker->getFailureCount());
        $this->assertEquals(CircuitState::CLOSED, $this->circuitBreaker->getState());
    }

    public function testTransitionToOpenAfterThresholdFailures(): void
    {
        // Record failures up to threshold
        $this->circuitBreaker->recordFailure();
        $this->circuitBreaker->recordFailure();
        $this->circuitBreaker->recordFailure();

        // Should transition to OPEN
        $this->assertEquals(CircuitState::OPEN, $this->circuitBreaker->getState());
        $this->assertFalse($this->circuitBreaker->shouldExecute());
    }

    public function testSuccessResetsFailureCountInClosedState(): void
    {
        $this->circuitBreaker->recordFailure();
        $this->circuitBreaker->recordFailure();
        $this->assertEquals(2, $this->circuitBreaker->getFailureCount());

        $this->circuitBreaker->recordSuccess();
        $this->assertEquals(0, $this->circuitBreaker->getFailureCount());
        $this->assertEquals(CircuitState::CLOSED, $this->circuitBreaker->getState());
    }
}
