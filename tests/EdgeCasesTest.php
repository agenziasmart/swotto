<?php

declare(strict_types=1);

namespace Swotto\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Swotto\Client;
use Swotto\Config\Configuration;
use Swotto\Contract\HttpClientInterface;
use Swotto\Exception\ApiException;
use Swotto\Exception\RateLimitException;
use Swotto\Http\GuzzleHttpClient;
use Swotto\Response\SwottoResponse;

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

    // ========== 6.1 Configuration Edge Cases ==========

    /**
     * Test access_token empty string is treated as no token.
     */
    public function testAccessTokenEmptyStringHasNoToken(): void
    {
        $client = new Client(
            ['url' => 'https://api.example.com', 'access_token' => ''],
            $this->mockLogger,
            $this->mockHttpClient
        );

        $this->assertFalse($client->hasAccessToken());
    }

    /**
     * Test access_token null is treated as no token.
     */
    public function testAccessTokenNullHasNoToken(): void
    {
        $client = new Client(
            ['url' => 'https://api.example.com', 'access_token' => null],
            $this->mockLogger,
            $this->mockHttpClient
        );

        $this->assertFalse($client->hasAccessToken());
    }

    /**
     * Test access_token whitespace only is treated as having token.
     */
    public function testAccessTokenWhitespaceOnlyHasToken(): void
    {
        $client = new Client(
            ['url' => 'https://api.example.com', 'access_token' => '   '],
            $this->mockLogger,
            $this->mockHttpClient
        );

        // Whitespace-only token is still considered "has token" since it's not empty/null
        $this->assertTrue($client->hasAccessToken());
    }

    /**
     * Test CRLF injection prevention in client user agent.
     */
    public function testCrlfInjectionPreventionInUserAgent(): void
    {
        $config = new Configuration([
            'url' => 'https://api.example.com',
            'client_user_agent' => "MyApp/1.0\r\nX-Injected: malicious",
        ]);

        $userAgent = $config->detectClientUserAgent();
        $this->assertNotNull($userAgent);

        // CRLF should be stripped
        $this->assertStringNotContainsString("\r", $userAgent);
        $this->assertStringNotContainsString("\n", $userAgent);
        $this->assertEquals('MyApp/1.0X-Injected: malicious', $userAgent);
    }

    /**
     * Test CRLF injection prevention in client IP.
     */
    public function testCrlfInjectionPreventionInClientIp(): void
    {
        $config = new Configuration([
            'url' => 'https://api.example.com',
            'client_ip' => "192.168.1.1\r\nX-Injected: malicious",
        ]);

        $clientIp = $config->detectClientIp();
        $this->assertNotNull($clientIp);

        // CRLF should be stripped
        $this->assertStringNotContainsString("\r", $clientIp);
        $this->assertStringNotContainsString("\n", $clientIp);
        $this->assertEquals('192.168.1.1X-Injected: malicious', $clientIp);
    }

    /**
     * Test null byte injection prevention.
     */
    public function testNullByteInjectionPrevention(): void
    {
        $config = new Configuration([
            'url' => 'https://api.example.com',
            'client_user_agent' => "MyApp/1.0\0malicious",
        ]);

        $userAgent = $config->detectClientUserAgent();
        $this->assertNotNull($userAgent);

        // Null byte should be stripped
        $this->assertStringNotContainsString("\0", $userAgent);
        $this->assertEquals('MyApp/1.0malicious', $userAgent);
    }

    // ========== 6.2 Response Edge Cases ==========

    /**
     * Test response with data: null in getParsed returns structure with null data.
     */
    public function testResponseDataNullInGetParsed(): void
    {
        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->willReturn(['data' => null, 'success' => true]);

        $client = new Client(
            ['url' => 'https://api.example.com'],
            $this->mockLogger,
            $this->mockHttpClient
        );

        $result = $client->getParsed('test');

        // getParsed extracts 'data' key, which is null
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('success', $result);
    }

    /**
     * Test response with data: [] returns empty array.
     */
    public function testResponseDataEmptyArrayReturnsEmptyArray(): void
    {
        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->willReturn(['data' => [], 'success' => true]);

        $client = new Client(
            ['url' => 'https://api.example.com'],
            $this->mockLogger,
            $this->mockHttpClient
        );

        $result = $client->getParsed('test');

        $this->assertEquals([], $result['data']);
        $this->assertTrue($result['success']);
    }

    /**
     * Test pagination with current_page > total_pages is handled gracefully.
     */
    public function testPaginationCurrentPageGreaterThanTotalPages(): void
    {
        // parseSwottoResponse expects 'meta.pagination' not 'paginator' directly
        $responseData = [
            'data' => [],
            'meta' => [
                'pagination' => [
                    'current_page' => 10,
                    'total_pages' => 5,
                    'total' => 50,
                    'per_page' => 10,
                ],
            ],
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->willReturn($responseData);

        $client = new Client(
            ['url' => 'https://api.example.com'],
            $this->mockLogger,
            $this->mockHttpClient
        );

        $result = $client->getParsed('test');

        // Should return the response as-is, not throw error
        $this->assertEquals([], $result['data']);
        $this->assertArrayHasKey('paginator', $result);
        // buildPaginator maps: current_page->current, total_pages->last
        $this->assertEquals(10, $result['paginator']['current']);
        $this->assertEquals(5, $result['paginator']['last']);
    }

    /**
     * Test JSON response with non-ASCII characters (Unicode).
     */
    public function testJsonWithNonAsciiCharacters(): void
    {
        $unicodeData = [
            'name' => 'Müller',
            'city' => '東京',
            'emoji' => '🎉',
            'arabic' => 'مرحبا',
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->willReturn(['data' => $unicodeData]);

        $client = new Client(
            ['url' => 'https://api.example.com'],
            $this->mockLogger,
            $this->mockHttpClient
        );

        $result = $client->get('test');

        $this->assertEquals('Müller', $result['data']['name']);
        $this->assertEquals('東京', $result['data']['city']);
        $this->assertEquals('🎉', $result['data']['emoji']);
        $this->assertEquals('مرحبا', $result['data']['arabic']);
    }

    /**
     * Test CSV parsing with standard comma delimiter.
     * Note: parseCsvContent returns data rows keyed by header names.
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

        // parseCsvContent returns data rows (excluding header) as associative arrays
        $this->assertCount(2, $result); // 2 data rows
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

        // With default comma delimiter, entire row becomes one "column"
        // The header is "name;age;city", data is "John;30;Rome"
        $this->assertCount(1, $result); // 1 data row
        $this->assertArrayHasKey('name;age;city', $result[0]);
    }

    // ========== 6.3 Network Edge Cases ==========

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

        // Inject custom handler via reflection or use factory
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
            // Retry-After returns 0 when header is missing (int type, not nullable)
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

    /**
     * Test fetchPop with missing 'data' key returns empty array.
     */
    public function testFetchPopMissingDataKeyReturnsEmptyArray(): void
    {
        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->willReturn(['success' => true]); // No 'data' key

        $client = new Client(
            ['url' => 'https://api.example.com'],
            $this->mockLogger,
            $this->mockHttpClient
        );

        $result = $client->fetchPop('test');

        $this->assertEquals([], $result);
    }

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

        $client = new Client(
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

        $client = new Client(
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

        // Should strip trailing slashes
        $this->assertEquals('https://api.example.com', $config->getBaseUrl());
    }

    /**
     * Test hasAccessToken with valid token returns true.
     */
    public function testHasAccessTokenWithValidToken(): void
    {
        $client = new Client(
            ['url' => 'https://api.example.com', 'access_token' => 'valid-token-123'],
            $this->mockLogger,
            $this->mockHttpClient
        );

        $this->assertTrue($client->hasAccessToken());
    }
}
