<?php

declare(strict_types=1);

namespace Swotto\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Swotto\Auth\ClientCredentialsTokenProvider;
use Swotto\Config\Configuration;
use Swotto\Exception\AuthenticationException;
use Swotto\Http\GuzzleHttpClient;
use Swotto\Retry\RetryHttpClient;
use Swotto\SwottoClient;

/**
 * ClientCredentialsHttpTest.
 *
 * The client_credentials round inside the HTTP client: the machine Bearer on every call,
 * one renewal after a Bearer challenge, and nothing else changed on the repeated request.
 * The token endpoint and the API share one mocked transport, so the recorded sequence is
 * the order in which the SDK really talks to SW4.
 */
class ClientCredentialsHttpTest extends TestCase
{
    private const BASE_URL = 'https://api.example.com';

    private const TOKEN_A = 'PLACEHOLDER_ACCESS_TOKEN_AAAA';

    private const TOKEN_B = 'PLACEHOLDER_ACCESS_TOKEN_BBBB';

    private const BEARER_CHALLENGE = 'Bearer realm="sw4", error="invalid_token"';

    /**
     * Requests that reached the transport, in order.
     *
     * @var array<int, RequestInterface>
     */
    private array $sent = [];

    /**
     * Bodies as they were on the wire, read when each request was sent.
     *
     * @var array<int, string>
     */
    private array $sentBodies = [];

    /**
     * @param array<int, Response> $responses Queued responses, token endpoint included
     * @return callable(RequestInterface, array<string, mixed>): PromiseInterface
     */
    private function transport(array $responses): callable
    {
        $mock = new MockHandler($responses);
        $this->sent = [];
        $this->sentBodies = [];

        return function (RequestInterface $request, array $options) use ($mock): PromiseInterface {
            $this->sent[] = $request;
            $this->sentBodies[] = (string) $request->getBody();

            return $mock($request, $options);
        };
    }

    /**
     * @param array<int, Response> $responses
     */
    private function machineClient(array $responses, ?\Psr\Log\LoggerInterface $logger = null): GuzzleHttpClient
    {
        $logger ??= new NullLogger();
        $config = new Configuration([
            'url' => self::BASE_URL,
            'client_id' => 'PLACEHOLDER_CLIENT_ID',
            'client_secret' => 'PLACEHOLDER_CLIENT_SECRET',
            'scope' => 'product.readonly',
        ]);
        $handler = $this->transport($responses);
        $provider = ClientCredentialsTokenProvider::fromConfiguration($config, $logger, $handler);

        return new GuzzleHttpClient($config, $logger, $provider, $handler);
    }

