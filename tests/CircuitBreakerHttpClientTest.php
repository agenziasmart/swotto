<?php

declare(strict_types=1);

namespace Swotto\Tests;

use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Swotto\CircuitBreaker\CircuitBreaker;
use Swotto\CircuitBreaker\CircuitBreakerHttpClient;
use Swotto\Contract\HttpClientInterface;
use Swotto\Exception\ApiException;
use Swotto\Exception\AuthenticationException;
use Swotto\Exception\CircuitBreakerOpenException;
use Swotto\Exception\ConnectionException;
use Swotto\Exception\ForbiddenException;
use Swotto\Exception\NetworkException;
use Swotto\Exception\NotFoundException;
use Swotto\Exception\RateLimitException;
use Swotto\Exception\ValidationException;

/**
 * CircuitBreakerHttpClientTest.
 *
 * Basic unit tests for CircuitBreakerHttpClient decorator
 */
class CircuitBreakerHttpClientTest extends TestCase
{
    /**
     * @var HttpClientInterface&\Mockery\MockInterface
     */
    private HttpClientInterface $mockClient;

    private CircuitBreaker $circuitBreaker;

    private CircuitBreakerHttpClient $circuitBreakerClient;

    protected function setUp(): void
    {
        /** @phpstan-ignore-next-line */
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
            $this->mockClient, // @phpstan-ignore-line
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

    // ========== 4xx CLIENT ERRORS - SHOULD NOT INCREMENT CIRCUIT BREAKER ==========

    public function test401UnauthorizedDoesNotIncrementCircuitBreaker(): void
    {
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/auth', [])
            ->andThrow(new AuthenticationException('Unauthorized', [], 401));

        $this->expectException(AuthenticationException::class);

        try {
            $this->circuitBreakerClient->request('GET', '/auth', []);
        } catch (AuthenticationException $e) {
            // Circuit breaker should NOT increment for 401
            $this->assertEquals(0, $this->circuitBreaker->getFailureCount());
            throw $e;
        }
    }

    public function test403ForbiddenDoesNotIncrementCircuitBreaker(): void
    {
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/admin', [])
            ->andThrow(new ForbiddenException('Forbidden', [], 403));

        $this->expectException(ForbiddenException::class);

        try {
            $this->circuitBreakerClient->request('GET', '/admin', []);
        } catch (ForbiddenException $e) {
            // Circuit breaker should NOT increment for 403
            $this->assertEquals(0, $this->circuitBreaker->getFailureCount());
            throw $e;
        }
    }

    public function test404NotFoundDoesNotIncrementCircuitBreaker(): void
    {
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/not-exists', [])
            ->andThrow(new NotFoundException('Not Found', [], 404));

        $this->expectException(NotFoundException::class);

        try {
            $this->circuitBreakerClient->request('GET', '/not-exists', []);
        } catch (NotFoundException $e) {
            // Circuit breaker should NOT increment for 404
            $this->assertEquals(0, $this->circuitBreaker->getFailureCount());
            throw $e;
        }
    }

    public function test422ValidationDoesNotIncrementCircuitBreaker(): void
    {
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('POST', '/auth/account', [])
            ->andThrow(new ValidationException('Validation failed', ['email' => 'required'], 422));

        $this->expectException(ValidationException::class);

        try {
            $this->circuitBreakerClient->request('POST', '/auth/account', []);
        } catch (ValidationException $e) {
            // Circuit breaker should NOT increment for 422
            $this->assertEquals(0, $this->circuitBreaker->getFailureCount());
            throw $e;
        }
    }

    public function test429RateLimitDoesNotIncrementCircuitBreaker(): void
    {
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/api/resource', [])
            ->andThrow(new RateLimitException('Too Many Requests', [], 60));

        $this->expectException(RateLimitException::class);

        try {
            $this->circuitBreakerClient->request('GET', '/api/resource', []);
        } catch (RateLimitException $e) {
            // Circuit breaker should NOT increment for 429
            $this->assertEquals(0, $this->circuitBreaker->getFailureCount());
            throw $e;
        }
    }

    // ========== 5xx SERVER ERRORS - SHOULD INCREMENT CIRCUIT BREAKER ==========

    public function test500ServerErrorIncrementsCircuitBreaker(): void
    {
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/api/resource', [])
            ->andThrow(new ApiException('Internal Server Error', [], 500));

        $this->expectException(ApiException::class);

        try {
            $this->circuitBreakerClient->request('GET', '/api/resource', []);
        } catch (ApiException $e) {
            // Circuit breaker SHOULD increment for 500
            $this->assertEquals(1, $this->circuitBreaker->getFailureCount());
            throw $e;
        }
    }

    public function test503ServiceUnavailableIncrementsCircuitBreaker(): void
    {
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/api/resource', [])
            ->andThrow(new ApiException('Service Unavailable', [], 503));

        $this->expectException(ApiException::class);

        try {
            $this->circuitBreakerClient->request('GET', '/api/resource', []);
        } catch (ApiException $e) {
            // Circuit breaker SHOULD increment for 503
            $this->assertEquals(1, $this->circuitBreaker->getFailureCount());
            throw $e;
        }
    }

    // ========== NETWORK ERRORS - SHOULD INCREMENT CIRCUIT BREAKER ==========

    public function testNetworkExceptionIncrementsCircuitBreaker(): void
    {
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/api/resource', [])
            ->andThrow(new NetworkException('Network error', [], 0));

        $this->expectException(NetworkException::class);

        try {
            $this->circuitBreakerClient->request('GET', '/api/resource', []);
        } catch (NetworkException $e) {
            // Circuit breaker SHOULD increment for network errors
            $this->assertEquals(1, $this->circuitBreaker->getFailureCount());
            throw $e;
        }
    }

    public function testConnectionExceptionIncrementsCircuitBreaker(): void
    {
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/api/resource', [])
            ->andThrow(new ConnectionException('Connection failed', 'https://api.example.com', [], 0));

        $this->expectException(ConnectionException::class);

        try {
            $this->circuitBreakerClient->request('GET', '/api/resource', []);
        } catch (ConnectionException $e) {
            // Circuit breaker SHOULD increment for connection errors
            $this->assertEquals(1, $this->circuitBreaker->getFailureCount());
            throw $e;
        }
    }

    // ========== MULTIPLE 4xx DO NOT OPEN CIRCUIT ==========

    public function testMultiple4xxErrorsDoNotOpenCircuit(): void
    {
        // First 401
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/auth', [])
            ->andThrow(new AuthenticationException('Unauthorized', [], 401));

        try {
            $this->circuitBreakerClient->request('GET', '/auth', []);
        } catch (AuthenticationException $e) {
            // Expected
        }

        $this->assertEquals(0, $this->circuitBreaker->getFailureCount());

        // Second 422
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('POST', '/data', [])
            ->andThrow(new ValidationException('Validation failed', [], 422));

        try {
            $this->circuitBreakerClient->request('POST', '/data', []);
        } catch (ValidationException $e) {
            // Expected
        }

        // Circuit breaker should still be at 0 - circuit remains CLOSED
        $this->assertEquals(0, $this->circuitBreaker->getFailureCount());
    }

    // ========== MULTIPLE 5xx OPEN CIRCUIT ==========

    public function testMultiple5xxErrorsOpenCircuit(): void
    {
        // First 500 (threshold = 2 in setUp)
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/api/1', [])
            ->andThrow(new ApiException('Internal Server Error', [], 500));

        try {
            $this->circuitBreakerClient->request('GET', '/api/1', []);
        } catch (ApiException $e) {
            // Expected
        }

        $this->assertEquals(1, $this->circuitBreaker->getFailureCount());

        // Second 503 - should OPEN circuit
        $this->mockClient
            ->shouldReceive('request')
            ->once()
            ->with('GET', '/api/2', [])
            ->andThrow(new ApiException('Service Unavailable', [], 503));

        try {
            $this->circuitBreakerClient->request('GET', '/api/2', []);
        } catch (ApiException $e) {
            // Expected
        }

        $this->assertEquals(2, $this->circuitBreaker->getFailureCount());

        // Third request should be blocked by OPEN circuit
        $this->mockClient
            ->shouldNotReceive('request');

        $this->expectException(CircuitBreakerOpenException::class);
        $this->circuitBreakerClient->request('GET', '/api/3', []);
    }
}
