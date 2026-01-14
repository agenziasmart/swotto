<?php

declare(strict_types=1);

namespace Swotto\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Swotto\CircuitBreaker\CircuitBreaker;
use Swotto\CircuitBreaker\CircuitState;

/**
 * CircuitBreakerStateTest.
 *
 * Test CircuitBreaker state transitions and cache persistence.
 */
class CircuitBreakerStateTest extends TestCase
{
    private LoggerInterface $mockLogger;

    protected function setUp(): void
    {
        $this->mockLogger = $this->createMock(LoggerInterface::class);
    }

    // ========== State Transition Tests ==========

    /**
     * Test initial state is CLOSED.
     */
    public function testInitialStateIsClosed(): void
    {
        $cb = new CircuitBreaker(
            'test',
            5,
            30,
            2,
            null,
            $this->mockLogger
        );

        $this->assertEquals(CircuitState::CLOSED, $cb->getState());
        $this->assertEquals(0, $cb->getFailureCount());
    }

    /**
     * Test CLOSED → OPEN transition after failure threshold.
     */
    public function testClosedToOpenTransition(): void
    {
        $failureThreshold = 3;
        $cb = new CircuitBreaker(
            'test',
            $failureThreshold,
            30,
            2,
            null,
            $this->mockLogger
        );

        // Record failures until threshold
        for ($i = 0; $i < $failureThreshold; $i++) {
            $this->assertEquals(CircuitState::CLOSED, $cb->getState());
            $cb->recordFailure();
        }

        // After threshold, should be OPEN
        $this->assertEquals(CircuitState::OPEN, $cb->getState());
        $this->assertFalse($cb->shouldExecute());
    }

    /**
     * Test success resets failure count in CLOSED state.
     */
    public function testSuccessResetsFailureCount(): void
    {
        $cb = new CircuitBreaker(
            'test',
            5,
            30,
            2,
            null,
            $this->mockLogger
        );

        // Record some failures
        $cb->recordFailure();
        $cb->recordFailure();
        $this->assertEquals(2, $cb->getFailureCount());

        // Record success
        $cb->recordSuccess();
        $this->assertEquals(0, $cb->getFailureCount());
        $this->assertEquals(CircuitState::CLOSED, $cb->getState());
    }

    /**
     * Test OPEN → HALF_OPEN transition after recovery timeout.
     */
    public function testOpenToHalfOpenTransition(): void
    {
        $recoveryTimeout = 1; // 1 second for testing
        $cb = new CircuitBreaker(
            'test',
            2,
            $recoveryTimeout,
            2,
            null,
            $this->mockLogger
        );

        // Force OPEN state
        $cb->recordFailure();
        $cb->recordFailure();
        $this->assertEquals(CircuitState::OPEN, $cb->getState());

        // Wait for recovery timeout
        sleep($recoveryTimeout + 1);

        // Should transition to HALF_OPEN when checked
        $this->assertEquals(CircuitState::HALF_OPEN, $cb->getState());
        $this->assertTrue($cb->shouldExecute());
    }

    /**
     * Test HALF_OPEN → CLOSED transition after success threshold.
     */
    public function testHalfOpenToClosedTransition(): void
    {
        $recoveryTimeout = 1;
        $successThreshold = 2;
        $cb = new CircuitBreaker(
            'test',
            2,
            $recoveryTimeout,
            $successThreshold,
            null,
            $this->mockLogger
        );

        // Force OPEN state
        $cb->recordFailure();
        $cb->recordFailure();

        // Wait for recovery timeout to enter HALF_OPEN
        sleep($recoveryTimeout + 1);
        $this->assertEquals(CircuitState::HALF_OPEN, $cb->getState());

        // Record successes to close circuit
        for ($i = 0; $i < $successThreshold; $i++) {
            $cb->recordSuccess();
        }

        $this->assertEquals(CircuitState::CLOSED, $cb->getState());
        $this->assertEquals(0, $cb->getFailureCount());
    }

    /**
     * Test HALF_OPEN → OPEN transition on failure.
     */
    public function testHalfOpenToOpenOnFailure(): void
    {
        $recoveryTimeout = 1;
        $cb = new CircuitBreaker(
            'test',
            2,
            $recoveryTimeout,
            2,
            null,
            $this->mockLogger
        );

        // Force OPEN state
        $cb->recordFailure();
        $cb->recordFailure();

        // Wait for recovery timeout to enter HALF_OPEN
        sleep($recoveryTimeout + 1);
        $this->assertEquals(CircuitState::HALF_OPEN, $cb->getState());

        // Single failure in HALF_OPEN returns to OPEN
        $cb->recordFailure();
        $this->assertEquals(CircuitState::OPEN, $cb->getState());
    }

    /**
     * Test shouldExecute returns false in OPEN state.
     */
    public function testShouldExecuteFalseInOpenState(): void
    {
        $cb = new CircuitBreaker(
            'test',
            2,
            60, // Long timeout
            2,
            null,
            $this->mockLogger
        );

        // Force OPEN state
        $cb->recordFailure();
        $cb->recordFailure();

        $this->assertFalse($cb->shouldExecute());
    }

