<?php

declare(strict_types=1);

namespace Swotto;

use Psr\Log\NullLogger;
use Swotto\Trait\PopTrait;
use Psr\Log\LoggerInterface;
use Swotto\Config\Configuration;
use Swotto\Http\GuzzleHttpClient;
use Swotto\Contract\ClientInterface;
use Swotto\Contract\HttpClientInterface;
use Swotto\Exception\ConnectionException;

/**
 * Client
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
   * Constructor
   *
   * @param array<string, mixed> $config Configuration options
   * @param LoggerInterface|null $logger Optional logger
   * @param HttpClientInterface|null $httpClient Optional HTTP client implementation
   *
   * @throws \Swotto\Exception\ConfigurationException On invalid configuration
   */
  public function __construct(
    array $config = [],
    ?LoggerInterface $logger = null,
    ?HttpClientInterface $httpClient = null
  ) {
    $this->logger = $logger ?? new NullLogger();
    $this->config = new Configuration($config);

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
    $response = $this->get($uri, ['query' => $query ?? []]);
    return $response['data'] ?? [];
  }

  /**
   * Update client configuration
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
   * Set client original user agent
   *
   * @param string $userAgent Original client user agent
   * @return void
   */
  public function setClientUserAgent(string $userAgent): void
  {
    $this->updateConfig(['client_user_agent' => $userAgent]);
  }

  /**
   * Set client original IP
   *
   * @param string $ip Original client IP
   * @return void
   */
  public function setClientIp(string $ip): void
  {
    $this->updateConfig(['client_ip' => $ip]);
  }
}
