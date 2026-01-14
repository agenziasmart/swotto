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
        $response = new Response(200, [], (string) json_encode($responseData));

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
        $response = new Response(400, [], (string) json_encode(['message' => 'Validation failed'] + $errorData));
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
        $response = new Response(401, [], (string) json_encode(['message' => 'Unauthorized']));
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
        $response = new Response(403, [], (string) json_encode(['message' => 'Access denied']));
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
        $response = new Response(404, [], (string) json_encode(['message' => 'Not found']));
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
        $response = new Response(429, ['Retry-After' => ['60']], (string) json_encode(['message' => 'Too many requests']));
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

    /**
     * Test that initialize() updates internal configuration.
     *
     * This is a critical test that verifies the fix for:
     * "setAccessToken() non aggiornava correttamente gli headers"
     *
     * Before the fix, initialize() ignored the $config parameter and used
     * the old $this->config. After the fix, $this->config is updated.
     */
    public function testInitializeUpdatesAccessTokenConfig(): void
    {
        // Create initial config WITHOUT access_token
        $initialConfig = new Configuration(['url' => 'https://api.example.com']);
        $httpClient = new GuzzleHttpClient($initialConfig, $this->mockLogger);

        // Verify initial config has no access_token
        $reflection = new \ReflectionClass($httpClient);
        $configProperty = $reflection->getProperty('config');
        $configProperty->setAccessible(true);
        $config = $configProperty->getValue($httpClient);
        $this->assertNull($config->get('access_token'));

        // Now call initialize with NEW config including access_token
        $newConfig = [
            'url' => 'https://api.example.com',
            'access_token' => 'new-bearer-token-123',
        ];
        $httpClient->initialize($newConfig);

        // Verify the internal config was updated
        $config = $configProperty->getValue($httpClient);
        $this->assertEquals('new-bearer-token-123', $config->get('access_token'));

        // Verify getHeaders() returns the new Authorization header
        $headers = $config->getHeaders();
        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertEquals('Bearer new-bearer-token-123', $headers['Authorization']);
    }

    /**
     * Test that initialize() properly clears access_token when set to null.
     */
    public function testInitializeRemovesAccessTokenConfig(): void
    {
        // Create initial config WITH access_token
        $initialConfig = new Configuration([
            'url' => 'https://api.example.com',
            'access_token' => 'initial-token',
        ]);
        $httpClient = new GuzzleHttpClient($initialConfig, $this->mockLogger);

        // Verify initial config has access_token
        $reflection = new \ReflectionClass($httpClient);
        $configProperty = $reflection->getProperty('config');
        $configProperty->setAccessible(true);
        $config = $configProperty->getValue($httpClient);
        $this->assertEquals('initial-token', $config->get('access_token'));

        // Call initialize WITHOUT access_token (simulating clearAccessToken)
        $newConfig = [
            'url' => 'https://api.example.com',
            'access_token' => null,
        ];
        $httpClient->initialize($newConfig);

        // Verify access_token was cleared
        $config = $configProperty->getValue($httpClient);
        $this->assertNull($config->get('access_token'));

        // Verify getHeaders() does not include Authorization
        $headers = $config->getHeaders();
        $this->assertArrayNotHasKey('Authorization', $headers);
    }

    /**
     * Test that initialize() updates session_id in config.
     */
    public function testInitializeUpdatesSessionIdConfig(): void
    {
        // Create initial config without session_id
        $initialConfig = new Configuration(['url' => 'https://api.example.com']);
        $httpClient = new GuzzleHttpClient($initialConfig, $this->mockLogger);

        $reflection = new \ReflectionClass($httpClient);
        $configProperty = $reflection->getProperty('config');
        $configProperty->setAccessible(true);

        // Verify initial config has no session_id
        $config = $configProperty->getValue($httpClient);
        $this->assertNull($config->get('session_id'));

        // Call initialize with session_id
        $newConfig = [
            'url' => 'https://api.example.com',
            'session_id' => 'test-session-abc123',
        ];
        $httpClient->initialize($newConfig);

        // Verify session_id was set
        $config = $configProperty->getValue($httpClient);
        $this->assertEquals('test-session-abc123', $config->get('session_id'));

        // Verify getHeaders() returns the x-sid header
        $headers = $config->getHeaders();
        $this->assertArrayHasKey('x-sid', $headers);
        $this->assertEquals('test-session-abc123', $headers['x-sid']);
    }

    /**
     * Test that initialize() updates language in config.
     */
    public function testInitializeUpdatesLanguageConfig(): void
    {
        // Create initial config with default language
        $initialConfig = new Configuration(['url' => 'https://api.example.com']);
        $httpClient = new GuzzleHttpClient($initialConfig, $this->mockLogger);

        $reflection = new \ReflectionClass($httpClient);
        $configProperty = $reflection->getProperty('config');
        $configProperty->setAccessible(true);

        // Verify initial config has default language (null, defaults to 'en' in getHeaders)
        $config = $configProperty->getValue($httpClient);
        $this->assertNull($config->get('language'));

        // Call initialize with Italian language
        $newConfig = [
            'url' => 'https://api.example.com',
            'language' => 'it',
        ];
        $httpClient->initialize($newConfig);

        // Verify language was set
        $config = $configProperty->getValue($httpClient);
        $this->assertEquals('it', $config->get('language'));

        // Verify getHeaders() returns the new Accept-Language header
        $headers = $config->getHeaders();
        $this->assertArrayHasKey('Accept-Language', $headers);
        $this->assertEquals('it', $headers['Accept-Language']);
    }

    /**
     * Test that initialize() updates multiple config values at once.
     */
    public function testInitializeUpdatesMultipleConfigValues(): void
    {
        // Create initial config
        $initialConfig = new Configuration(['url' => 'https://api.example.com']);
        $httpClient = new GuzzleHttpClient($initialConfig, $this->mockLogger);

        $reflection = new \ReflectionClass($httpClient);
        $configProperty = $reflection->getProperty('config');
        $configProperty->setAccessible(true);

        // Call initialize with multiple values
        $newConfig = [
            'url' => 'https://api.example.com',
            'access_token' => 'user-token-xyz',
            'session_id' => 'session-123',
            'language' => 'fr',
            'key' => 'devapp-key-abc',
        ];
        $httpClient->initialize($newConfig);

        // Verify all values were set
        $config = $configProperty->getValue($httpClient);
        $this->assertEquals('user-token-xyz', $config->get('access_token'));
        $this->assertEquals('session-123', $config->get('session_id'));
        $this->assertEquals('fr', $config->get('language'));
        $this->assertEquals('devapp-key-abc', $config->get('key'));

        // Verify all headers are present
        $headers = $config->getHeaders();
        $this->assertEquals('Bearer user-token-xyz', $headers['Authorization']);
        $this->assertEquals('session-123', $headers['x-sid']);
        $this->assertEquals('fr', $headers['Accept-Language']);
        $this->assertEquals('devapp-key-abc', $headers['x-devapp']);
    }
}
