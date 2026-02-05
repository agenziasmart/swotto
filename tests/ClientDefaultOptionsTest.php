<?php

declare(strict_types=1);

namespace Swotto\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Swotto\Client;
use Swotto\Contract\HttpClientInterface;

/**
 * ClientDefaultOptionsTest.
 *
 * Tests for the defaultOptions + merge pattern (Stripe-inspired).
 * Verifies that config-level context options (bearer_token, language, session_id,
 * client_ip, client_user_agent) are extracted as defaults and merged with per-call options.
 */
class ClientDefaultOptionsTest extends TestCase
{
    private HttpClientInterface $mockHttpClient;

    private LoggerInterface $mockLogger;

    protected function setUp(): void
    {
        $this->mockHttpClient = $this->createMock(HttpClientInterface::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);
    }

    public function testDefaultBearerTokenIsPassedOnEveryRequest(): void
    {
        $client = new Client(
            ['url' => 'https://api.example.com', 'bearer_token' => 'default-token-123'],
            $this->mockLogger,
            $this->mockHttpClient
        );

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'test', ['bearer_token' => 'default-token-123'])
            ->willReturn(['data' => 'ok']);

        $client->get('test');
    }

    public function testDefaultLanguageIsPassedOnEveryRequest(): void
    {
        $client = new Client(
            ['url' => 'https://api.example.com', 'language' => 'it'],
            $this->mockLogger,
            $this->mockHttpClient
        );

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'test', ['language' => 'it'])
            ->willReturn(['data' => 'ok']);

        $client->get('test');
    }

    public function testPerCallOptionOverridesDefault(): void
    {
        $client = new Client(
            ['url' => 'https://api.example.com', 'bearer_token' => 'default-token'],
            $this->mockLogger,
            $this->mockHttpClient
        );

        // Per-call bearer_token should override default
        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'test', ['bearer_token' => 'per-call-token'])
            ->willReturn(['data' => 'ok']);

        $client->get('test', ['bearer_token' => 'per-call-token']);
    }

    public function testNoDefaultsNoPerCallMeansEmptyOptions(): void
    {
        $client = new Client(
            ['url' => 'https://api.example.com'],
            $this->mockLogger,
            $this->mockHttpClient
        );

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'test', [])
            ->willReturn(['data' => 'ok']);

        $client->get('test');
    }

    public function testMultipleDefaultsMergedWithPerCall(): void
    {
        $client = new Client(
            [
                'url' => 'https://api.example.com',
                'bearer_token' => 'default-token',
                'language' => 'it',
                'session_id' => 'sess-abc',
            ],
            $this->mockLogger,
            $this->mockHttpClient
        );

        // Per-call overrides language, keeps defaults for bearer_token and session_id, adds query
        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'test',
                $this->callback(function ($options) {
                    return $options['bearer_token'] === 'default-token'
                        && $options['language'] === 'fr' // overridden
                        && $options['session_id'] === 'sess-abc'
                        && $options['query'] === ['page' => 1];
                })
            )
            ->willReturn(['data' => 'ok']);

        $client->get('test', ['language' => 'fr', 'query' => ['page' => 1]]);
    }

    public function testDefaultOptionsAreImmutable(): void
    {
        $client = new Client(
            ['url' => 'https://api.example.com', 'bearer_token' => 'immutable-token'],
            $this->mockLogger,
            $this->mockHttpClient
        );

        // First call overrides bearer_token per-call
        $this->mockHttpClient->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function (string $method, string $uri, array $options) {
                return ['token' => $options['bearer_token'] ?? null];
            });

        $result1 = $client->get('test', ['bearer_token' => 'temp-token']);
        $this->assertEquals('temp-token', $result1['token']);

        // Second call should still use original default
        $result2 = $client->get('test');
        $this->assertEquals('immutable-token', $result2['token']);
    }

    public function testEmptyBearerTokenNotIncludedInDefaults(): void
    {
        $client = new Client(
            ['url' => 'https://api.example.com', 'bearer_token' => ''],
            $this->mockLogger,
            $this->mockHttpClient
        );

        // Empty bearer_token should not be included in defaults
        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'test', [])
            ->willReturn(['data' => 'ok']);

        $client->get('test');
    }

    public function testDefaultOptionsForPostWithData(): void
    {
        $client = new Client(
            ['url' => 'https://api.example.com', 'bearer_token' => 'post-token'],
            $this->mockLogger,
            $this->mockHttpClient
        );

        // POST with data: defaults should be merged, data goes to json key
        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'test',
                $this->callback(function ($options) {
                    return $options['bearer_token'] === 'post-token'
                        && $options['json'] === ['name' => 'Test'];
                })
            )
            ->willReturn(['data' => 'created']);

        $client->post('test', ['name' => 'Test']);
    }

    public function testDefaultClientIpAndUserAgent(): void
    {
        $client = new Client(
            [
                'url' => 'https://api.example.com',
                'client_ip' => '192.168.1.1',
                'client_user_agent' => 'TestApp/1.0',
            ],
            $this->mockLogger,
            $this->mockHttpClient
        );

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'test',
                $this->callback(function ($options) {
                    return $options['client_ip'] === '192.168.1.1'
                        && $options['client_user_agent'] === 'TestApp/1.0';
                })
            )
            ->willReturn(['data' => 'ok']);

        $client->get('test');
    }
}
