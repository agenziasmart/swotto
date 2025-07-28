<?php

declare(strict_types=1);

namespace Swotto;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use Swotto\Config\Configuration;
use Swotto\Contract\ClientInterface;
use Swotto\Contract\HttpClientInterface;
use Swotto\Exception\ConnectionException;
use Swotto\Http\GuzzleHttpClient;
use Swotto\Trait\PopTrait;

/**
 * Client.
 *
 * Main Swotto API Client implementation
 */
class Client implements ClientInterface
{
    use PopTrait;

    /**
     * @var HttpClientInterface HTTP client implementation
     */
    private HttpClientInterface $httpClient;

    /**
     * @var LoggerInterface Logger instance
     */
    private LoggerInterface $logger;

    /**
     * @var Configuration Client configuration
     */
    private Configuration $config;

    /**
     * @var CacheInterface|null Cache implementation
     */
    private ?CacheInterface $cache;

    /**
     * @var EventDispatcherInterface|null Event dispatcher
     */
    private ?EventDispatcherInterface $eventDispatcher;

    /**
     * Constructor.
     *
     * @param array<string, mixed> $config Configuration options
     * @param LoggerInterface|null $logger Optional logger
     * @param HttpClientInterface|null $httpClient Optional HTTP client implementation
     * @param CacheInterface|null $cache Optional cache implementation
     * @param EventDispatcherInterface|null $eventDispatcher Optional event dispatcher
     *
     * @throws \Swotto\Exception\ConfigurationException On invalid configuration
     */
    public function __construct(
        array $config = [],
        ?LoggerInterface $logger = null,
        ?HttpClientInterface $httpClient = null,
        ?CacheInterface $cache = null,
        ?EventDispatcherInterface $eventDispatcher = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->config = new Configuration($config);
        $this->cache = $cache;
        $this->eventDispatcher = $eventDispatcher;

        $this->httpClient = $httpClient ?? new GuzzleHttpClient(
            $this->config,
            $this->logger
        );
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $uri, array $options = []): array
    {
        return $this->httpClient->request('GET', $uri, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function post(string $uri, array $options = []): array
    {
        return $this->httpClient->request('POST', $uri, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function patch(string $uri, array $options = []): array
    {
        return $this->httpClient->request('PATCH', $uri, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function put(string $uri, array $options = []): array
    {
        return $this->httpClient->request('PUT', $uri, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $uri, array $options = []): array
    {
        return $this->httpClient->request('DELETE', $uri, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function checkConnection(): bool
    {
        try {
            $this->get('ping');

            return true;
        } catch (ConnectionException $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function checkAuth(?array $options = null): array
    {
        return $this->get('auth', $options ?? []);
    }

    /**
     * {@inheritdoc}
     */
    public function checkSession(?array $options = null): array
    {
        return $this->get('session', $options ?? []);
    }

    /**
     * {@inheritdoc}
     */
    public function setSessionId(string $sessionId): void
    {
        $this->updateConfig(['session_id' => $sessionId]);
    }

    /**
     * {@inheritdoc}
     */
    public function setLanguage(string $language): void
    {
        $this->updateConfig(['language' => $language]);
    }

    /**
     * {@inheritdoc}
     */
    public function setAccept(string $accept): void
    {
        $this->updateConfig(['accept' => $accept]);
    }

    /**
     * {@inheritdoc}
     */
    public function fetchPop(string $uri, ?array $query = []): array
    {
        // Smart caching for static endpoints
        $cacheKey = $this->getCacheKey($uri, $query ?? []);

        if ($this->isCacheable($uri) && $this->cache && $this->cache->has($cacheKey)) {
            return $this->cache->get($cacheKey);
        }

        $response = $this->get($uri, ['query' => $query ?? []]);
        $data = $response['data'] ?? [];

        // Auto-cache static data
        if ($this->isCacheable($uri) && $this->cache) {
            $cacheTtl = $this->config->get('cache_ttl', 3600); // 1h default
            $this->cache->set($cacheKey, $data, $cacheTtl);
        }

        return $data;
    }

    /**
     * Update client configuration.
     *
     * @param array<string, mixed> $newConfig New configuration options
     * @return void
     */
    private function updateConfig(array $newConfig): void
    {
        $this->config = $this->config->update($newConfig);
        $this->httpClient->initialize($this->config->toArray());
    }

    /**
     * Set client original user agent.
     *
     * @param string $userAgent Original client user agent
     * @return void
     */
    public function setClientUserAgent(string $userAgent): void
    {
        $this->updateConfig(['client_user_agent' => $userAgent]);
    }

    /**
     * Set client original IP.
     *
     * @param string $ip Original client IP
     * @return void
     */
    public function setClientIp(string $ip): void
    {
        $this->updateConfig(['client_ip' => $ip]);
    }

    /**
     * Send a GET request and parse the response.
     *
     * @param string $uri The URI to request
     * @param array $options Request options to apply
     * @return array The parsed response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function getParsed(string $uri, array $options = []): array
    {
        $response = $this->get($uri, $options);

        return $this->parseSwottoResponse($response);
    }

    /**
     * Send a POST request and parse the response.
     *
     * @param string $uri The URI to request
     * @param array $options Request options to apply
     * @return array The parsed response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function postParsed(string $uri, array $options = []): array
    {
        $response = $this->post($uri, $options);

        return $this->parseSwottoResponse($response);
    }

    /**
     * Send a PATCH request and parse the response.
     *
     * @param string $uri The URI to request
     * @param array $options Request options to apply
     * @return array The parsed response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function patchParsed(string $uri, array $options = []): array
    {
        $response = $this->patch($uri, $options);

        return $this->parseSwottoResponse($response);
    }

    /**
     * Send a PUT request and parse the response.
     *
     * @param string $uri The URI to request
     * @param array $options Request options to apply
     * @return array The parsed response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function putParsed(string $uri, array $options = []): array
    {
        $response = $this->put($uri, $options);

        return $this->parseSwottoResponse($response);
    }

    /**
     * Send a DELETE request and parse the response.
     *
     * @param string $uri The URI to request
     * @param array $options Request options to apply
     * @return array The parsed response data
     *
     * @throws \Swotto\Exception\SwottoException On error
     */
    public function deleteParsed(string $uri, array $options = []): array
    {
        $response = $this->delete($uri, $options);

        return $this->parseSwottoResponse($response);
    }

    /**
     * Parse Swotto API response (mirrors DataHandlerTrait::parseDataResponse).
     *
     * @param array $response Raw API response
     * @return array Parsed response with data, paginator, and success
     */
    private function parseSwottoResponse(array $response): array
    {
        $parsedResponse = [
            'data' => [],
            'paginator' => [],
            'success' => false,
        ];

        // Success flag
        $parsedResponse['success'] = isset($response['success']) && $response['success'] === true;

        // Extract data
        if (isset($response['data']) && !empty($response['data'])) {
            $parsedResponse['data'] = $response['data'];
        }

        // Build paginator from meta.pagination
        if (isset($response['meta']) && !empty($response['meta'])) {
            if (isset($response['meta']['pagination']) && !empty($response['meta']['pagination'])) {
                $parsedResponse['paginator'] = $this->buildPaginator($response['meta']['pagination']);
            }
        }

        return $parsedResponse;
    }

    /**
     * Build paginator array (compatible with SW4 APP paginator helper).
     *
     * @param array $pagination Pagination metadata from API response
     * @return array Formatted paginator data
     */
    private function buildPaginator(array $pagination): array
    {
        if (empty($pagination)) {
            return [];
        }

        $delta = 1;
        $current = $pagination['current_page'] ?? null;
        $perPage = $pagination['per_page'] ?? null;
        $last = $pagination['total_pages'] ?? null;
        $results = $pagination['total'] ?? null;
        $range = [];

        if ($last > 1) {
            for ($i = max(2, ($current - $delta)); $i <= min(($last - 1), ($current + $delta)); $i += 1) {
                $range[] = $i;
            }
            if (($current - $delta) > 2) {
                array_unshift($range, count($range) == ($last - 3) ? 2 : '...');
            }
            if (($current + $delta) < ($last - 1)) {
                $range[] = count($range) == ($last - 3) ? ($last - 1) : '...';
            }
            array_unshift($range, 1);
            $range[] = $last;
        }

        return [
            'current' => $current,
            'last' => $last,
            'per_page' => $perPage,
            'results' => $results,
            'range' => $range,
        ];
    }

    /**
     * Generate cache key for endpoint and query.
     *
     * @param string $endpoint The endpoint URI
     * @param array $query Query parameters
     * @return string Cache key
     */
    private function getCacheKey(string $endpoint, array $query): string
    {
        return 'swotto_' . md5($endpoint . serialize($query));
    }

    /**
     * Check if endpoint is cacheable (static data).
     *
     * @param string $endpoint The endpoint URI
     * @return bool True if endpoint should be cached
     */
    private function isCacheable(string $endpoint): bool
    {
        // Static data endpoints that rarely change
        $cacheableEndpoints = [
            'open/country',
            'open/currency',
            'open/language',
            'open/gender',
            'open/role',
            'open/incoterm',
            'configuration/payment-type',
        ];

        return in_array($endpoint, $cacheableEndpoints) ||
               str_starts_with($endpoint, 'open/timezone');
    }

    /**
     * {@inheritdoc}
     */
    public function setAccessToken(string $token): void
    {
        $this->updateConfig(['access_token' => $token]);
    }

    /**
     * {@inheritdoc}
     */
    public function clearAccessToken(): void
    {
        $this->updateConfig(['access_token' => null]);
    }

    /**
     * {@inheritdoc}
     */
    public function getAccessToken(): ?string
    {
        return $this->config->get('access_token');
    }
}
