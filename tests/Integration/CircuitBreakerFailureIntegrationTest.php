<?php

declare(strict_types=1);

namespace Swotto\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Swotto\Client;
use Swotto\Exception\CircuitBreakerOpenException;
use Swotto\Exception\NetworkException;
use Swotto\CircuitBreaker\CircuitBreakerHttpClient;
use Swotto\CircuitBreaker\CircuitState;

/**
 * CircuitBreakerFailureIntegrationTest.
 *
 * Integration tests for Circuit Breaker failure handling and state transitions
 */
class CircuitBreakerFailureIntegrationTest extends TestCase
{
    /**
     * Test that circuit breaker opens after consecutive failures.
     */
    public function testCircuitBreakerOpensAfterConsecutiveFailures(): void
    {
        $client = new Client([
            'url' => 'http://nonexistent-api-test.invalid', // This will always fail
            'circuit_breaker_enabled' => true,
            'circuit_breaker_failure_threshold' => 2, // Low threshold for testing
            'circuit_breaker_recovery_timeout' => 5,
        ]);

        // Get circuit breaker for state inspection
        $httpClient = $this->getHttpClientFromClient($client);
        $this->assertInstanceOf(CircuitBreakerHttpClient::class, $httpClient);
        
        $circuitBreaker = $this->getCircuitBreakerFromHttpClient($httpClient);
        
        // Initial state should be CLOSED
        $this->assertEquals(CircuitState::CLOSED, $circuitBreaker->getState());
        $this->assertEquals(0, $circuitBreaker->getFailureCount());
        $this->assertTrue($circuitBreaker->shouldExecute());

        // First failure
        try {
            $client->get('test-endpoint');
            $this->fail('Expected NetworkException for first failure');
        } catch (NetworkException $e) {
            // Expected network exception
            $this->assertEquals(CircuitState::CLOSED, $circuitBreaker->getState());
            $this->assertEquals(1, $circuitBreaker->getFailureCount());
        }

        // Second failure - should trigger circuit breaker to OPEN
        try {
            $client->get('test-endpoint');
            $this->fail('Expected NetworkException for second failure');
        } catch (NetworkException $e) {
            // Expected network exception, CB should now be OPEN
            $this->assertEquals(CircuitState::OPEN, $circuitBreaker->getState());
            $this->assertEquals(2, $circuitBreaker->getFailureCount());
        }

        // Third attempt - should be blocked by circuit breaker
        $this->expectException(CircuitBreakerOpenException::class);
        $this->expectExceptionMessage('Circuit breaker is OPEN - API temporarily unavailable');
        $this->expectExceptionCode(503);
        
        $client->get('test-endpoint');
    }

    /**
     * Test that circuit breaker blocks requests when open.
     */
    public function testCircuitBreakerBlocksRequestsWhenOpen(): void
    {
        $client = new Client([
            'url' => 'http://nonexistent-api-test.invalid',
            'circuit_breaker_enabled' => true,
            'circuit_breaker_failure_threshold' => 1, // Very low threshold
            'circuit_breaker_recovery_timeout' => 10,
        ]);

        // Force circuit breaker to open with one failure
        try {
            $client->get('test-endpoint');
        } catch (NetworkException $e) {
            // Expected failure
        }

        // Verify CB is open
        $httpClient = $this->getHttpClientFromClient($client);
        $circuitBreaker = $this->getCircuitBreakerFromHttpClient($httpClient);
        $this->assertEquals(CircuitState::OPEN, $circuitBreaker->getState());

        // All subsequent requests should be blocked immediately
        for ($i = 0; $i < 3; $i++) {
            try {
                $client->get("test-endpoint-$i");
                $this->fail("Request $i should have been blocked by circuit breaker");
            } catch (CircuitBreakerOpenException $e) {
                $this->assertEquals(503, $e->getCode());
                $this->assertStringContainsString('Circuit breaker is OPEN', $e->getMessage());
            }
        }

        // State should remain OPEN and failure count unchanged
        $this->assertEquals(CircuitState::OPEN, $circuitBreaker->getState());
        $this->assertEquals(1, $circuitBreaker->getFailureCount());
    }

    /**
     * Test that circuit breaker transitions to HALF_OPEN after recovery timeout.
     */
    public function testCircuitBreakerRecoveryAfterTimeout(): void
    {
        $client = new Client([
            'url' => 'http://nonexistent-api-test.invalid',
            'circuit_breaker_enabled' => true,
            'circuit_breaker_failure_threshold' => 1,
            'circuit_breaker_recovery_timeout' => 1, // Very short timeout for testing
        ]);

        // Force circuit breaker to open
        try {
            $client->get('test-endpoint');
        } catch (NetworkException $e) {
            // Expected failure
        }

        $httpClient = $this->getHttpClientFromClient($client);
        $circuitBreaker = $this->getCircuitBreakerFromHttpClient($httpClient);
        
        // Verify CB is open
        $this->assertEquals(CircuitState::OPEN, $circuitBreaker->getState());
        $this->assertFalse($circuitBreaker->shouldExecute());

        // Wait for recovery timeout
        sleep(2); // Wait longer than recovery timeout

        // Check state - should transition to HALF_OPEN when shouldExecute() is called
        $this->assertTrue($circuitBreaker->shouldExecute());
        $this->assertEquals(CircuitState::HALF_OPEN, $circuitBreaker->getState());

        // Next request should be allowed through (but will still fail due to invalid URL)
        try {
            $client->get('test-endpoint');
            $this->fail('Expected NetworkException in HALF_OPEN state');
        } catch (NetworkException $e) {
            // Expected failure - should transition back to OPEN
            $this->assertEquals(CircuitState::OPEN, $circuitBreaker->getState());
        }
    }

