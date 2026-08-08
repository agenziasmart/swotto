<?php

declare(strict_types=1);

namespace Swotto\Http;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;
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
    private const VERSION = '2.3.0';

    /**
     * @var int Default request timeout
     */
    private const DEFAULT_TIMEOUT = 10;

    /**
     * @var int Maximum number of redirects to follow
     */
    private const MAX_REDIRECTS = 5;

    /**
     * Application headers that must not survive a change of origin.
     *
     * Guzzle already strips Authorization and Cookie on a cross-origin redirect, but it
     * knows nothing about our own headers: the DevApp token identifies the application
     * and has no business reaching a host we did not configure.
     *
     * @var array<int, string>
     */
    private const ORIGIN_BOUND_HEADERS = ['x-devapp', 'x-swotto-client-info', 'x-sid'];

    /**
     * Placeholder written in place of a redacted value.
     */
    private const REDACTED = '****';

    /**
     * Header names whose value must never be logged. Matched loosely — see isSensitiveKey().
     *
     * @var array<int, string>
     */
    private const SENSITIVE_HEADERS = ['authorization', 'cookie', 'apikey', 'authtoken', 'devapp', 'xsid'];

    /**
     * Payload keys whose value must never be logged, at any depth.
     *
     * @var array<int, string>
     */
    private const SENSITIVE_FIELDS = [
        'password',
        'passwd',
        'token',
        'secret',
        'apikey',
        'credential',
        'authorization',
        'cookie',
        'signature',
        'privatekey',
    ];

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

        $userAgent = $this->buildUserAgent();
        $telemetry = $this->buildTelemetry();

        $headers = array_merge(
            [
                'User-Agent' => $userAgent,
                'X-Swotto-Client-Info' => $telemetry,
            ],
            $this->config->getHeaders()
        );

        $verifySsl = $this->config->get('verify_ssl', self::VERIFY_SSL);
        $timeout = $this->config->get('timeout', self::DEFAULT_TIMEOUT);

        if ($verifySsl === false) {
            $this->logger->warning(
                'SSL verification is disabled - this is insecure and should only be used in development!'
            );
        }

        $baseUrl = $this->config->getBaseUrl();

        $httpConfig = [
            'base_uri' => $baseUrl,
            'headers' => $headers,
            'timeout' => $timeout,
            'allow_redirects' => [
                'max' => self::MAX_REDIRECTS,
                // A base URL on http is a local development setup; anywhere else, refuse to
                // be walked down to cleartext by a redirect.
                'protocols' => str_starts_with($baseUrl, 'http://') ? ['http', 'https'] : ['https'],
                'strict' => true,
                'referer' => false,
            ],
            'verify' => $verifySsl,
            'handler' => $this->buildHandlerStack($baseUrl),
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
     * Build the handler stack with the cross-origin header guard installed.
     *
     * The guard is pushed, which in Guzzle puts it closest to the transport — below
     * RedirectMiddleware. That position matters: when a redirect is followed the middleware
     * re-enters the stack from itself downwards, so only a handler below it sees the
     * redirected request rather than just the original one.
     *
     * @param string $baseUrl Configured base URL
     * @return HandlerStack Handler stack for the Guzzle client
     */
    private function buildHandlerStack(string $baseUrl): HandlerStack
    {
        $stack = HandlerStack::create();
        $baseHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));

        $stack->push(
            static function (callable $handler) use ($baseHost): callable {
                return static function (RequestInterface $request, array $options) use ($handler, $baseHost) {
                    if ($baseHost !== '' && strtolower($request->getUri()->getHost()) !== $baseHost) {
                        foreach (self::ORIGIN_BOUND_HEADERS as $header) {
                            $request = $request->withoutHeader($header);
                        }
                    }

                    return $handler($request, $options);
                };
            },
            'swotto_origin_guard'
        );

        return $stack;
    }

    /**
     * {@inheritdoc}
     */
    public function request(string $method, string $uri, array $options = []): array
    {
        $options = $this->extractPerCallOptions($options);

        $this->logRequest('Requesting', $method, $uri, $options);

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

        $this->logRequest('Raw request', $method, $uri, $options);

        try {
            return $this->client->request($method, $uri, $options);
        } catch (\Exception $exception) {
            $this->handleRawException($exception, $uri);
        }
    }

    /**
     * Log an outgoing request.
     *
     * The request line goes out at info level; the options — which carry the request body,
     * and in an ERP that body is full of commercial and personal data — go out at debug,
     * so ordinary production logging records what was called without accumulating payloads.
     *
     * @param string $label Human-readable prefix
     * @param string $method HTTP method
     * @param string $uri Request URI
     * @param array<string, mixed> $options Guzzle request options
     */
    private function logRequest(string $label, string $method, string $uri, array $options): void
    {
        $this->logger->info("{$label} {$method} " . $this->sanitizeUriForLogging($uri));
        $this->logger->debug(
            "{$label} {$method} {$uri} options",
            $this->sanitizeOptionsForLogging($options)
        );
    }

    /**
     * Strip the query string from a URI before logging it.
     *
     * Tokens travel in query strings often enough that logging one whole is a leak in
     * itself; scheme, host and path are what makes a log line useful anyway.
     *
     * @param string $uri Request URI, absolute or relative to the base URL
     * @return string URI without its query string or fragment
     */
    private function sanitizeUriForLogging(string $uri): string
    {
        $withoutFragment = strtok($uri, '#');
        $withoutQuery = strtok($withoutFragment === false ? $uri : $withoutFragment, '?');

        return $withoutQuery === false ? $uri : $withoutQuery;
    }

    /**
     * Extract Swotto-specific per-call options and convert them to HTTP headers.
     *
     * Supported per-call options:
     * - bearer_token: Sets Authorization header
     * - language: Sets Accept-Language header
     * - session_id: Sets x-sid header
     * - client_ip: Sets Client-Ip header
     * - client_user_agent: Sets X-Client-User-Agent header (end-user UA forwarding)
     *
     * @param array<string, mixed> $options Request options (may contain Swotto-specific keys)
     * @return array<string, mixed> Options with Swotto keys converted to headers
     */
    private function extractPerCallOptions(array $options): array
    {
        // Consumed by RetryHttpClient when the decorator is active; with retry disabled it
        // would otherwise reach Guzzle as an unknown transfer option.
        unset($options['retry_non_idempotent']);

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
            $perCallHeaders['X-Client-User-Agent'] = $options['client_user_agent'];
            unset($options['client_user_agent']);
        }

        if (!empty($perCallHeaders)) {
            $options['headers'] = array_merge($options['headers'] ?? [], $perCallHeaders);
        }

        return $options;
    }

    /**
     * Build SDK User-Agent string.
     *
     * Format: Swotto/v1 PHP-SDK/{version} [AppName/AppVersion] PHP/{php_version}
     *
     * @return string User-Agent string
     */
    private function buildUserAgent(): string
    {
        $parts = ['Swotto/v1 PHP-SDK/' . self::VERSION];

        $appName = $this->config->get('app_name');
        $appVersion = $this->config->get('app_version');
        if ($appName !== null && $appName !== '') {
            $appPart = (string) $appName;
            if ($appVersion !== null && $appVersion !== '') {
                $appPart .= '/' . (string) $appVersion;
            }
            $parts[] = $appPart;
        }

        $parts[] = 'PHP/' . PHP_VERSION;

        return implode(' ', $parts);
    }

    /**
     * Build JSON telemetry string for X-Swotto-Client-Info header.
     *
     * @return string JSON-encoded telemetry data
     */
    private function buildTelemetry(): string
    {
        $data = [
            'sdk_version' => self::VERSION,
            'lang' => 'php',
            'lang_version' => PHP_VERSION,
            'os' => PHP_OS,
        ];

        $appName = $this->config->get('app_name');
        if ($appName !== null && $appName !== '') {
            $data['app_name'] = (string) $appName;
            $appVersion = $this->config->get('app_version');
            if ($appVersion !== null && $appVersion !== '') {
                $data['app_version'] = (string) $appVersion;
            }
        }

        return (string) json_encode($data);
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
        $is404 = $exception instanceof RequestException && $exception->getCode() === 404;
        if ($is401 || $is404) {
            $this->logger->debug("HTTP {$exception->getCode()} for {$uri}");
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
        $message = $this->extractErrorMessage($body);

        switch ($code) {
            case 400:
            case 422:
                throw new ValidationException($message ?? 'Invalid field', $body, $code);
            case 401:
                throw new AuthenticationException($message ?? 'Unauthorized', $body, $code);
            case 403:
                throw new ForbiddenException($message ?? 'Forbidden', $body, $code);
            case 404:
                throw new NotFoundException($message ?? 'Not Found', $body, $code);
            case 429:
                $retryAfter = $this->parseRetryAfter($response->getHeader('Retry-After')[0] ?? '');
                throw new RateLimitException($message ?? 'Too Many Requests', $body, $retryAfter);
            default:
                throw new ApiException($message ?? $exception->getMessage(), $body, $code);
        }
    }

    /**
     * Extract a human-readable message from an error response body.
     *
     * The Swotto API nests it under `error.message`; a flat `message` is accepted as a
     * fallback for other shapes. Anything that is not a non-empty string is discarded, so
     * a structured or unexpected value yields the caller's default instead of a TypeError
     * that would hide the HTTP response entirely.
     *
     * @param array<string, mixed> $body Parsed response body
     * @return string|null Message, or null when none is usable
     */
    private function extractErrorMessage(array $body): ?string
    {
        $candidates = [];

        if (isset($body['error']) && is_array($body['error']) && isset($body['error']['message'])) {
            $candidates[] = $body['error']['message'];
        }

        if (isset($body['message'])) {
            $candidates[] = $body['message'];
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Parse a Retry-After header value into seconds.
     *
     * RFC 9110 allows two forms: delay-seconds and an HTTP-date. Casting the date form
     * with (int) yields 0, so it is parsed explicitly and converted to a delay relative
     * to now. Anything unparsable or in the past yields 0, which lets the retry policy
     * fall back to its exponential backoff.
     *
     * @param string $value Raw header value
     * @return int Seconds to wait, 0 if unknown
     */
    private function parseRetryAfter(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        if (preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return 0;
        }

        return max(0, $timestamp - time());
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

        // 1. Sanitize multipart parts. The value lives under 'contents' while the field
        //    name lives under 'name', so a generic key walk would miss a part literally
        //    named "password".
        if (isset($sanitized['multipart']) && is_array($sanitized['multipart'])) {
            foreach ($sanitized['multipart'] as &$part) {
                if (!is_array($part) || !isset($part['contents'])) {
                    continue;
                }

                $name = $part['name'] ?? null;
                if (is_string($name) && $this->isSensitiveKey($name, self::SENSITIVE_FIELDS)) {
                    $part['contents'] = self::REDACTED;

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
            unset($part);
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
        if (isset($sanitized['headers']) && is_array($sanitized['headers'])) {
            foreach (array_keys($sanitized['headers']) as $key) {
                if (is_string($key) && $this->isSensitiveKey($key, self::SENSITIVE_HEADERS)) {
                    $sanitized['headers'][$key] = self::REDACTED;
                }
            }
        }

        // 4. Sanitize payload containers at any depth. A secret nested three levels down
        //    in a JSON body, or sitting in the query string, used to reach the logger in
        //    the clear because only the first level of a couple of containers was checked.
        foreach (['form_params', 'json', 'query'] as $container) {
            if (isset($sanitized[$container]) && is_array($sanitized[$container])) {
                $sanitized[$container] = $this->redactSensitiveValues($sanitized[$container]);
            }
        }

        return $sanitized;
    }

    /**
     * Walk an arbitrarily nested structure and redact values held under sensitive keys.
     *
     * @param array<array-key, mixed> $data Structure to redact
     * @return array<array-key, mixed> Redacted copy
     */
    private function redactSensitiveValues(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key, self::SENSITIVE_FIELDS)) {
                $data[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->redactSensitiveValues($value);
            }
        }

        return $data;
    }

    /**
     * Match a key against a list of sensitive names, ignoring case, separators and any
     * surrounding prefix (`x-api-key`, `apiKey` and `customer_api_key` all match).
     *
     * @param string $key Key to test
     * @param array<int, string> $needles Sensitive names, lowercase and separator-free
     * @return bool True if the key must be redacted
     */
    private function isSensitiveKey(string $key, array $needles): bool
    {
        $normalized = strtolower(str_replace(['-', '_', '.', ' '], '', $key));

        foreach ($needles as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
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
