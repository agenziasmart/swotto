<?php

declare(strict_types=1);

namespace Swotto\Auth;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Swotto\Config\Configuration;
use Swotto\Contract\TokenCacheInterface;
use Swotto\Exception\ApiException;
use Swotto\Exception\ConfigurationException;
use Swotto\Exception\NetworkException;
use Swotto\Http\LogSanitizer;

/**
 * ClientCredentialsTokenProvider.
 *
 * Obtains and keeps an access token with the OAuth 2.0 client_credentials grant
 * (RFC 6749 §4.4). The token is held per instance (or in the injected cache), is
 * refreshed `earlyRefreshSeconds` before expiry, and never reaches a log or an
 * exception message: neither does the token endpoint's response body nor the secret.
 *
 * The token request uses its own HTTP client, outside the SDK's logging pipeline,
 * with `http_errors` off so Guzzle never builds an exception carrying the body.
 */
final class ClientCredentialsTokenProvider
{
    private const REJECTED = 'SW4 rejected the client credentials';

    private readonly string $cacheKey;

    /**
     * @var \Closure(): int
     */
    private readonly \Closure $clock;

    /**
     * @param ClientInterface $http HTTP client for the token endpoint (no SDK logging middleware)
     * @param string $tokenUrl Absolute URL of the token endpoint
     * @param string $clientId OAuth client id
     * @param string $clientSecret OAuth client secret
     * @param string $scope Space-separated scopes; empty means "not sent"
     * @param TokenCacheInterface $cache Token store
     * @param LoggerInterface $logger Receives content-free traces only
     * @param int $earlyRefreshSeconds Seconds before expiry at which the token is renewed
     * @param (\Closure(): int)|null $clock Current unix time; defaults to time()
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $tokenUrl,
        private readonly string $clientId,
        #[SensitiveParameter]
        private readonly string $clientSecret,
        private readonly string $scope,
        private readonly TokenCacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly int $earlyRefreshSeconds = 30,
        ?\Closure $clock = null,
    ) {
        $this->cacheKey = hash('sha256', $tokenUrl . '|' . $clientId . '|' . $scope);
        $this->clock = $clock ?? time(...);
    }

    /**
     * Build the provider from a validated client_id configuration.
     *
     * The token endpoint is `{url}/oauth/token`. The HTTP client is a plain Guzzle client of
     * its own — not the SDK's request pipeline — so neither the token request nor its response
     * body passes through the SDK's request logging. Redirects are refused: the Basic credentials
     * go to the configured endpoint or nowhere. The cache is `token_cache` when given, otherwise a
     * new per-instance InMemoryTokenCache.
     *
     * @param Configuration $config Configuration with client_id and client_secret
     * @param LoggerInterface $logger Receives content-free traces only
     * @param callable|null $handler Innermost Guzzle handler (tests); null uses the default transport
     *
     * @throws ConfigurationException When the configuration has no client_id
     */
    public static function fromConfiguration(
        Configuration $config,
        LoggerInterface $logger,
        ?callable $handler = null,
    ): self {
        $clientId = $config->get('client_id');
        $clientSecret = $config->get('client_secret');
        if (!is_string($clientId) || $clientId === '' || !is_string($clientSecret)) {
            throw new ConfigurationException('client_credentials requires client_id and client_secret');
        }

        $scope = $config->get('scope', '');
        $cache = $config->get('token_cache');

        $http = new GuzzleClient([
            'handler' => HandlerStack::create($handler),
            'timeout' => $config->get('timeout', 10),
            'verify' => $config->get('verify_ssl', true),
            'allow_redirects' => false,
        ]);

        return new self(
            $http,
            $config->getBaseUrl() . '/oauth/token',
            $clientId,
            $clientSecret,
            is_string($scope) ? $scope : '',
            $cache instanceof TokenCacheInterface ? $cache : new InMemoryTokenCache(),
            $logger,
        );
    }

    /**
     * Return a valid access token, requesting a new one when needed.
     *
     * @return string Access token
     *
     * @throws ConfigurationException When the token endpoint rejects the credentials (400/401)
     * @throws ApiException When the token endpoint answers with an unusable response
     * @throws NetworkException When the token endpoint cannot be reached
     */
    public function token(): string
    {
        $now = ($this->clock)();
        $cached = $this->cache->get($this->cacheKey);
        if ($cached !== null && $cached['expires_at'] - $this->earlyRefreshSeconds > $now) {
            return $cached['access_token'];
        }

        $token = $this->requestToken($now);
        $this->cache->set($this->cacheKey, $token, max(1, $token['expires_at'] - $now));

        return $token['access_token'];
    }

    /**
     * Drop the current token: the next token() call requests a new one.
     */
    public function invalidate(): void
    {
        $this->cache->delete($this->cacheKey);
    }

    /**
     * @return array{access_token: string, expires_at: int}
     */
    private function requestToken(int $now): array
    {
        $form = ['grant_type' => 'client_credentials'];
        if ($this->scope !== '') {
            $form['scope'] = $this->scope;
        }

        $this->logger->debug('Requesting a client_credentials token', ['token_url' => LogSanitizer::uri($this->tokenUrl)]);

        try {
            $response = $this->http->request('POST', $this->tokenUrl, [
                'auth' => [$this->clientId, $this->clientSecret],
                'form_params' => $form,
                'headers' => ['Accept' => 'application/json'],
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            // The previous exception is dropped on purpose: it holds the request, Basic header included.
            $this->logger->warning('Token endpoint unreachable', ['exception_type' => $e::class]);

            throw new NetworkException('SW4 token endpoint unreachable');
        }

        $status = $response->getStatusCode();
        if ($status === 400 || $status === 401) {
            $this->logger->warning(self::REJECTED, ['status' => $status]);

            throw new ConfigurationException(self::REJECTED, [], $status);
        }

        $data = $status === 200 ? json_decode((string) $response->getBody(), true) : null;
        $accessToken = is_array($data) ? ($data['access_token'] ?? null) : null;
        $expiresIn = is_array($data) ? ($data['expires_in'] ?? null) : null;

        if (!is_string($accessToken) || $accessToken === '' || !is_int($expiresIn) || $expiresIn <= 0) {
            $this->logger->warning('Token endpoint returned an unusable response', ['status' => $status]);

            throw new ApiException('SW4 token endpoint returned an unusable response', [], $status);
        }

        $this->logger->debug('Client_credentials token obtained', ['expires_in' => $expiresIn]);

        return ['access_token' => $accessToken, 'expires_at' => $now + $expiresIn];
    }
}