    /**
     * Test shouldExecute returns true in CLOSED state.
     */
    public function testShouldExecuteTrueInClosedState(): void
    {
        $cb = new CircuitBreaker(
            'test',
            5,
            30,
            2,
            null,
            $this->mockLogger
        );

        $this->assertTrue($cb->shouldExecute());
    }

    // ========== Cache Persistence Tests ==========

    /**
     * Test state is saved to cache.
     */
    public function testStateSavedToCache(): void
    {
        $mockCache = $this->createMock(CacheInterface::class);

        // Cache should receive 'set' call for each state change
        $mockCache->expects($this->atLeastOnce())
            ->method('set')
            ->with(
                $this->stringContains('swotto:cb:test:state'),
                $this->callback(function ($data) {
                    return is_array($data) &&
                           isset($data['state']) &&
                           isset($data['failures']);
                }),
                $this->anything()
            );

        // Cache get returns null initially
        $mockCache->method('get')->willReturn(null);

        $cb = new CircuitBreaker(
            'test',
            5,
            30,
            2,
            $mockCache,
            $this->mockLogger
        );

        $cb->recordFailure();
    }

    /**
     * Test state is loaded from cache.
     */
    public function testStateLoadedFromCache(): void
    {
        $mockCache = $this->createMock(CacheInterface::class);

        // Cache returns saved state
        $mockCache->method('get')
            ->willReturn([
                'state' => 'open',
                'failures' => 5,
                'opened_at' => time() - 10, // Opened 10 seconds ago
                'half_open_successes' => 0,
            ]);

        $cb = new CircuitBreaker(
            'test',
            5,
            60, // Long timeout
            2,
            $mockCache,
            $this->mockLogger
        );

        // Should be OPEN from cached state
        $this->assertEquals(CircuitState::OPEN, $cb->getState());
        $this->assertEquals(5, $cb->getFailureCount());
    }

    /**
     * Test cache error is handled gracefully on load.
     */
    public function testCacheLoadErrorHandledGracefully(): void
    {
        $mockCache = $this->createMock(CacheInterface::class);

        // Cache throws exception
        $mockCache->method('get')
            ->willThrowException(new \Exception('Cache error'));

        // Should not throw, defaults to CLOSED
        $cb = new CircuitBreaker(
            'test',
            5,
            30,
            2,
            $mockCache,
            $this->mockLogger
        );

        $this->assertEquals(CircuitState::CLOSED, $cb->getState());
    }

    /**
     * Test cache error is handled gracefully on save.
     */
    public function testCacheSaveErrorHandledGracefully(): void
    {
        $mockCache = $this->createMock(CacheInterface::class);

        $mockCache->method('get')->willReturn(null);
        $mockCache->method('set')
            ->willThrowException(new \Exception('Cache write error'));

        // Should not throw
        $cb = new CircuitBreaker(
            'test',
            5,
            30,
            2,
            $mockCache,
            $this->mockLogger
        );

        $cb->recordFailure();

        $this->assertEquals(1, $cb->getFailureCount());
    }

    /**
     * Test no cache operations when cache is null.
     */
    public function testNoCacheOperationsWhenNull(): void
    {
        $cb = new CircuitBreaker(
            'test',
            5,
            30,
            2,
            null, // No cache
            $this->mockLogger
        );

        // Should work without cache
        $cb->recordFailure();
        $cb->recordFailure();
        $cb->recordSuccess();

        $this->assertEquals(CircuitState::CLOSED, $cb->getState());
    }

    // ========== Configuration Tests ==========

    /**
     * Test getRecoveryTimeout returns configured value.
     */
    public function testGetRecoveryTimeout(): void
    {
        $recoveryTimeout = 45;
        $cb = new CircuitBreaker(
            'test',
            5,
            $recoveryTimeout,
            2,
            null,
            $this->mockLogger
        );

        $this->assertEquals($recoveryTimeout, $cb->getRecoveryTimeout());
    }

    /**
     * Test circuit breaker with threshold of 1.
     */
    public function testThresholdOfOne(): void
    {
        $cb = new CircuitBreaker(
            'test',
            1, // Single failure opens circuit
            30,
            1, // Single success closes circuit
            null,
            $this->mockLogger
        );

        $this->assertEquals(CircuitState::CLOSED, $cb->getState());

        // Single failure opens circuit
        $cb->recordFailure();
        $this->assertEquals(CircuitState::OPEN, $cb->getState());
    }

    /**
     * Test multiple circuit breakers with different names.
     */
    public function testMultipleCircuitBreakersIndependent(): void
    {
        $cb1 = new CircuitBreaker('service1', 2, 30, 2, null, $this->mockLogger);
        $cb2 = new CircuitBreaker('service2', 2, 30, 2, null, $this->mockLogger);

        // Open cb1
        $cb1->recordFailure();
        $cb1->recordFailure();

        // cb1 is OPEN, cb2 is still CLOSED
        $this->assertEquals(CircuitState::OPEN, $cb1->getState());
        $this->assertEquals(CircuitState::CLOSED, $cb2->getState());
    }
}
