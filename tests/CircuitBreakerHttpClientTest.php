<?php

declare(strict_types=1);

namespace Swotto\Tests;

use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Swotto\CircuitBreaker\CircuitBreaker;
use Swotto\CircuitBreaker\CircuitBreakerHttpClient;
use Swotto\Contract\HttpClientInterface;
use Swotto\Exception\CircuitBreakerOpenException;

/**
 * CircuitBreakerHttpClientTest.
 *
 * Basic unit tests for CircuitBreakerHttpClient decorator
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
}
