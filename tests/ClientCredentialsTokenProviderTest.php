<?php

declare(strict_types=1);

namespace Swotto\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\AbstractLogger;
use Stringable;
use Swotto\Auth\ClientCredentialsTokenProvider;
use Swotto\Auth\InMemoryTokenCache;
use Swotto\Exception\ConfigurationException;

/** The client_credentials round (RFC 6749 §4.4): one request per token, early refresh, nothing logged. */
class ClientCredentialsTokenProviderTest extends TestCase
{
    private const TOKEN_URL = 'https://api.example.com/oauth/token';
    private const CLIENT_ID = 'catalog-client';
    private const CLIENT_SECRET = 'SENTINEL_CLIENT_SECRET';
    private const SCOPE = 'category.readonly product.readonly';

    private MockHandler $mock;

    private GuzzleClient $http;

    /** @var list<RequestInterface> Requests that reached the fake token endpoint */
    private array $history = [];

    private TokenProviderSpyLogger $logs;

    private InMemoryTokenCache $cache;

    private int $now = 1_000_000;

    protected function setUp(): void
    {
        $this->mock = new MockHandler();
        $this->history = [];
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::mapRequest(function (RequestInterface $request): RequestInterface {
            $this->history[] = $request;

            return $request;
        }));
        $this->logs = new TokenProviderSpyLogger();
        $this->cache = new InMemoryTokenCache();
        $this->http = new GuzzleClient(['handler' => $stack]);
    }

    public function testRequestsATokenOnceAndReusesIt(): void
    {
        $this->mock->append($this->tokenResponse('first-token', 3600), $this->tokenResponse('second-token', 3600));
        $provider = $this->provider();

        $this->assertSame('first-token', $provider->token());
        $this->assertSame('first-token', $provider->token());

        $this->assertCount(1, $this->history);
        $request = $this->history[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(self::TOKEN_URL, (string) $request->getUri());
        $this->assertSame(
            'Basic ' . base64_encode(self::CLIENT_ID . ':' . self::CLIENT_SECRET),
            $request->getHeaderLine('Authorization')
        );
        parse_str((string) $request->getBody(), $form);
        $this->assertSame(['grant_type' => 'client_credentials', 'scope' => self::SCOPE], $form);
    }

    public function testEmptyScopeIsNotSent(): void
    {
        $this->mock->append($this->tokenResponse('first-token', 3600));

        $this->provider(scope: '')->token();

        parse_str((string) $this->history[0]->getBody(), $form);
        $this->assertSame(['grant_type' => 'client_credentials'], $form);
    }

    public function testRefreshesBeforeExpiry(): void
    {
        $this->mock->append($this->tokenResponse('first-token', 40), $this->tokenResponse('second-token', 40));
        $provider = $this->provider();

        $this->assertSame('first-token', $provider->token());

        $this->now += 9;
        $this->assertSame('first-token', $provider->token());
        $this->assertCount(1, $this->history);

        $this->now += 2;
        $this->assertSame('second-token', $provider->token());
        $this->assertCount(2, $this->history);
    }

    public function testInvalidateForcesANewToken(): void
    {
        $this->mock->append($this->tokenResponse('first-token', 3600), $this->tokenResponse('second-token', 3600));
        $provider = $this->provider();

        $this->assertSame('first-token', $provider->token());
        $provider->invalidate();

        $this->assertSame('second-token', $provider->token());
        $this->assertCount(2, $this->history);
    }

    public function testCachedTokenUnderTheDocumentedKeyIsReused(): void
    {
        $key = hash('sha256', self::TOKEN_URL . '|' . self::CLIENT_ID . '|' . self::SCOPE);
        $this->cache->set($key, ['access_token' => 'seeded-token', 'expires_at' => $this->now + 3600], 3600);

        $this->assertSame('seeded-token', $this->provider()->token());
        $this->assertCount(0, $this->history);
    }

    public function testTwoProvidersWithDistinctCachesDoNotShareTheToken(): void
    {
        $this->mock->append($this->tokenResponse('first-token', 3600), $this->tokenResponse('second-token', 3600));

        $this->assertSame('first-token', $this->provider()->token());
        $this->cache = new InMemoryTokenCache();
        $this->assertSame('second-token', $this->provider()->token());
    }

    public function testRejectedCredentialsRaiseConfigurationException(): void
    {
        $body = (string) json_encode(['error' => 'invalid_client', 'error_description' => 'SENTINEL_REJECTION_BODY']);
        $this->mock->append(new Response(401, ['Content-Type' => 'application/json'], $body), $this->tokenResponse('never', 3600));

        try {
            $this->provider()->token();
            $this->fail('A rejected client must raise ConfigurationException');
        } catch (ConfigurationException $e) {
            $this->assertSame('SW4 rejected the client credentials', $e->getMessage());
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('SENTINEL_REJECTION_BODY', (string) $e);
            $this->assertStringNotContainsString(self::CLIENT_SECRET, (string) $e);
        }

        $this->assertCount(1, $this->history);
        $this->assertStringNotContainsString('SENTINEL_REJECTION_BODY', serialize($this->logs->entries));
    }

    public function testBadRequestFromTheTokenEndpointIsAlsoARejection(): void
    {
        $this->mock->append(new Response(400, [], '{"error":"invalid_scope"}'));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('SW4 rejected the client credentials');

        $this->provider()->token();
    }

    public function testMalformedTokenResponseIsNotAConfigurationError(): void
    {
        $this->mock->append(new Response(200, [], '{"token_type":"Bearer","SENTINEL":"x"}'));

        try {
            $this->provider()->token();
            $this->fail('A malformed token response must raise');
        } catch (ConfigurationException $e) {
            $this->fail('A malformed response is not a configuration error');
        } catch (\Swotto\Exception\SwottoException $e) {
            $this->assertStringNotContainsString('SENTINEL', (string) $e);
            $this->assertNull($e->getPrevious());
        }
    }

    public function testTokenResponseIsNeverLogged(): void
    {
        $this->mock->append($this->tokenResponse('SENTINEL_ACCESS_TOKEN', 3600));

        $this->provider()->token();

        $this->assertNotEmpty($this->logs->entries, 'The provider must leave a trace, or this test proves nothing');
        $serialized = serialize($this->logs->entries);
        $this->assertStringNotContainsString('access_token', $serialized);
        $this->assertStringNotContainsString('SENTINEL_ACCESS_TOKEN', $serialized);
        $this->assertStringNotContainsString(self::CLIENT_SECRET, $serialized);
        $this->assertStringNotContainsString(base64_encode(self::CLIENT_ID . ':' . self::CLIENT_SECRET), $serialized);
    }

    private function provider(string $scope = self::SCOPE): ClientCredentialsTokenProvider
    {
        return new ClientCredentialsTokenProvider(
            http: $this->http,
            tokenUrl: self::TOKEN_URL,
            clientId: self::CLIENT_ID,
            clientSecret: self::CLIENT_SECRET,
            scope: $scope,
            cache: $this->cache,
            logger: $this->logs,
            earlyRefreshSeconds: 30,
            clock: fn (): int => $this->now,
        );
    }

    private function tokenResponse(string $token, int $expiresIn): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
            'access_token' => $token,
            'scope' => self::SCOPE,
        ]));
    }
}

final class TokenProviderSpyLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $entries = [];

    /** @param array<string, mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->entries[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
