<?php

declare(strict_types=1);

namespace Swotto\Tests;

use PHPUnit\Framework\TestCase;
use Swotto\Exception\ApiException;
use Swotto\Exception\AuthenticationException;
use Swotto\Exception\ConfigurationException;
use Swotto\Exception\ConnectionException;
use Swotto\Exception\ForbiddenException;
use Swotto\Exception\NetworkException;
use Swotto\Exception\NotFoundException;
use Swotto\Exception\RateLimitException;
use Swotto\Exception\SwottoException;
use Swotto\Exception\ValidationException;

class ExceptionTest extends TestCase
{
    public function testSwottoException(): void
    {
        $exception = new SwottoException('Test message', ['error' => 'data'], 500);

        $this->assertEquals('Test message', $exception->getMessage());
        $this->assertEquals(['error' => 'data'], $exception->getErrorData());
        $this->assertEquals(500, $exception->getCode());
    }

    public function testApiException(): void
    {
        $exception = new ApiException('API Error', ['field' => 'invalid'], 400);

        $this->assertEquals('API Error', $exception->getMessage());
        $this->assertEquals(['field' => 'invalid'], $exception->getErrorData());
        $this->assertEquals(400, $exception->getCode());
        $this->assertInstanceOf(SwottoException::class, $exception);
    }

    public function testAuthenticationException(): void
    {
        $exception = new AuthenticationException('Unauthorized', ['token' => 'invalid'], 401);

        $this->assertEquals('Unauthorized', $exception->getMessage());
        $this->assertEquals(['token' => 'invalid'], $exception->getErrorData());
        $this->assertEquals(401, $exception->getCode());
        $this->assertInstanceOf(SwottoException::class, $exception);
    }

    public function testConfigurationException(): void
    {
        $exception = new ConfigurationException('Invalid config');

        $this->assertEquals('Invalid config', $exception->getMessage());
        $this->assertEquals(['message' => 'Invalid config', 'code' => 0], $exception->getErrorData());
        $this->assertEquals(0, $exception->getCode());
        $this->assertInstanceOf(SwottoException::class, $exception);
    }

    public function testConnectionException(): void
    {
        $exception = new ConnectionException('Connection failed', 'https://api.example.com');

        $this->assertEquals('Connection failed', $exception->getMessage());
        $this->assertEquals('https://api.example.com', $exception->getUrl());
        $this->assertEquals([], $exception->getTraceDetails());
        $this->assertEquals(0, $exception->getCode());
        $this->assertInstanceOf(SwottoException::class, $exception);
    }

    public function testConnectionExceptionWithTrace(): void
    {
        $trace = ['trace1', 'trace2'];
        $exception = new ConnectionException('Connection failed', 'https://api.example.com', $trace, 500);

        $this->assertEquals('Connection failed', $exception->getMessage());
        $this->assertEquals('https://api.example.com', $exception->getUrl());
        $this->assertEquals($trace, $exception->getTraceDetails());
        $this->assertEquals(500, $exception->getCode());
    }

    public function testForbiddenException(): void
    {
        $exception = new ForbiddenException('Access denied', ['role' => 'user'], 403);

        $this->assertEquals('Access denied', $exception->getMessage());
        $this->assertEquals(['role' => 'user'], $exception->getErrorData());
        $this->assertEquals(403, $exception->getCode());
        $this->assertInstanceOf(SwottoException::class, $exception);
    }

    public function testNetworkException(): void
    {
        $exception = new NetworkException('Network error', [], 502);

        $this->assertEquals('Network error', $exception->getMessage());
        $this->assertEquals(502, $exception->getCode());
        $this->assertInstanceOf(SwottoException::class, $exception);
    }

    public function testNotFoundException(): void
    {
        $exception = new NotFoundException('Resource not found', ['id' => 123], 404);

        $this->assertEquals('Resource not found', $exception->getMessage());
        $this->assertEquals(['id' => 123], $exception->getErrorData());
        $this->assertEquals(404, $exception->getCode());
        $this->assertInstanceOf(SwottoException::class, $exception);
    }

    public function testRateLimitException(): void
    {
        $exception = new RateLimitException('Too many requests', ['limit' => 100], 60);

        $this->assertEquals('Too many requests', $exception->getMessage());
        $this->assertEquals(['limit' => 100, 'retry_after' => 60], $exception->getErrorData());
        $this->assertEquals(60, $exception->getRetryAfter());
        $this->assertEquals(429, $exception->getCode());
        $this->assertInstanceOf(SwottoException::class, $exception);
    }

    public function testValidationException(): void
    {
        $exception = new ValidationException('Validation failed', ['field' => 'required'], 422);

        $this->assertEquals('Validation failed', $exception->getMessage());
        $this->assertEquals(['field' => 'required'], $exception->getErrorData());
        $this->assertEquals(422, $exception->getCode());
        $this->assertInstanceOf(SwottoException::class, $exception);
    }

    public function testExceptionDefaults(): void
    {
        $exception = new SwottoException('Test');

        $this->assertEquals('Test', $exception->getMessage());
        $this->assertEquals([], $exception->getErrorData());
        $this->assertEquals(0, $exception->getCode());
    }
}
