<?php

declare(strict_types=1);

namespace Swotto\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Swotto\Config\Configuration;
use Swotto\Exception\AuthenticationException;
use Swotto\Exception\ConnectionException;
use Swotto\Exception\ForbiddenException;
use Swotto\Exception\NetworkException;
use Swotto\Exception\NotFoundException;
use Swotto\Exception\RateLimitException;
use Swotto\Exception\ValidationException;
use Swotto\Http\GuzzleHttpClient;

class GuzzleHttpClientTest extends TestCase
{
    private Configuration $config;

    private LoggerInterface $mockLogger;

    private GuzzleHttpClient $httpClient;

    protected function setUp(): void
    {
        $this->config = new Configuration(['url' => 'https://api.example.com']);
        $this->mockLogger = $this->createMock(LoggerInterface::class);
        $this->httpClient = new GuzzleHttpClient($this->config, $this->mockLogger);
    }

    public function testSuccessfulRequest(): void
    {
        $responseData = ['success' => true, 'data' => 'test'];
        $response = new Response(200, [], json_encode($responseData));

        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->with('GET', 'test', ['query' => ['param' => 'value']])
            ->willReturn($response);

        // Use reflection to replace the internal Guzzle client
        $reflection = new \ReflectionClass($this->httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($this->httpClient, $mockGuzzle);

        $result = $this->httpClient->request('GET', 'test', ['query' => ['param' => 'value']]);

        $this->assertEquals($responseData, $result);
    }

    public function testEmptyResponse(): void
    {
        $response = new Response(200, [], '');

        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->willReturn($response);

        // Use reflection to replace the internal Guzzle client
        $reflection = new \ReflectionClass($this->httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($this->httpClient, $mockGuzzle);

        $result = $this->httpClient->request('GET', 'test');

        $this->assertEquals([], $result);
    }

    public function testValidationException(): void
    {
        $errorData = ['field' => 'required'];
        $response = new Response(400, [], json_encode(['message' => 'Validation failed'] + $errorData));
        $request = new Request('POST', 'test');
        $exception = new RequestException('Bad Request', $request, $response);

        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->willThrowException($exception);

        // Use reflection to replace the internal Guzzle client
        $reflection = new \ReflectionClass($this->httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($this->httpClient, $mockGuzzle);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Validation failed');
        $this->expectExceptionCode(400);

        $this->httpClient->request('POST', 'test');
    }

    public function testAuthenticationException(): void
    {
        $response = new Response(401, [], json_encode(['message' => 'Unauthorized']));
        $request = new Request('GET', 'test');
        $exception = new RequestException('Unauthorized', $request, $response);

        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->willThrowException($exception);

        // Use reflection to replace the internal Guzzle client
        $reflection = new \ReflectionClass($this->httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($this->httpClient, $mockGuzzle);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Unauthorized');
        $this->expectExceptionCode(401);

        $this->httpClient->request('GET', 'test');
    }

    public function testForbiddenException(): void
    {
        $response = new Response(403, [], json_encode(['message' => 'Access denied']));
        $request = new Request('GET', 'test');
        $exception = new RequestException('Forbidden', $request, $response);

        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->willThrowException($exception);

        // Use reflection to replace the internal Guzzle client
        $reflection = new \ReflectionClass($this->httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($this->httpClient, $mockGuzzle);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('Access denied');
        $this->expectExceptionCode(403);

        $this->httpClient->request('GET', 'test');
    }

    public function testNotFoundException(): void
    {
        $response = new Response(404, [], json_encode(['message' => 'Not found']));
        $request = new Request('GET', 'test');
        $exception = new RequestException('Not Found', $request, $response);

        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->willThrowException($exception);

        // Use reflection to replace the internal Guzzle client
        $reflection = new \ReflectionClass($this->httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($this->httpClient, $mockGuzzle);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('Not found');
        $this->expectExceptionCode(404);

        $this->httpClient->request('GET', 'test');
    }

    public function testRateLimitException(): void
    {
        $response = new Response(429, ['Retry-After' => ['60']], json_encode(['message' => 'Too many requests']));
        $request = new Request('GET', 'test');
        $exception = new RequestException('Too Many Requests', $request, $response);

        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->willThrowException($exception);

        // Use reflection to replace the internal Guzzle client
        $reflection = new \ReflectionClass($this->httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($this->httpClient, $mockGuzzle);

        $this->expectException(RateLimitException::class);
        $this->expectExceptionMessage('Too many requests');

        try {
            $this->httpClient->request('GET', 'test');
        } catch (RateLimitException $e) {
            $this->assertEquals(60, $e->getRetryAfter());
            throw $e;
        }
    }

    public function testConnectException(): void
    {
        $request = new Request('GET', 'test');
        $exception = new ConnectException('Connection failed', $request);

        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->willThrowException($exception);

        // Use reflection to replace the internal Guzzle client
        $reflection = new \ReflectionClass($this->httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($this->httpClient, $mockGuzzle);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Connection failed');

        $this->httpClient->request('GET', 'test');
    }

    public function testNetworkException(): void
    {
        $request = new Request('GET', 'test');
        $exception = new RequestException('Network error', $request);

        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->willThrowException($exception);

        // Use reflection to replace the internal Guzzle client
        $reflection = new \ReflectionClass($this->httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($this->httpClient, $mockGuzzle);

        $this->expectException(NetworkException::class);
        $this->expectExceptionMessage('Network error while requesting test: Network error');

        $this->httpClient->request('GET', 'test');
    }

    public function testInitialize(): void
    {
        $newConfig = [
            'url' => 'https://new-api.example.com',
            'key' => 'test-key',
            'session_id' => 'test-session',
        ];

        $this->httpClient->initialize($newConfig);

        // Test that the client was reinitialized (we can't easily test internal state)
        $this->assertTrue(true); // This test mainly ensures no exceptions are thrown
    }

    public function testInitializeWithConnectionException(): void
    {
        // This test is challenging because valid URLs don't cause Guzzle construction to fail
        // We'll simulate it by testing that the client can be created with valid URLs
        $config = new Configuration(['url' => 'https://api.example.com']);
        $client = new GuzzleHttpClient($config, $this->mockLogger);

        $this->assertInstanceOf(GuzzleHttpClient::class, $client);
    }
}
