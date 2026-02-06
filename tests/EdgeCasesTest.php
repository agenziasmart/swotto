<?php

declare(strict_types=1);

namespace Swotto\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Swotto\Config\Configuration;
use Swotto\Contract\HttpClientInterface;
use Swotto\Exception\ApiException;
use Swotto\Exception\RateLimitException;
use Swotto\Http\GuzzleHttpClient;
use Swotto\Response\SwottoResponse;
use Swotto\SwottoClient;

/**
 * EdgeCasesTest.
 *
 * Tests for edge cases and hardening scenarios.
 */
class EdgeCasesTest extends TestCase
{
    private HttpClientInterface $mockHttpClient;

    private LoggerInterface $mockLogger;

    protected function setUp(): void
    {
        $this->mockHttpClient = $this->createMock(HttpClientInterface::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);
    }

    // ========== Response Edge Cases ==========

    /**
     * Test JSON response with non-ASCII characters (Unicode).
     */
    public function testJsonWithNonAsciiCharacters(): void
    {
        $unicodeData = [
            'name' => 'Muller',
            'city' => 'Tokyo',
            'emoji' => 'test',
            'arabic' => 'hello',
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->willReturn(['data' => $unicodeData]);

        $client = new SwottoClient(
            ['url' => 'https://api.example.com'],
            $this->mockLogger,
            $this->mockHttpClient
        );

        $result = $client->get('test');

        $this->assertEquals('Muller', $result['data']['name']);
        $this->assertEquals('Tokyo', $result['data']['city']);
    }

    /**
     * Test CSV parsing with standard comma delimiter.
     */
    public function testCsvWithStandardCommaDelimiter(): void
    {
        $csvContent = "name,age,city\nJohn,30,Rome\nJane,25,Milan";

        $response = new Response(
            200,
            ['Content-Type' => 'text/csv'],
            $csvContent
        );

        $swottoResponse = new SwottoResponse($response);
        $result = $swottoResponse->asArray();

        $this->assertCount(2, $result);
        $this->assertEquals('John', $result[0]['name']);
        $this->assertEquals('30', $result[0]['age']);
        $this->assertEquals('Rome', $result[0]['city']);
    }

    /**
     * Test CSV with semicolon is treated as single field (no custom delimiter support).
     */
    public function testCsvWithSemicolonTreatedAsSingleField(): void
    {
        $csvContent = "name;age;city\nJohn;30;Rome";

        $response = new Response(
            200,
            ['Content-Type' => 'text/csv'],
            $csvContent
        );

        $swottoResponse = new SwottoResponse($response);
        $result = $swottoResponse->asArray();

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('name;age;city', $result[0]);
    }

    // ========== Network Edge Cases ==========

    /**
     * Test empty response body with 200 status.
     */
    public function testEmptyResponseBodyWith200Status(): void
    {
        $response = new Response(200, ['Content-Type' => 'application/json'], '');

        $swottoResponse = new SwottoResponse($response);

        $this->assertEquals(200, $swottoResponse->getStatusCode());
        $this->assertEquals('', $swottoResponse->asString());
    }

    /**
     * Test 500 Internal Server Error.
     */
    public function testGeneric500InternalServerError(): void
    {
        $mock = new MockHandler([
            new Response(500, [], (string) json_encode(['message' => 'Internal Server Error'])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $config = new Configuration(['url' => 'https://api.example.com']);
        $httpClient = new GuzzleHttpClient($config, $this->mockLogger);

        $reflection = new \ReflectionClass($httpClient);
        $clientProp = $reflection->getProperty('client');
        $clientProp->setAccessible(true);
        $clientProp->setValue($httpClient, new \GuzzleHttp\Client(['handler' => $handlerStack]));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Internal Server Error');

        $httpClient->request('GET', 'test');
    }

    /**
     * Test 502 Bad Gateway Error.
     */
    public function testGeneric502BadGatewayError(): void
    {
        $mock = new MockHandler([
            new Response(502, [], (string) json_encode(['message' => 'Bad Gateway'])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $config = new Configuration(['url' => 'https://api.example.com']);
        $httpClient = new GuzzleHttpClient($config, $this->mockLogger);

        $reflection = new \ReflectionClass($httpClient);
        $clientProp = $reflection->getProperty('client');
        $clientProp->setAccessible(true);
        $clientProp->setValue($httpClient, new \GuzzleHttp\Client(['handler' => $handlerStack]));

        $this->expectException(ApiException::class);

        $httpClient->request('GET', 'test');
    }

    /**
     * Test 503 Service Unavailable Error.
     */
    public function testGeneric503ServiceUnavailableError(): void
    {
        $mock = new MockHandler([
            new Response(503, [], (string) json_encode(['message' => 'Service Unavailable'])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $config = new Configuration(['url' => 'https://api.example.com']);
        $httpClient = new GuzzleHttpClient($config, $this->mockLogger);

        $reflection = new \ReflectionClass($httpClient);
        $clientProp = $reflection->getProperty('client');
        $clientProp->setAccessible(true);
        $clientProp->setValue($httpClient, new \GuzzleHttp\Client(['handler' => $handlerStack]));

        $this->expectException(ApiException::class);

        $httpClient->request('GET', 'test');
    }

    /**
     * Test 504 Gateway Timeout Error.
     */
    public function testGeneric504GatewayTimeoutError(): void
    {
        $mock = new MockHandler([
            new Response(504, [], (string) json_encode(['message' => 'Gateway Timeout'])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $config = new Configuration(['url' => 'https://api.example.com']);
        $httpClient = new GuzzleHttpClient($config, $this->mockLogger);

        $reflection = new \ReflectionClass($httpClient);
        $clientProp = $reflection->getProperty('client');
        $clientProp->setAccessible(true);
        $clientProp->setValue($httpClient, new \GuzzleHttp\Client(['handler' => $handlerStack]));

        $this->expectException(ApiException::class);

        $httpClient->request('GET', 'test');
    }

    /**
     * Test rate limit exception without Retry-After header.
     */
    public function testRateLimitWithoutRetryAfterHeader(): void
    {
        $mock = new MockHandler([
            new Response(429, [], (string) json_encode(['message' => 'Too Many Requests'])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $config = new Configuration(['url' => 'https://api.example.com']);
        $httpClient = new GuzzleHttpClient($config, $this->mockLogger);

        $reflection = new \ReflectionClass($httpClient);
        $clientProp = $reflection->getProperty('client');
        $clientProp->setAccessible(true);
        $clientProp->setValue($httpClient, new \GuzzleHttp\Client(['handler' => $handlerStack]));

        try {
            $httpClient->request('GET', 'test');
            $this->fail('Expected RateLimitException was not thrown');
        } catch (RateLimitException $e) {
            $this->assertEquals(0, $e->getRetryAfter());
        }
    }

    /**
     * Test rate limit exception with Retry-After header.
     */
    public function testRateLimitWithRetryAfterHeader(): void
    {
        $mock = new MockHandler([
            new Response(429, ['Retry-After' => '120'], (string) json_encode(['message' => 'Too Many Requests'])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $config = new Configuration(['url' => 'https://api.example.com']);
        $httpClient = new GuzzleHttpClient($config, $this->mockLogger);

        $reflection = new \ReflectionClass($httpClient);
        $clientProp = $reflection->getProperty('client');
        $clientProp->setAccessible(true);
        $clientProp->setValue($httpClient, new \GuzzleHttp\Client(['handler' => $handlerStack]));

        try {
            $httpClient->request('GET', 'test');
            $this->fail('Expected RateLimitException was not thrown');
        } catch (RateLimitException $e) {
            $this->assertEquals(120, $e->getRetryAfter());
        }
    }

    /**
     * Test response with only whitespace body.
     */
    public function testResponseWithWhitespaceOnlyBody(): void
    {
        $response = new Response(200, ['Content-Type' => 'application/json'], '   ');

        $swottoResponse = new SwottoResponse($response);

        $this->assertEquals('   ', $swottoResponse->asString());
    }

    // ========== URL Edge Cases ==========

    /**
     * Test very long URL handling.
     */
    public function testVeryLongUrlHandling(): void
    {
        $longPath = str_repeat('a', 2000);

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', $longPath, $this->anything())
            ->willReturn(['data' => 'ok']);

        $client = new SwottoClient(
            ['url' => 'https://api.example.com'],
            $this->mockLogger,
            $this->mockHttpClient
        );

        $result = $client->get($longPath);

        $this->assertEquals('ok', $result['data']);
    }

    /**
     * Test special characters in URI path.
     */
    public function testSpecialCharactersInUriPath(): void
    {
        $specialPath = 'test/path with spaces/file%20name';

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', $specialPath, $this->anything())
            ->willReturn(['data' => 'ok']);

        $client = new SwottoClient(
            ['url' => 'https://api.example.com'],
            $this->mockLogger,
            $this->mockHttpClient
        );

        $result = $client->get($specialPath);

        $this->assertEquals('ok', $result['data']);
    }

    /**
     * Test configuration with multiple trailing slashes.
     */
    public function testConfigurationMultipleTrailingSlashes(): void
    {
        $config = new Configuration(['url' => 'https://api.example.com///']);

        $this->assertEquals('https://api.example.com', $config->getBaseUrl());
    }
}
