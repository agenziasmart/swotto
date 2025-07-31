<?php

declare(strict_types=1);

namespace Swotto\Tests;

use PHPUnit\Framework\TestCase;
use Swotto\CircuitBreaker\CircuitBreaker;
use Swotto\CircuitBreaker\CircuitState;
use Psr\Log\NullLogger;

/**
 * CircuitBreakerTest.
 *
 * Unit tests for CircuitBreaker class
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

    public function testTransitionToHalfOpenAfterRecoveryTimeout(): void
    {
        // Create a circuit breaker with short recovery timeout for testing
        $circuitBreaker = new CircuitBreaker(
            name: 'test-recovery',
            failureThreshold: 2,
            recoveryTimeout: 1, // 1 second
            successThreshold: 2,
            cache: null,
            logger: new NullLogger()
        );

        // Force transition to OPEN
        $circuitBreaker->recordFailure();
        $circuitBreaker->recordFailure();
        $this->assertEquals(CircuitState::OPEN, $circuitBreaker->getState());
        $this->assertFalse($circuitBreaker->shouldExecute());

        // Wait for recovery timeout and check transition to HALF_OPEN
        sleep(2); // Wait longer than recovery timeout
        
        // Should transition to HALF_OPEN when checked
        $this->assertTrue($circuitBreaker->shouldExecute());
        $this->assertEquals(CircuitState::HALF_OPEN, $circuitBreaker->getState());
    }

    public function testTransitionToClosedFromHalfOpenAfterSuccesses(): void
    {
        // Create a circuit breaker and force it through the states naturally
        $circuitBreaker = new CircuitBreaker(
            name: 'test-half-open',
            failureThreshold: 2,
            recoveryTimeout: 1,
            successThreshold: 2,
            cache: null,
            logger: new NullLogger()
        );

        // Force to OPEN state
        $circuitBreaker->recordFailure();
        $circuitBreaker->recordFailure();
        $this->assertEquals(CircuitState::OPEN, $circuitBreaker->getState());

        // Wait and transition to HALF_OPEN
        sleep(2);
        $circuitBreaker->shouldExecute(); // This triggers transition to HALF_OPEN
        $this->assertEquals(CircuitState::HALF_OPEN, $circuitBreaker->getState());

        // Record successes to close circuit
        $circuitBreaker->recordSuccess();
        $this->assertEquals(CircuitState::HALF_OPEN, $circuitBreaker->getState());

        $circuitBreaker->recordSuccess();
        $this->assertEquals(CircuitState::CLOSED, $circuitBreaker->getState());
    }

    public function testTransitionToOpenFromHalfOpenOnFailure(): void
    {
        // Create a circuit breaker and get it to HALF_OPEN state
        $circuitBreaker = new CircuitBreaker(
            name: 'test-half-open-fail',
            failureThreshold: 2,
            recoveryTimeout: 1,
            successThreshold: 2,
            cache: null,
            logger: new NullLogger()
        );

        // Force to OPEN state
        $circuitBreaker->recordFailure();
        $circuitBreaker->recordFailure();
        $this->assertEquals(CircuitState::OPEN, $circuitBreaker->getState());

        // Wait and transition to HALF_OPEN
        sleep(2);
        $circuitBreaker->shouldExecute(); // This triggers transition to HALF_OPEN
        $this->assertEquals(CircuitState::HALF_OPEN, $circuitBreaker->getState());

        // Single failure should return to OPEN
        $circuitBreaker->recordFailure();
        $this->assertEquals(CircuitState::OPEN, $circuitBreaker->getState());
        $this->assertFalse($circuitBreaker->shouldExecute());
    }
}