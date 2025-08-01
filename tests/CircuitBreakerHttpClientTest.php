<?php

declare(strict_types=1);

namespace Swotto\Tests;

use PHPUnit\Framework\TestCase;
use Mockery;
use Swotto\CircuitBreaker\CircuitBreaker;
use Swotto\CircuitBreaker\CircuitBreakerHttpClient;
use Swotto\Contract\HttpClientInterface;
use Swotto\Exception\CircuitBreakerOpenException;
use Swotto\Exception\ApiException;
use Psr\Log\NullLogger;

/**
 * CircuitBreakerHttpClientTest.
 *
 * Unit tests for CircuitBreakerHttpClient decorator
 */
class CircuitBreakerHttpClientTest extends TestCase
{
    private HttpClientInterface $mockClient;
    private CircuitBreaker $circuitBreaker;
    private CircuitBreakerHttpClient $circuitBreakerClient;

    protected function setUp(): void
    {
        $this->mockClient = Mockery::mock(HttpClientInterface::class);
        $this->circuitBreaker = new CircuitBreaker(
            name: 'test',
            failureThreshold: 2,
            recoveryTimeout: 5,
            successThreshold: 2,
            cache: null,
            logger: new NullLogger()
        );
        $this->circuitBreakerClient = new CircuitBreakerHttpClient(
            $this->mockClient,
            $this->circuitBreaker
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testInitializeDelegatesToDecoratedClient(): void
    {
        $config = ['test' => 'value'];
        
        $this->mockClient
            ->shouldReceive('initialize')
            ->once()
            ->with($config);

        $this->circuitBreakerClient->initialize($config);
        
        // Add assertion to make test not risky
        $this->assertTrue(true);
    }

    public function testSuccessfulRequestRecordsSuccess(): void
    {
        $expectedResponse = ['data' => 'test'];
        
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/test', [])
            ->andReturn($expectedResponse);

        $response = $this->circuitBreakerClient->request('GET', '/test', []);

        $this->assertEquals($expectedResponse, $response);
        $this->assertEquals(0, $this->circuitBreaker->getFailureCount());
    }

    public function testFailedRequestRecordsFailure(): void
    {
        $exception = new ApiException('Test error', [], 500);
        
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/test', [])
            ->andThrow($exception);

        $this->expectException(ApiException::class);
        
        try {
            $this->circuitBreakerClient->request('GET', '/test', []);
        } finally {
            $this->assertEquals(1, $this->circuitBreaker->getFailureCount());
        }
    }

    public function testCircuitOpenPreventsRequests(): void
    {
        // Force circuit to open by recording failures
        $this->circuitBreaker->recordFailure();
        $this->circuitBreaker->recordFailure();

        $this->expectException(CircuitBreakerOpenException::class);
        $this->expectExceptionMessage('Circuit breaker is OPEN - API temporarily unavailable');

        // Mock client should not be called
        $this->mockClient
            ->shouldNotReceive('request');

        $this->circuitBreakerClient->request('GET', '/test', []);
    }

    public function testMultipleFailuresOpenCircuit(): void
    {
        $exception = new ApiException('Test error', [], 500);
        
        // First failure
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->andThrow($exception);

        try {
            $this->circuitBreakerClient->request('GET', '/test1', []);
        } catch (ApiException $e) {
            // Expected
        }

        // Second failure should open circuit
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->andThrow($exception);

        try {
            $this->circuitBreakerClient->request('GET', '/test2', []);
        } catch (ApiException $e) {
            // Expected
        }

        // Third request should fail fast without hitting decorated client
        $this->mockClient
            ->shouldNotReceive('request');

        $this->expectException(CircuitBreakerOpenException::class);
        $this->circuitBreakerClient->request('GET', '/test3', []);
    }

    public function testSuccessAfterFailureResetsCount(): void
    {
        $exception = new ApiException('Test error', [], 500);
        $successResponse = ['data' => 'success'];
        
        // Record one failure
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->andThrow($exception);

        try {
            $this->circuitBreakerClient->request('GET', '/test-fail', []);
        } catch (ApiException $e) {
            // Expected
        }

        $this->assertEquals(1, $this->circuitBreaker->getFailureCount());

        // Record success - should reset failure count
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->andReturn($successResponse);

        $response = $this->circuitBreakerClient->request('GET', '/test-success', []);

        $this->assertEquals($successResponse, $response);
        $this->assertEquals(0, $this->circuitBreaker->getFailureCount());
    }
}