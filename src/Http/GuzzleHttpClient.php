<?php

declare(strict_types=1);

namespace Swotto\Http;

use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;
use Swotto\Config\Configuration;
use Swotto\Exception\ApiException;
use GuzzleHttp\Client as GuzzleClient;
use Swotto\Exception\NetworkException;
use Swotto\Exception\NotFoundException;
use Swotto\Contract\HttpClientInterface;
use Swotto\Exception\ForbiddenException;
use Swotto\Exception\RateLimitException;
use Swotto\Exception\ConnectionException;
use Swotto\Exception\ValidationException;
use GuzzleHttp\Exception\RequestException;
use Swotto\Exception\AuthenticationException;

/**
 * GuzzleHttpClient
 *
 * HTTP Client implementation using Guzzle
 */
class GuzzleHttpClient implements HttpClientInterface
{
  /**
   * @var string Author identifier
   */
  private const AUTHOR = 'swottosdk';

  /**
   * @var string SDK version
   */
  private const VERSION = '1.0.9';

  /**
   * @var int Default request timeout
   */
  private const DEFAULT_TIMEOUT = 10;

  /**
   * @var bool Allow redirects by default
   */
  private const ALLOW_REDIRECTS = true;

  /**
   * @var bool Verify SSL by default
   */
  private const VERIFY_SSL = true;

  /**
   * @var GuzzleClient Guzzle HTTP client instance
   */
  private GuzzleClient $client;

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
   * @param Configuration $config Configuration instance
   * @param LoggerInterface|null $logger Optional logger
   */
  public function __construct(Configuration $config, ?LoggerInterface $logger = null)
  {
    $this->config = $config;
    $this->logger = $logger ?? new NullLogger();
    $this->initialize($config->toArray());
  }

  /**
   * {@inheritdoc}
   */
  public function initialize(array $config): void
  {
    // SDK User-Agent
    $sdkAuthor = 'Swotto-SDK/' . self::VERSION . ' PHP/' . PHP_VERSION;
    $headers = array_merge(
      [
        'x-author' => $sdkAuthor
      ],
      $this->config->getHeaders()
    );

    $httpConfig = [
      'base_uri' => $this->config->getBaseUrl(),
      'headers' => $headers,
      'timeout' => self::DEFAULT_TIMEOUT,
      'allow_redirects' => self::ALLOW_REDIRECTS,
      'verify' => $this->config->get('verify_ssl', self::VERIFY_SSL),
    ];

    try {
      $this->client = new GuzzleClient($httpConfig);
    } catch (\Exception $e) {
      throw new ConnectionException(
        $e->getMessage(),
        $this->config->getBaseUrl()
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function request(string $method, string $uri, array $options = []): array
  {
    $this->logger->info("Requesting {$method} {$uri}", $options);

    try {
      $response = $this->client->request($method, $uri, $options);

      // Return empty array if response body is empty
      if ($response->getBody()->getSize() === 0) {
        return [];
      }

      return json_decode($response->getBody()->getContents(), true);
    } catch (\Exception $exception) {
      return $this->handleException($exception, $uri);
    }
  }

  /**
   * Handle exceptions that might occur during API requests
   *
   * @param \Exception $exception The caught exception
   * @param string $uri The requested URI
   * @return array Never returns, always throws an exception
   *
   * @throws ApiException|ConnectionException|NetworkException|\Exception
   */
  private function handleException(\Exception $exception, string $uri): array
  {
    $this->logger->error("Error while requesting {$uri}: {$exception->getMessage()}");

    if ($exception instanceof RequestException) {
      $code = $exception->getCode();
      $body = [];

      if ($exception->hasResponse()) {
        $body = json_decode($exception->getResponse()->getBody()->getContents(), true);

        // Handle different error codes
        switch ($code) {
          case 400:
            throw new ValidationException(
              $body['message'] ?? 'Invalid field',
              $body ?? [],
              $code
            );

          case 401:
            throw new AuthenticationException(
              $body['message'] ?? 'Unauthorized',
              $body ?? [],
              $code
            );

          case 403:
            throw new ForbiddenException(
              $body['message'] ?? 'Forbidden',
              $body ?? [],
              $code
            );

          case 404:
            throw new NotFoundException(
              $body['message'] ?? 'Not Found',
              $body ?? [],
              $code
            );

          case 429:
            $retryAfter = (int) ($exception->getResponse()->getHeader('Retry-After')[0] ?? 0);
            throw new RateLimitException(
              $body['message'] ?? 'Too Many Requests',
              $body ?? [],
              $retryAfter
            );
        }

        throw new ApiException(
          $body['message'] ?? $exception->getMessage(),
          $body ?? [],
          $code
        );
      }

      throw new NetworkException(
        "Network error while requesting {$uri}: {$exception->getMessage()}",
        $code
      );
    }

    if ($exception instanceof \GuzzleHttp\Exception\ConnectException) {
      throw new ConnectionException(
        $exception->getMessage(),
        $this->config->getBaseUrl(),
        array_slice(explode("\n", $exception->getTraceAsString()), 0, 10),
        $exception->getCode()
      );
    }

    // Rethrow any other exceptions
    throw $exception;
  }
}