    /**
     * Test circuit breaker with different failure thresholds.
     */
    public function testDifferentFailureThresholds(): void
    {
        $testCases = [
            ['threshold' => 1, 'expectedFailures' => 1],
            ['threshold' => 3, 'expectedFailures' => 3],
            ['threshold' => 5, 'expectedFailures' => 5],
        ];

        foreach ($testCases as $case) {
            $client = new Client([
                'url' => 'http://nonexistent-api-test.invalid',
                'circuit_breaker_enabled' => true,
                'circuit_breaker_failure_threshold' => $case['threshold'],
                'circuit_breaker_recovery_timeout' => 10,
            ]);

            $httpClient = $this->getHttpClientFromClient($client);
            $circuitBreaker = $this->getCircuitBreakerFromHttpClient($httpClient);

            // Generate failures up to threshold - 1
            for ($i = 0; $i < $case['threshold'] - 1; $i++) {
                try {
                    $client->get("test-endpoint-$i");
                } catch (NetworkException $e) {
                    // Expected
                }
                
                // Should still be CLOSED
                $this->assertEquals(
                    CircuitState::CLOSED,
                    $circuitBreaker->getState(),
                    "CB should be CLOSED after " . ($i + 1) . " failures with threshold " . $case['threshold']
                );
            }

            // One more failure should open the circuit
            try {
                $client->get('final-test-endpoint');
            } catch (NetworkException $e) {
                // Expected
            }

            $this->assertEquals(
                CircuitState::OPEN,
                $circuitBreaker->getState(),
                "CB should be OPEN after {$case['threshold']} failures"
            );
            
            $this->assertEquals(
                $case['expectedFailures'],
                $circuitBreaker->getFailureCount(),
                "Failure count should match threshold"
            );
        }
    }

    /**
     * Test that successful requests reset failure count in CLOSED state.
     */
    public function testSuccessfulRequestsResetFailureCount(): void
    {
        // Skip this test since it requires external HTTP endpoint
        $this->markTestSkipped('Test requires external HTTP endpoint - would make tests flaky');
    }

    /**
     * Extract HttpClient from Client using reflection.
     */
    private function getHttpClientFromClient(Client $client): CircuitBreakerHttpClient
    {
        $reflection = new \ReflectionClass($client);
        $httpClientProperty = $reflection->getProperty('httpClient');
        $httpClientProperty->setAccessible(true);
        
        return $httpClientProperty->getValue($client);
    }

    /**
     * Extract CircuitBreaker from CircuitBreakerHttpClient using reflection.
     */
    private function getCircuitBreakerFromHttpClient(CircuitBreakerHttpClient $httpClient): \Swotto\CircuitBreaker\CircuitBreaker
    {
        $reflection = new \ReflectionClass($httpClient);
        $circuitBreakerProperty = $reflection->getProperty('circuitBreaker');
        $circuitBreakerProperty->setAccessible(true);
        
        return $circuitBreakerProperty->getValue($httpClient);
    }

    /**
     * Get base URL from Client configuration using reflection.
     */
    private function getUrlFromClient(Client $client): string
    {
        $reflection = new \ReflectionClass($client);
        $configProperty = $reflection->getProperty('config');
        $configProperty->setAccessible(true);
        $config = $configProperty->getValue($client);
        
        return $config->getBaseUrl();
    }

    /**
     * Set base URL on Client configuration using reflection.
     */
    private function setUrlOnClient(Client $client, string $newUrl): void
    {
        $reflection = new \ReflectionClass($client);
        $configProperty = $reflection->getProperty('config');
        $configProperty->setAccessible(true);
        $config = $configProperty->getValue($client);
        
        // Update config with new URL
        $newConfig = $config->update(['url' => $newUrl]);
        $configProperty->setValue($client, $newConfig);
        
        // Also update the HttpClient's configuration
        $httpClientProperty = $reflection->getProperty('httpClient');
        $httpClientProperty->setAccessible(true);
        $httpClient = $httpClientProperty->getValue($client);
        
        if ($httpClient instanceof CircuitBreakerHttpClient) {
            // Update the decorated client's configuration
            $decoratedReflection = new \ReflectionClass($httpClient);
            $decoratedProperty = $decoratedReflection->getProperty('decoratedClient');
            $decoratedProperty->setAccessible(true);
            $decoratedClient = $decoratedProperty->getValue($httpClient);
            
            $decoratedClient->initialize($newConfig->toArray());
        }
    }
}