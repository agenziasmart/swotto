<?php

declare(strict_types=1);

namespace Swotto\Http;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Swotto\Config\Configuration;
use Swotto\Contract\HttpClientInterface;
use Swotto\Exception\ApiException;
use Swotto\Exception\AuthenticationException;
use Swotto\Exception\ConnectionException;
use Swotto\Exception\ForbiddenException;
use Swotto\Exception\NetworkException;
use Swotto\Exception\NotFoundException;
use Swotto\Exception\RateLimitException;
use Swotto\Exception\ValidationException;

/**
 * GuzzleHttpClient.
 *
 * HTTP Client implementation using Guzzle.
 * Immutable: configured once in constructor.
 */
final class GuzzleHttpClient implements HttpClientInterface
{
    /**
     * @var string SDK version
     */
    private const VERSION = '4.0.0';

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
     * @var GuzzleClient Guzzle HTTP client instance (not readonly to allow test injection)
     */
    private GuzzleClient $client;

    /**
     * @var LoggerInterface Logger instance
     */
    private readonly LoggerInterface $logger;

    /**
     * @var Configuration Client configuration
     */
    private readonly Configuration $config;

    /**
     * Constructor.
     *
     * @param Configuration $config Configuration instance
     * @param LoggerInterface|null $logger Optional logger
     */
    public function __construct(Configuration $config, ?LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();

        $sdkAuthor = 'Swotto-SDK/' . self::VERSION . ' PHP/' . PHP_VERSION;
        $headers = array_merge(
            ['x-author' => $sdkAuthor],
            $this->config->getHeaders()
        );

        $verifySsl = $this->config->get('verify_ssl', self::VERIFY_SSL);
        $timeout = $this->config->get('timeout', self::DEFAULT_TIMEOUT);

        if ($verifySsl === false) {
            $this->logger->warning(
                'SSL verification is disabled - this is insecure and should only be used in development!'
            );
        }

        $httpConfig = [
            'base_uri' => $this->config->getBaseUrl(),
            'headers' => $headers,
            'timeout' => $timeout,
            'allow_redirects' => self::ALLOW_REDIRECTS,
            'verify' => $verifySsl,
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
        $options = $this->extractPerCallOptions($options);

        $this->logger->info("Requesting {$method} {$uri}", $this->sanitizeOptionsForLogging($options));

        try {
            $response = $this->client->request($method, $uri, $options);

            if ($response->getBody()->getSize() === 0) {
                return [];
            }

            $decoded = json_decode($response->getBody()->getContents(), true);

            if (!is_array($decoded)) {
                return [];
            }

            return $decoded;
        } catch (\Exception $exception) {
            return $this->handleException($exception, $uri);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function requestRaw(string $method, string $uri, array $options = []): ResponseInterface
    {
        $options = $this->extractPerCallOptions($options);

        $this->logger->info("Raw request {$method} {$uri}", $this->sanitizeOptionsForLogging($options));

        try {
            return $this->client->request($method, $uri, $options);
        } catch (\Exception $exception) {
            $this->handleRawException($exception, $uri);
        }
    }

    /**
     * Extract Swotto-specific per-call options and convert them to HTTP headers.
     *
     * Supported per-call options:
     * - bearer_token: Sets Authorization header
     * - language: Sets Accept-Language header
     * - session_id: Sets x-sid header
     * - client_ip: Sets Client-Ip header
     * - client_user_agent: Sets User-Agent header
     *
     * @param array<string, mixed> $options Request options (may contain Swotto-specific keys)
     * @return array<string, mixed> Options with Swotto keys converted to headers
     */
    private function extractPerCallOptions(array $options): array
    {
        $perCallHeaders = [];

        if (isset($options['bearer_token'])) {
            $perCallHeaders['Authorization'] = 'Bearer ' . $options['bearer_token'];
            unset($options['bearer_token']);
        }

        if (isset($options['language'])) {
            $perCallHeaders['Accept-Language'] = $options['language'];
            unset($options['language']);
        }

        if (isset($options['session_id'])) {
            $perCallHeaders['x-sid'] = $options['session_id'];
            unset($options['session_id']);
        }

        if (isset($options['client_ip'])) {
            $perCallHeaders['Client-Ip'] = $options['client_ip'];
            unset($options['client_ip']);
        }

        if (isset($options['client_user_agent'])) {
            $perCallHeaders['User-Agent'] = $options['client_user_agent'];
            unset($options['client_user_agent']);
        }

        if (!empty($perCallHeaders)) {
            $options['headers'] = array_merge($options['headers'] ?? [], $perCallHeaders);
        }

        return $options;
    }

    /**
     * Handle exceptions that might occur during API requests.
     *
     * @param \Exception $exception The caught exception
     * @param string $uri The requested URI
     * @return array<string, mixed> Never returns, always throws
     *
     * @throws ApiException|ConnectionException|NetworkException|\Exception
     */
    private function handleException(\Exception $exception, string $uri): array
    {
        $this->logException($exception, $uri);
        $this->throwMappedException($exception, $uri, false);
    }

    /**
     * Handle exceptions for raw requests.
     *
     * @param \Exception $exception The caught exception
     * @param string $uri The requested URI
     * @return never Always throws
     *
     * @throws ApiException|ConnectionException|NetworkException|\Exception
     */
    private function handleRawException(\Exception $exception, string $uri): never
    {
        $this->logException($exception, $uri);
        $this->throwMappedException($exception, $uri, true);
    }

    /**
     * Log exception with appropriate level.
     *
     * @param \Exception $exception The exception to log
     * @param string $uri The requested URI
     */
    private function logException(\Exception $exception, string $uri): void
    {
        $is401 = $exception instanceof RequestException && $exception->getCode() === 401;
        if ($is401) {
            $this->logger->debug("Authentication required for {$uri}");
        } else {
            $this->logger->error("Error while requesting {$uri}: {$exception->getMessage()}");
        }
    }

    /**
     * Map exception to appropriate Swotto exception and throw it.
     *
     * @param \Exception $exception The original exception
     * @param string $uri The requested URI
     * @param bool $preserveRawBody Whether to preserve raw body
     * @return never Always throws
     *
     * @throws ApiException|ConnectionException|NetworkException|\Exception
     */
    private function throwMappedException(\Exception $exception, string $uri, bool $preserveRawBody): never
    {
        if ($exception instanceof RequestException) {
            $code = $exception->getCode();

            if ($exception->hasResponse()) {
                $response = $exception->getResponse();
                if ($response !== null) {
                    $body = $this->parseResponseBody($response, $preserveRawBody);
                    $this->throwHttpException($code, $body, $response, $exception);
                }
            }

            throw new NetworkException(
                "Network error while requesting {$uri}: {$exception->getMessage()}",
                [],
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

        throw $exception;
    }

    /**
     * Parse response body to array.
     *
     * @param ResponseInterface $response The HTTP response
     * @param bool $preserveRawBody Whether to preserve raw body for non-JSON
     * @return array<string, mixed> Parsed body
     */
    private function parseResponseBody(ResponseInterface $response, bool $preserveRawBody): array
    {
        if ($preserveRawBody) {
            try {
                $bodyContent = $response->getBody()->getContents();
                $decoded = json_decode($bodyContent, true);

                return is_array($decoded) ? $decoded : ['raw_body' => $bodyContent];
            } catch (\Exception) {
                return ['error' => 'Could not read response body'];
            }
        }

        $decoded = json_decode($response->getBody()->getContents(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Throw appropriate HTTP exception based on status code.
     *
     * @param int $code HTTP status code
     * @param array<string, mixed> $body Parsed response body
     * @param ResponseInterface $response Original response
     * @param RequestException $exception Original exception
     * @return never Always throws
     *
     * @throws ValidationException|AuthenticationException|ForbiddenException
     * @throws NotFoundException|RateLimitException|ApiException
     */
    private function throwHttpException(
        int $code,
        array $body,
        ResponseInterface $response,
        RequestException $exception
    ): never {
        $message = $body['message'] ?? null;

        switch ($code) {
            case 400:
                throw new ValidationException($message ?? 'Invalid field', $body, $code);
            case 401:
                throw new AuthenticationException($message ?? 'Unauthorized', $body, $code);
            case 403:
                throw new ForbiddenException($message ?? 'Forbidden', $body, $code);
            case 404:
                throw new NotFoundException($message ?? 'Not Found', $body, $code);
            case 429:
                $retryAfter = (int) ($response->getHeader('Retry-After')[0] ?? 0);
                throw new RateLimitException($message ?? 'Too Many Requests', $body, $retryAfter);
            default:
                throw new ApiException($message ?? $exception->getMessage(), $body, $code);
        }
    }

    /**
     * Sanitize request options for safe logging.
     *
     * @param array<string, mixed> $options Original Guzzle request options
     * @return array<string, mixed> Sanitized options safe for logging
     */
    private function sanitizeOptionsForLogging(array $options): array
    {
        $sanitized = $options;

        // 1. Sanitize multipart file contents
        if (isset($sanitized['multipart'])) {
            foreach ($sanitized['multipart'] as &$part) {
                if (!isset($part['contents'])) {
                    continue;
                }

                if (!is_string($part['contents'])) {
                    $size = $this->getContentSize($part['contents']);
                    $part['contents'] = sprintf('<binary data: %d bytes>', $size);

                    continue;
                }

                if ($this->isBinaryString($part['contents'])) {
                    $size = strlen($part['contents']);
                    $part['contents'] = sprintf('<binary data: %d bytes>', $size);
                }
            }
        }

        // 2. Sanitize body streams/resources and binary strings
        if (isset($sanitized['body'])) {
            if (is_resource($sanitized['body']) || $sanitized['body'] instanceof \Psr\Http\Message\StreamInterface) {
                $size = $this->getContentSize($sanitized['body']);
                $sanitized['body'] = sprintf('<stream: %d bytes>', $size);
            } elseif (is_string($sanitized['body']) && $this->isBinaryString($sanitized['body'])) {
                $sanitized['body'] = sprintf('<body: %d bytes>', strlen($sanitized['body']));
            }
        }

        // 3. Sanitize sensitive headers
        if (isset($sanitized['headers'])) {
            $sensitiveHeaders = ['Authorization', 'Cookie', 'Set-Cookie', 'X-Api-Key', 'X-Auth-Token', 'X-Devapp'];
            foreach ($sensitiveHeaders as $header) {
                foreach (array_keys($sanitized['headers']) as $key) {
                    if (is_string($key) && strcasecmp($key, $header) === 0) {
                        $sanitized['headers'][$key] = '****';
                    }
                }
            }
        }

        // 4. Sanitize sensitive form parameters
        if (isset($sanitized['form_params'])) {
            $sensitiveFields = ['password', 'token', 'secret', 'api_key', 'access_token'];
            foreach ($sensitiveFields as $field) {
                if (isset($sanitized['form_params'][$field])) {
                    $sanitized['form_params'][$field] = '****';
                }
            }
        }

        // 5. Sanitize JSON body sensitive fields
        if (isset($sanitized['json']) && is_array($sanitized['json'])) {
            $sensitiveFields = ['password', 'token', 'secret', 'api_key', 'access_token'];
            foreach ($sensitiveFields as $field) {
                if (isset($sanitized['json'][$field])) {
                    $sanitized['json'][$field] = '****';
                }
            }
        }

        return $sanitized;
    }

    /**
     * Get size of content.
     *
     * @param mixed $content Content to measure
     * @return int Size in bytes
     */
    private function getContentSize(mixed $content): int
    {
        if (is_string($content)) {
            return strlen($content);
        }

        if (is_resource($content)) {
            $stat = fstat($content);

            return $stat['size'] ?? 0;
        }

        if ($content instanceof \Psr\Http\Message\StreamInterface) {
            return $content->getSize() ?? 0;
        }

        return 0;
    }

    /**
     * Detect if string content is binary data.
     *
     * @param string $content Content to check
     * @return bool True if content appears to be binary
     */
    private function isBinaryString(string $content): bool
    {
        if (strlen($content) === 0) {
            return false;
        }

        $quickSample = substr($content, 0, 512);
        if (strpos($quickSample, "\0") !== false) {
            return true;
        }

        $sample = substr($content, 0, 1024);

        return !mb_check_encoding($sample, 'UTF-8');
    }
}
