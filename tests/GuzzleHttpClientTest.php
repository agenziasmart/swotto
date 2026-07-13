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
        $this->mockLogger = $this->createStub(LoggerInterface::class);
        $this->httpClient = new GuzzleHttpClient($this->config, $this->mockLogger);
    }

    /**
     * Inject a mock Guzzle client into the HTTP client via reflection.
     */
    private function injectMockGuzzle(GuzzleClient $mockGuzzle): void
    {
        $reflection = new \ReflectionClass($this->httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($this->httpClient, $mockGuzzle);
    }

    public function testConstructorWithValidConfig(): void
    {
        $config = new Configuration(['url' => 'https://api.example.com']);
        $client = new GuzzleHttpClient($config, $this->mockLogger);

        $this->assertInstanceOf(GuzzleHttpClient::class, $client);
    }

    public function testSuccessfulRequest(): void
    {
        $responseData = ['success' => true, 'data' => 'test'];
        $response = new Response(200, [], (string) json_encode($responseData));

        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->with('GET', 'test', ['query' => ['param' => 'value']])
            ->willReturn($response);

        $this->injectMockGuzzle($mockGuzzle);

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

        $this->injectMockGuzzle($mockGuzzle);

        $result = $this->httpClient->request('GET', 'test');

        $this->assertEquals([], $result);
    }

    public function testValidationException(): void
    {
        $errorData = ['field' => 'required'];
        $response = new Response(400, [], (string) json_encode(['message' => 'Validation failed'] + $errorData));
        $request = new Request('POST', 'test');
        $exception = new RequestException('Bad Request', $request, $response);

        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->willThrowException($exception);

        $this->injectMockGuzzle($mockGuzzle);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Validation failed');
        $this->expectExceptionCode(400);

        $this->httpClient->request('POST', 'test');
    }

    public function testAuthenticationException(): void
    {
        $response = new Response(401, [], (string) json_encode(['message' => 'Unauthorized']));
        $request = new Request('GET', 'test');
        $exception = new RequestException('Unauthorized', $request, $response);

        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->willThrowException($exception);

        $this->injectMockGuzzle($mockGuzzle);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Unauthorized');
        $this->expectExceptionCode(401);

        $this->httpClient->request('GET', 'test');
    }

    public function testForbiddenException(): void
    {
        $response = new Response(403, [], (string) json_encode(['message' => 'Access denied']));
        $request = new Request('GET', 'test');
        $exception = new RequestException('Forbidden', $request, $response);

        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->willThrowException($exception);

        $this->injectMockGuzzle($mockGuzzle);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('Access denied');
        $this->expectExceptionCode(403);

        $this->httpClient->request('GET', 'test');
    }

    public function testNotFoundException(): void
    {
        $response = new Response(404, [], (string) json_encode(['message' => 'Not found']));
        $request = new Request('GET', 'test');
        $exception = new RequestException('Not Found', $request, $response);

        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->willThrowException($exception);

        $this->injectMockGuzzle($mockGuzzle);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('Not found');
        $this->expectExceptionCode(404);

        $this->httpClient->request('GET', 'test');
    }

    public function testRateLimitException(): void
    {
        $response = new Response(429, ['Retry-After' => ['60']], (string) json_encode(['message' => 'Too many requests']));
        $request = new Request('GET', 'test');
        $exception = new RequestException('Too Many Requests', $request, $response);

        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->willThrowException($exception);

        $this->injectMockGuzzle($mockGuzzle);

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

        $this->injectMockGuzzle($mockGuzzle);

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

        $this->injectMockGuzzle($mockGuzzle);

        $this->expectException(NetworkException::class);
        $this->expectExceptionMessage('Network error while requesting test: Network error');

        $this->httpClient->request('GET', 'test');
    }

    // ========== Per-Call Options Tests ==========

    /**
     * Test that per-call bearer_token option is extracted and converted to Authorization header.
     */
    public function testPerCallBearerTokenOption(): void
    {
        $responseData = ['success' => true];
        $response = new Response(200, [], (string) json_encode($responseData));

        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'test',
                $this->callback(function ($options) {
                    return isset($options['headers']['Authorization'])
                        && $options['headers']['Authorization'] === 'Bearer per-call-token-123'
                        && !isset($options['bearer_token']);
                })
            )
            ->willReturn($response);

        $this->injectMockGuzzle($mockGuzzle);

        $result = $this->httpClient->request('GET', 'test', ['bearer_token' => 'per-call-token-123']);

        $this->assertEquals($responseData, $result);
    }

    /**
     * Test that per-call language option is extracted and converted to Accept-Language header.
     */
    public function testPerCallLanguageOption(): void
    {
        $responseData = ['success' => true];
        $response = new Response(200, [], (string) json_encode($responseData));

        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'test',
                $this->callback(function ($options) {
                    return isset($options['headers']['Accept-Language'])
                        && $options['headers']['Accept-Language'] === 'it'
                        && !isset($options['language']);
                })
            )
            ->willReturn($response);

        $this->injectMockGuzzle($mockGuzzle);

        $result = $this->httpClient->request('GET', 'test', ['language' => 'it']);

        $this->assertEquals($responseData, $result);
    }

    /**
     * Test that per-call session_id option is extracted and converted to x-sid header.
     */
    public function testPerCallSessionIdOption(): void
    {
        $responseData = ['success' => true];
        $response = new Response(200, [], (string) json_encode($responseData));

        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'test',
                $this->callback(function ($options) {
                    return isset($options['headers']['x-sid'])
                        && $options['headers']['x-sid'] === 'session-abc-123'
                        && !isset($options['session_id']);
                })
            )
            ->willReturn($response);

        $this->injectMockGuzzle($mockGuzzle);

        $result = $this->httpClient->request('GET', 'test', ['session_id' => 'session-abc-123']);

        $this->assertEquals($responseData, $result);
    }

    /**
     * Test that per-call client_ip option is extracted and converted to Client-Ip header.
     */
    public function testPerCallClientIpOption(): void
    {
        $responseData = ['success' => true];
        $response = new Response(200, [], (string) json_encode($responseData));

        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'test',
                $this->callback(function ($options) {
                    return isset($options['headers']['Client-Ip'])
                        && $options['headers']['Client-Ip'] === '192.168.1.100'
                        && !isset($options['client_ip']);
                })
            )
            ->willReturn($response);

        $this->injectMockGuzzle($mockGuzzle);

        $result = $this->httpClient->request('GET', 'test', ['client_ip' => '192.168.1.100']);

        $this->assertEquals($responseData, $result);
    }

    /**
     * Test that per-call client_user_agent option is extracted and converted to X-Client-User-Agent header.
     */
    public function testPerCallClientUserAgentOption(): void
    {
        $responseData = ['success' => true];
        $response = new Response(200, [], (string) json_encode($responseData));

        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'test',
                $this->callback(function ($options) {
                    return isset($options['headers']['X-Client-User-Agent'])
                        && $options['headers']['X-Client-User-Agent'] === 'Mozilla/5.0 Custom'
                        && !isset($options['client_user_agent']);
                })
            )
            ->willReturn($response);

        $this->injectMockGuzzle($mockGuzzle);

        $result = $this->httpClient->request('GET', 'test', ['client_user_agent' => 'Mozilla/5.0 Custom']);

        $this->assertEquals($responseData, $result);
    }

    /**
     * Test that multiple per-call options are extracted together.
     */
    public function testMultiplePerCallOptions(): void
    {
        $responseData = ['success' => true];
        $response = new Response(200, [], (string) json_encode($responseData));

        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'test',
                $this->callback(function ($options) {
                    return isset($options['headers']['Authorization'])
                        && $options['headers']['Authorization'] === 'Bearer token-xyz'
                        && isset($options['headers']['Accept-Language'])
                        && $options['headers']['Accept-Language'] === 'fr'
                        && isset($options['headers']['x-sid'])
                        && $options['headers']['x-sid'] === 'sess-999'
                        && isset($options['headers']['Client-Ip'])
                        && $options['headers']['Client-Ip'] === '10.0.0.1'
                        && isset($options['headers']['X-Client-User-Agent'])
                        && $options['headers']['X-Client-User-Agent'] === 'TestApp/2.0'
                        && !isset($options['bearer_token'])
                        && !isset($options['language'])
                        && !isset($options['session_id'])
                        && !isset($options['client_ip'])
                        && !isset($options['client_user_agent']);
                })
            )
            ->willReturn($response);

        $this->injectMockGuzzle($mockGuzzle);

        $result = $this->httpClient->request('GET', 'test', [
            'bearer_token' => 'token-xyz',
            'language' => 'fr',
            'session_id' => 'sess-999',
            'client_ip' => '10.0.0.1',
            'client_user_agent' => 'TestApp/2.0',
        ]);

        $this->assertEquals($responseData, $result);
    }

    /**
     * Test that per-call options are merged with existing headers in options.
     */
    public function testPerCallOptionsMergeWithExistingHeaders(): void
    {
        $responseData = ['success' => true];
        $response = new Response(200, [], (string) json_encode($responseData));

        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'test',
                $this->callback(function ($options) {
                    return isset($options['headers']['X-Custom-Header'])
                        && $options['headers']['X-Custom-Header'] === 'custom-value'
                        && isset($options['headers']['Authorization'])
                        && $options['headers']['Authorization'] === 'Bearer per-call-token';
                })
            )
            ->willReturn($response);

        $this->injectMockGuzzle($mockGuzzle);

        $result = $this->httpClient->request('GET', 'test', [
            'headers' => ['X-Custom-Header' => 'custom-value'],
            'bearer_token' => 'per-call-token',
        ]);

        $this->assertEquals($responseData, $result);
    }

    /**
     * Test that per-call options work with requestRaw() method too.
     */
    public function testPerCallOptionsInRequestRaw(): void
    {
        $response = new Response(200, [], 'raw content');

        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'test',
                $this->callback(function ($options) {
                    return isset($options['headers']['Authorization'])
                        && $options['headers']['Authorization'] === 'Bearer raw-token'
                        && !isset($options['bearer_token']);
                })
            )
            ->willReturn($response);

        $this->injectMockGuzzle($mockGuzzle);

        $result = $this->httpClient->requestRaw('GET', 'test', ['bearer_token' => 'raw-token']);

        $this->assertSame($response, $result);
    }

    // ========== SDK User-Agent & Telemetry Tests ==========

    /**
     * Test that SDK User-Agent is set as base header in Guzzle client.
     */
    public function testSdkUserAgentIsSetInBaseHeaders(): void
    {
        $config = new Configuration(['url' => 'https://api.example.com']);
        $client = new GuzzleHttpClient($config);

        $reflection = new \ReflectionClass($client);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        /** @var GuzzleClient $guzzle */
        $guzzle = $clientProperty->getValue($client);

        $guzzleConfig = $guzzle->getConfig();
        $headers = $guzzleConfig['headers'] ?? [];

        $this->assertArrayHasKey('User-Agent', $headers);
        $this->assertStringContainsString('Swotto/v1 PHP-SDK/', $headers['User-Agent']);
        $this->assertStringContainsString('PHP/' . PHP_VERSION, $headers['User-Agent']);
    }

    /**
     * Test that X-Swotto-Client-Info telemetry header is set.
     */
    public function testTelemetryHeaderIsSet(): void
    {
        $config = new Configuration(['url' => 'https://api.example.com']);
        $client = new GuzzleHttpClient($config);

        $reflection = new \ReflectionClass($client);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        /** @var GuzzleClient $guzzle */
        $guzzle = $clientProperty->getValue($client);

        $guzzleConfig = $guzzle->getConfig();
        $headers = $guzzleConfig['headers'] ?? [];

        $this->assertArrayHasKey('X-Swotto-Client-Info', $headers);

        $telemetry = json_decode($headers['X-Swotto-Client-Info'], true);
        $this->assertIsArray($telemetry);
        $this->assertArrayHasKey('sdk_version', $telemetry);
        $this->assertArrayHasKey('lang', $telemetry);
        $this->assertEquals('php', $telemetry['lang']);
        $this->assertEquals(PHP_VERSION, $telemetry['lang_version']);
        $this->assertEquals(PHP_OS, $telemetry['os']);
    }

    /**
     * Test that app_name and app_version are included in User-Agent.
     */
    public function testAppInfoInUserAgent(): void
    {
        $config = new Configuration([
            'url' => 'https://api.example.com',
            'app_name' => 'MyApp',
            'app_version' => '3.5.0',
        ]);
        $client = new GuzzleHttpClient($config);

        $reflection = new \ReflectionClass($client);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        /** @var GuzzleClient $guzzle */
        $guzzle = $clientProperty->getValue($client);

        $guzzleConfig = $guzzle->getConfig();
        $headers = $guzzleConfig['headers'] ?? [];

        $this->assertStringContainsString('MyApp/3.5.0', $headers['User-Agent']);
        $this->assertStringContainsString('Swotto/v1 PHP-SDK/', $headers['User-Agent']);
        $this->assertStringContainsString('PHP/', $headers['User-Agent']);
    }

    /**
     * Test that app_name without app_version works.
     */
    public function testAppNameWithoutVersion(): void
    {
        $config = new Configuration([
            'url' => 'https://api.example.com',
            'app_name' => 'MyApp',
        ]);
        $client = new GuzzleHttpClient($config);

        $reflection = new \ReflectionClass($client);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        /** @var GuzzleClient $guzzle */
        $guzzle = $clientProperty->getValue($client);

        $guzzleConfig = $guzzle->getConfig();
        $ua = $guzzleConfig['headers']['User-Agent'] ?? '';

        $this->assertStringContainsString('MyApp', $ua);
        $this->assertStringNotContainsString('MyApp/', $ua);
    }

    /**
     * Test that app_name is included in telemetry.
     */
    public function testAppInfoInTelemetry(): void
    {
        $config = new Configuration([
            'url' => 'https://api.example.com',
            'app_name' => 'TestApp',
            'app_version' => '1.0.0',
        ]);
        $client = new GuzzleHttpClient($config);

        $reflection = new \ReflectionClass($client);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        /** @var GuzzleClient $guzzle */
        $guzzle = $clientProperty->getValue($client);

        $guzzleConfig = $guzzle->getConfig();
        $headers = $guzzleConfig['headers'] ?? [];

        $telemetry = json_decode($headers['X-Swotto-Client-Info'], true);
        $this->assertEquals('TestApp', $telemetry['app_name']);
        $this->assertEquals('1.0.0', $telemetry['app_version']);
    }

    /**
     * Test that client_user_agent does NOT overwrite SDK User-Agent.
     */
    public function testClientUserAgentDoesNotOverwriteSdkUserAgent(): void
    {
        $responseData = ['success' => true];
        $response = new Response(200, [], (string) json_encode($responseData));

        $mockGuzzle = $this->createMock(GuzzleClient::class);
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'test',
                $this->callback(function ($options) {
                    // X-Client-User-Agent should have the end-user UA
                    // User-Agent should NOT be overwritten by per-call options
                    return isset($options['headers']['X-Client-User-Agent'])
                        && $options['headers']['X-Client-User-Agent'] === 'Mozilla/5.0 EndUser'
                        && !isset($options['headers']['User-Agent']);
                })
            )
            ->willReturn($response);

        $this->injectMockGuzzle($mockGuzzle);

        $result = $this->httpClient->request('GET', 'test', ['client_user_agent' => 'Mozilla/5.0 EndUser']);

        $this->assertEquals($responseData, $result);
    }
}
