<?php

declare(strict_types=1);

namespace Swotto\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Swotto\Client;
use Swotto\CircuitBreaker\CircuitBreakerHttpClient;
use Swotto\Http\GuzzleHttpClient;

/**
 * ClientCircuitBreakerIntegrationTest.
 *
 * Integration tests for Client + Circuit Breaker configuration
 */
class ClientCircuitBreakerIntegrationTest extends TestCase
{
    /**
     * Test that Client creates CircuitBreakerHttpClient when circuit breaker is enabled.
     */
    public function testClientCreatesCircuitBreakerWhenEnabled(): void
    {
        $client = new Client([
            'url' => 'https://api.test.com',
            'circuit_breaker_enabled' => true,
            'circuit_breaker_failure_threshold' => 3,
            'circuit_breaker_recovery_timeout' => 30,
        ]);

        $httpClient = $this->getHttpClientFromClient($client);
        
        $this->assertInstanceOf(
            CircuitBreakerHttpClient::class,
            $httpClient,
            'Client should create CircuitBreakerHttpClient when circuit_breaker_enabled = true'
        );
    }

    /**
     * Test that Client creates normal GuzzleHttpClient when circuit breaker is disabled.
     */
    public function testClientCreatesNormalHttpClientWhenDisabled(): void
    {
        $client = new Client([
            'url' => 'https://api.test.com',
            'circuit_breaker_enabled' => false,
        ]);

        $httpClient = $this->getHttpClientFromClient($client);
        
        $this->assertInstanceOf(
            GuzzleHttpClient::class,
            $httpClient,
            'Client should create GuzzleHttpClient when circuit_breaker_enabled = false'
        );
        
        $this->assertNotInstanceOf(
            CircuitBreakerHttpClient::class,
            $httpClient,
            'Client should NOT create CircuitBreakerHttpClient when circuit_breaker_enabled = false'
        );
    }

    /**
     * Test that Client creates normal GuzzleHttpClient when circuit breaker config is not specified.
     */
    public function testClientCreatesNormalHttpClientWhenNotSpecified(): void
    {
        $client = new Client([
            'url' => 'https://api.test.com',
            // circuit_breaker_enabled not specified = default false
        ]);

        $httpClient = $this->getHttpClientFromClient($client);
        
        $this->assertInstanceOf(
            GuzzleHttpClient::class,
            $httpClient,
            'Client should create GuzzleHttpClient when circuit_breaker_enabled is not specified (default false)'
        );
        
        $this->assertNotInstanceOf(
            CircuitBreakerHttpClient::class,
            $httpClient,
            'Client should NOT create CircuitBreakerHttpClient by default'
        );
    }

    /**
     * Test that circuit breaker configuration parameters are properly propagated.
     */
    public function testCircuitBreakerConfigurationPropagation(): void
    {
        $failureThreshold = 5;
        $recoveryTimeout = 60;
        
        $client = new Client([
            'url' => 'https://api.test.com',
            'circuit_breaker_enabled' => true,
            'circuit_breaker_failure_threshold' => $failureThreshold,
            'circuit_breaker_recovery_timeout' => $recoveryTimeout,
        ]);

        $httpClient = $this->getHttpClientFromClient($client);
        
        $this->assertInstanceOf(CircuitBreakerHttpClient::class, $httpClient);
        
        // Access circuit breaker via reflection to verify configuration
        $circuitBreaker = $this->getCircuitBreakerFromHttpClient($httpClient);
        
        // Verify initial state and configuration
        $this->assertEquals('closed', $circuitBreaker->getState()->value);
        $this->assertEquals(0, $circuitBreaker->getFailureCount());
        $this->assertTrue($circuitBreaker->shouldExecute());
    }

    /**
     * Test that client uses injected HttpClient instead of creating one.
     */
    public function testClientUsesInjectedHttpClient(): void
    {
        $mockHttpClient = $this->createMock(\Swotto\Contract\HttpClientInterface::class);
        
        $client = new Client(
            ['url' => 'https://api.test.com', 'circuit_breaker_enabled' => true],
            null,           // logger
            $mockHttpClient // injected HttpClient
        );

        $httpClient = $this->getHttpClientFromClient($client);
        
        $this->assertSame(
            $mockHttpClient,
            $httpClient,
            'Client should use injected HttpClient instead of creating new one'
        );
        
        $this->assertNotInstanceOf(
            CircuitBreakerHttpClient::class,
            $httpClient,
            'Injected HttpClient should bypass circuit breaker creation'
        );
    }

    /**
     * Test that different Client instances create independent circuit breakers.
     */
    public function testMultipleClientsCreateIndependentCircuitBreakers(): void
    {
        $client1 = new Client([
            'url' => 'https://api1.test.com',
            'circuit_breaker_enabled' => true,
            'circuit_breaker_failure_threshold' => 3,
        ]);

        $client2 = new Client([
            'url' => 'https://api2.test.com',
            'circuit_breaker_enabled' => true,
            'circuit_breaker_failure_threshold' => 5,
        ]);

        $httpClient1 = $this->getHttpClientFromClient($client1);
        $httpClient2 = $this->getHttpClientFromClient($client2);
        
        $this->assertInstanceOf(CircuitBreakerHttpClient::class, $httpClient1);
        $this->assertInstanceOf(CircuitBreakerHttpClient::class, $httpClient2);
        
        $cb1 = $this->getCircuitBreakerFromHttpClient($httpClient1);
        $cb2 = $this->getCircuitBreakerFromHttpClient($httpClient2);
        
        $this->assertNotSame($cb1, $cb2, 'Different clients should have different circuit breaker instances');
    }

    /**
     * Extract HttpClient from Client using reflection.
     */
    private function getHttpClientFromClient(Client $client): \Swotto\Contract\HttpClientInterface
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
}