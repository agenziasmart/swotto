<?php

declare(strict_types=1);

namespace Swotto\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;
use Swotto\Config\Configuration;
use Swotto\Http\GuzzleHttpClient;

/**
 * RedirectSecurityTest.
 *
 * The DevApp token identifies the application and must stay bound to the configured
 * origin. Guzzle strips Authorization and Cookie across origins on its own, but knows
 * nothing about the SDK's own headers.
 */
class RedirectSecurityTest extends TestCase
{
    private const DEVAPP_TOKEN = 'PLACEHOLDER_DEVAPP_TOKEN';

    /**
     * Requests that reached the transport, recorded after every middleware has run.
     *
     * @var array<int, RequestInterface>
     */
    private array $sent = [];

    /**
     * Build an HTTP client whose transport is mocked, keeping the SDK's own handler stack
     * (and therefore its origin guard) in place.
     *
     * @param array<int, Response> $responses Queued responses
     */
    private function createClientWithResponses(string $baseUrl, array $responses): GuzzleHttpClient
    {
        $config = new Configuration([
            'url' => $baseUrl,
            'key' => self::DEVAPP_TOKEN,
        ]);

        $httpClient = new GuzzleHttpClient($config, new NullLogger());

        // Rebuild the same stack the SDK installs, then swap the innermost handler for the
        // mock: the guard and RedirectMiddleware keep their real positions.
        $reflection = new \ReflectionClass($httpClient);
        $buildStack = $reflection->getMethod('buildHandlerStack');
        $buildStack->setAccessible(true);
        /** @var HandlerStack $stack */
        $stack = $buildStack->invoke($httpClient, $baseUrl);
        $stack->setHandler(new MockHandler($responses));

        // Recorded last in the chain, so what is captured is what the transport receives —
        // after the origin guard has had its say.
        $this->sent = [];
        $stack->push(function (callable $handler): callable {
            return function (RequestInterface $request, array $options) use ($handler) {
                $this->sent[] = $request;

                return $handler($request, $options);
            };
        });

        $guzzle = new GuzzleClient([
            'base_uri' => $baseUrl,
            'headers' => ['Accept' => 'application/json', 'x-devapp' => self::DEVAPP_TOKEN],
            'allow_redirects' => ['max' => 5, 'protocols' => ['http', 'https'], 'strict' => true],
            'handler' => $stack,
        ]);

        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($httpClient, $guzzle);

        return $httpClient;
    }

    /**
     * @return array<int, RequestInterface>
     */
    private function sentRequests(): array
    {
        return $this->sent;
    }

    public function testDevAppTokenIsStrippedOnCrossOriginRedirect(): void
    {
        $client = $this->createClientWithResponses('https://api.example.com', [
            new Response(302, ['Location' => 'https://attacker.example/collect']),
            new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}'),
        ]);

        $client->request('GET', 'customers');

        $requests = $this->sentRequests();
        $this->assertCount(2, $requests, 'The redirect should have been followed');

        $this->assertSame(
            self::DEVAPP_TOKEN,
            $requests[0]->getHeaderLine('x-devapp'),
            'The first request goes to the configured origin and keeps the token'
        );
        $this->assertSame(
            '',
            $requests[1]->getHeaderLine('x-devapp'),
            'The DevApp token must not follow a redirect to another host'
        );
        $this->assertSame('', $requests[1]->getHeaderLine('X-Swotto-Client-Info'));
    }

    public function testDevAppTokenSurvivesSameOriginRedirect(): void
    {
        $client = $this->createClientWithResponses('https://api.example.com', [
            new Response(302, ['Location' => 'https://api.example.com/customers/v2']),
            new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}'),
        ]);

        $client->request('GET', 'customers');

        $requests = $this->sentRequests();
        $this->assertCount(2, $requests);
        $this->assertSame(self::DEVAPP_TOKEN, $requests[1]->getHeaderLine('x-devapp'));
    }

    /**
     * Guzzle's own protection, asserted here so a future configuration change cannot
     * silently remove it.
     */
    public function testAuthorizationIsStrippedOnCrossOriginRedirect(): void
    {
        $client = $this->createClientWithResponses('https://api.example.com', [
            new Response(302, ['Location' => 'https://attacker.example/collect']),
            new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}'),
        ]);

        $client->request('GET', 'customers', ['bearer_token' => 'PLACEHOLDER_USER_TOKEN']);

        $requests = $this->sentRequests();
        $this->assertStringContainsString('PLACEHOLDER_USER_TOKEN', $requests[0]->getHeaderLine('Authorization'));
        $this->assertSame('', $requests[1]->getHeaderLine('Authorization'));
    }

    /**
     * An https base URL must not be downgraded to cleartext by a redirect.
     */
    public function testHttpsBaseUrlRefusesProtocolDowngrade(): void
    {
        $config = new Configuration(['url' => 'https://api.example.com', 'key' => self::DEVAPP_TOKEN]);
        $httpClient = new GuzzleHttpClient($config, new NullLogger());

        $reflection = new \ReflectionClass($httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        /** @var GuzzleClient $guzzle */
        $guzzle = $clientProperty->getValue($httpClient);

        $redirects = $guzzle->getConfig('allow_redirects');

        $this->assertSame(['https'], $redirects['protocols']);
        $this->assertSame(5, $redirects['max']);
    }

    /**
     * A local http base URL still works: the Docker development setup talks to the API
     * over http://host.docker.internal:8081.
     */
    public function testHttpBaseUrlKeepsBothProtocols(): void
    {
        $config = new Configuration(['url' => 'http://host.docker.internal:8081']);
        $httpClient = new GuzzleHttpClient($config, new NullLogger());

        $reflection = new \ReflectionClass($httpClient);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        /** @var GuzzleClient $guzzle */
        $guzzle = $clientProperty->getValue($httpClient);

        $this->assertSame(['http', 'https'], $guzzle->getConfig('allow_redirects')['protocols']);
    }
}