    private static function token(string $accessToken): Response
    {
        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            (string) json_encode(['access_token' => $accessToken, 'token_type' => 'Bearer', 'expires_in' => 3600])
        );
    }

    private static function unauthorized(string $challenge): Response
    {
        return new Response(
            401,
            ['Content-Type' => 'application/json', 'WWW-Authenticate' => $challenge],
            (string) json_encode(['error' => ['message' => 'Unauthorized']])
        );
    }

    private static function ok(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['data' => ['ok' => true]]));
    }

    public function testAddsTheMachineBearerToEveryCall(): void
    {
        $client = $this->machineClient([self::token(self::TOKEN_A), self::ok(), self::ok()]);

        $client->request('GET', 'product');
        $client->request('GET', 'category');

        $this->assertCount(3, $this->sent, 'one token request, then two API calls reusing the token');
        $this->assertSame('/oauth/token', $this->sent[0]->getUri()->getPath());
        $this->assertSame('POST', $this->sent[0]->getMethod());
        $this->assertStringStartsWith('Basic ', $this->sent[0]->getHeaderLine('Authorization'));
        $this->assertSame('Bearer ' . self::TOKEN_A, $this->sent[1]->getHeaderLine('Authorization'));
        $this->assertSame('Bearer ' . self::TOKEN_A, $this->sent[2]->getHeaderLine('Authorization'));
        $this->assertSame('/product', $this->sent[1]->getUri()->getPath());
        $this->assertFalse($this->sent[1]->hasHeader('x-devapp'));
    }

    public function testRetriesOnceWithANewTokenAfterA401BearerChallenge(): void
    {
        $client = $this->machineClient([
            self::token(self::TOKEN_A),
            self::unauthorized(self::BEARER_CHALLENGE),
            self::token(self::TOKEN_B),
            self::ok(),
        ]);

        $result = $client->request('POST', 'rfq', [
            'json' => ['product' => 'P-1', 'qty' => 3],
            'query' => ['lang' => 'it'],
            'headers' => ['Idempotency-Key' => 'PLACEHOLDER_IDEMPOTENCY_KEY'],
        ]);

        $this->assertSame(['data' => ['ok' => true]], $result);
        $this->assertCount(4, $this->sent);
        $this->assertSame('/oauth/token', $this->sent[2]->getUri()->getPath());

        $first = $this->sent[1];
        $retry = $this->sent[3];
        $this->assertSame('Bearer ' . self::TOKEN_A, $first->getHeaderLine('Authorization'));
        $this->assertSame('Bearer ' . self::TOKEN_B, $retry->getHeaderLine('Authorization'));
        $this->assertSame($first->getMethod(), $retry->getMethod());
        $this->assertSame((string) $first->getUri(), (string) $retry->getUri());
        $this->assertSame($this->sentBodies[1], $this->sentBodies[3]);
        $this->assertNotSame('', $this->sentBodies[3]);
        $this->assertSame('PLACEHOLDER_IDEMPOTENCY_KEY', $retry->getHeaderLine('Idempotency-Key'));

        // Every header but Authorization is identical on the repeated request.
        $firstHeaders = $first->withoutHeader('Authorization')->getHeaders();
        $retryHeaders = $retry->withoutHeader('Authorization')->getHeaders();
        $this->assertSame($firstHeaders, $retryHeaders);
    }

    public function testDoesNotRetryA401WithoutBearerChallenge(): void
    {
        $client = $this->machineClient([
            self::token(self::TOKEN_A),
            self::unauthorized('Basic realm="sw4"'),
            self::token(self::TOKEN_B),
            self::ok(),
        ]);

        try {
            $client->request('GET', 'product');
            $this->fail('A 401 with a Basic challenge must not be retried');
        } catch (AuthenticationException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }

        $this->assertCount(2, $this->sent);
    }

    public function testASecond401IsNotRetried(): void
    {
        $client = $this->machineClient([
            self::token(self::TOKEN_A),
            self::unauthorized(self::BEARER_CHALLENGE),
            self::token(self::TOKEN_B),
            self::unauthorized(self::BEARER_CHALLENGE),
            self::token('PLACEHOLDER_ACCESS_TOKEN_CCCC'),
            self::ok(),
        ]);

        try {
            $client->request('GET', 'product');
            $this->fail('The second 401 must come out as an exception');
        } catch (AuthenticationException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }

        $this->assertCount(4, $this->sent);
    }

    public function testKeyModeStillSendsXDevapp(): void
    {
        $config = new Configuration(['url' => self::BASE_URL, 'key' => 'PLACEHOLDER_DEVAPP_TOKEN']);
        $client = new GuzzleHttpClient($config, new NullLogger(), null, $this->transport([self::ok()]));

        $client->request('GET', 'product');

        $this->assertCount(1, $this->sent, 'no token request in key mode');
        $this->assertSame('/product', $this->sent[0]->getUri()->getPath());
        $this->assertSame('PLACEHOLDER_DEVAPP_TOKEN', $this->sent[0]->getHeaderLine('x-devapp'));
        $this->assertFalse($this->sent[0]->hasHeader('Authorization'));
    }

    public function testBearerTokenModeDoesNotRetryA401BearerChallenge(): void
    {
        $config = new Configuration(['url' => self::BASE_URL, 'bearer_token' => 'PLACEHOLDER_ACCOUNT_TOKEN']);
        $client = new GuzzleHttpClient(
            $config,
            new NullLogger(),
            null,
            $this->transport([self::unauthorized(self::BEARER_CHALLENGE), self::ok()])
        );

        try {
            $client->request('GET', 'rfq', ['bearer_token' => 'PLACEHOLDER_ACCOUNT_TOKEN']);
            $this->fail('An Account token cannot be renewed by the SDK');
        } catch (AuthenticationException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }

        $this->assertCount(1, $this->sent);
    }

    public function testANonRewindableBodyIsNotRetried(): void
    {
        $client = $this->machineClient([
            self::token(self::TOKEN_A),
            self::unauthorized(self::BEARER_CHALLENGE),
            self::token(self::TOKEN_B),
            self::ok(),
        ]);

        try {
            $client->request('POST', 'rfq', ['body' => new NoSeekStream(Utils::streamFor('{"product":"P-1"}'))]);
            $this->fail('A body that cannot be replayed must not be sent again');
        } catch (AuthenticationException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }

        $this->assertCount(2, $this->sent, 'no second attempt with an empty body');
    }

    public function testRawRequestAlsoRetriesOnceAfterABearerChallenge(): void
    {
        $client = $this->machineClient([
            self::token(self::TOKEN_A),
            self::unauthorized(self::BEARER_CHALLENGE),
            self::token(self::TOKEN_B),
            self::ok(),
        ]);

        $response = $client->requestRaw('GET', 'product');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(4, $this->sent);
        $this->assertSame('Bearer ' . self::TOKEN_B, $this->sent[3]->getHeaderLine('Authorization'));
    }

    public function testThePerCallAuthorizationOfTheCallerIsNeitherReplacedNorRetried(): void
    {
        $client = $this->machineClient([self::unauthorized(self::BEARER_CHALLENGE), self::token(self::TOKEN_A), self::ok()]);

        try {
            $client->request('GET', 'rfq', ['bearer_token' => 'PLACEHOLDER_ACCOUNT_TOKEN']);
            $this->fail('A caller-supplied Authorization is not the SDK\'s to renew');
        } catch (AuthenticationException) {
        }

        $this->assertCount(1, $this->sent, 'no token request, no retry');
        $this->assertSame('Bearer PLACEHOLDER_ACCOUNT_TOKEN', $this->sent[0]->getHeaderLine('Authorization'));
    }

    public function testTheGenericRetryDoesNotMultiplyTheBearerRetry(): void
    {
        $config = new Configuration([
            'url' => self::BASE_URL,
            'client_id' => 'PLACEHOLDER_CLIENT_ID',
            'client_secret' => 'PLACEHOLDER_CLIENT_SECRET',
            'retry_enabled' => true,
            'retry_max_attempts' => 3,
            'retry_initial_delay_ms' => 1,
        ]);
        $handler = $this->transport([
            self::token(self::TOKEN_A),
            self::unauthorized(self::BEARER_CHALLENGE),
            self::token(self::TOKEN_B),
            self::unauthorized(self::BEARER_CHALLENGE),
            self::token('PLACEHOLDER_ACCESS_TOKEN_CCCC'),
            self::ok(),
        ]);
        $provider = ClientCredentialsTokenProvider::fromConfiguration($config, new NullLogger(), $handler);
        $client = new RetryHttpClient(new GuzzleHttpClient($config, new NullLogger(), $provider, $handler), $config);

        try {
            $client->request('GET', 'product');
            $this->fail('The second 401 must come out as an exception');
        } catch (AuthenticationException) {
        }

        $this->assertCount(4, $this->sent);
    }

    public function testNoAccessTokenReachesTheLogDuringTheWholeRound(): void
    {
        $logger = new class () extends AbstractLogger {
            /** @var array<int, string> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = $level . ' ' . $message . ' ' . json_encode($context);
            }
        };

        $client = $this->machineClient([
            self::token(self::TOKEN_A),
            self::unauthorized(self::BEARER_CHALLENGE),
            self::token(self::TOKEN_B),
            self::ok(),
        ], $logger);

        $client->request('POST', 'rfq', ['json' => ['product' => 'P-1']]);

        $this->assertCount(4, $this->sent, 'the whole round ran');
        $all = implode("\n", $logger->records);
        $this->assertStringContainsString('Requesting POST rfq', $all, 'the spy did record the SDK log');
        $this->assertStringNotContainsString(self::TOKEN_A, $all);
        $this->assertStringNotContainsString(self::TOKEN_B, $all);
        $this->assertStringNotContainsString('access_token', $all);
        $this->assertStringNotContainsString('PLACEHOLDER_CLIENT_SECRET', $all);
    }

    public function testSwottoClientWiresTheProviderOnlyForClientId(): void
    {
        $machine = new SwottoClient([
            'url' => self::BASE_URL,
            'client_id' => 'PLACEHOLDER_CLIENT_ID',
            'client_secret' => 'PLACEHOLDER_CLIENT_SECRET',
        ]);
        $devapp = new SwottoClient(['url' => self::BASE_URL, 'key' => 'PLACEHOLDER_DEVAPP_TOKEN']);

        $this->assertInstanceOf(ClientCredentialsTokenProvider::class, self::providerOf($machine));
        $this->assertNull(self::providerOf($devapp));
    }

    private static function providerOf(SwottoClient $client): ?ClientCredentialsTokenProvider
    {
        $http = (new \ReflectionProperty(SwottoClient::class, 'httpClient'))->getValue($client);
        self::assertInstanceOf(GuzzleHttpClient::class, $http);

        $provider = (new \ReflectionProperty(GuzzleHttpClient::class, 'tokenProvider'))->getValue($http);
        self::assertTrue($provider === null || $provider instanceof ClientCredentialsTokenProvider);

        return $provider;
    }
}
