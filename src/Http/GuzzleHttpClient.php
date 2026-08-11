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

    /** Maximum length of an upstream request identifier written to logs. */
    private const MAX_LOGGED_REQUEST_ID_LENGTH = 128;

    /** Maximum length of a request path written to logs. */
    private const MAX_LOGGED_URI_LENGTH = 512;

    /** HTTP statuses whose API message is part of the public business/validation contract. */
    private const PUBLIC_MESSAGE_STATUSES = [400, 402, 409, 422];

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
        } catch (\Exception) {
            throw new ConnectionException(
                'Connection failed.',
                $this->sanitizeUriForLogging($this->config->getBaseUrl())
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
            return $this->handleException($exception);
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
            $this->handleRawException($exception);
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
        $safeUri = $this->sanitizeUriForLogging($uri);

        $this->logger->info("{$label} {$method} {$safeUri}");
        $this->logger->debug(
            "{$label} {$method} {$safeUri} options",
            $this->sanitizeOptionsForLogging($options)
        );
    }

    /**
     * Strip user-info, query string and fragment from a URI before logging it.
     *
     * Tokens travel in URL credentials and query strings often enough that logging either
     * is a leak in itself; scheme, host and path are what makes a log line useful anyway.
     *
     * @param string $uri Request URI, absolute or relative to the base URL
     * @return string URI without user-info, query string or fragment
     */
    private function sanitizeUriForLogging(string $uri): string
    {
        $uri = mb_scrub($uri, 'UTF-8');
        $uri = preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', '', $uri) ?? '';
        $withoutFragment = strtok($uri, '#');
        $withoutQuery = strtok($withoutFragment === false ? $uri : $withoutFragment, '?');
        $sanitized = $withoutQuery === false ? $uri : $withoutQuery;

        $sanitized = preg_replace('#^([a-z][a-z0-9+.-]*://|//)[^/]*@#i', '$1', $sanitized) ?? '';

        return mb_strcut($sanitized, 0, self::MAX_LOGGED_URI_LENGTH, 'UTF-8');
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
     * @return array<string, mixed> Never returns, always throws
     *
     * @throws ApiException|ConnectionException|NetworkException|\Exception
     */
    private function handleException(\Exception $exception): array
    {
        $this->logException($exception);
        $this->throwMappedException($exception, false);
    }

    /**
     * Handle exceptions for raw requests.
     *
     * @param \Exception $exception The caught exception
     * @return never Always throws
     *
     * @throws ApiException|ConnectionException|NetworkException|\Exception
     */
    private function handleRawException(\Exception $exception): never
    {
        $this->logException($exception);
        $this->throwMappedException($exception, true);
    }

    /**
     * Log exception with appropriate level.
     *
     * @param \Exception $exception The exception to log
     */
    private function logException(\Exception $exception): void
    {
        $context = $this->failureLogContext($exception);
        $status = $context['status'] ?? 0;

        // A 4xx is a request outcome, not a service failure. The caller receives the mapped
        // exception and decides its final severity; logging it here at error would duplicate
        // the same event in the wrong channel.
        if ($status >= 400 && $status < 500) {
            $this->logger->debug('Swotto HTTP request rejected', $context);

            return;
        }

        $this->logger->error('Swotto request failed', $context);
    }

    /**
     * Build a bounded diagnostic context without copying upstream-controlled text.
     *
     * Exception messages and response bodies can contain credentials, personal data or
     * implementation details. The response status, exception class and a bounded request
     * identifier are enough to correlate the failure with the API log.
     *
     * @return array{failure_type: string, exception_type: class-string, status?: int, request_id?: string}
     */
    private function failureLogContext(\Exception $exception): array
    {
        $context = [
            'failure_type' => $exception instanceof RequestException ? 'network' : 'unexpected',
            'exception_type' => $exception::class,
        ];

        if (!$exception instanceof RequestException || !$exception->hasResponse()) {
            if ($exception instanceof \GuzzleHttp\Exception\ConnectException) {
                $context['failure_type'] = 'network';
            }

            return $context;
        }

        $response = $exception->getResponse();
        if ($response === null) {
            return $context;
        }

        $context['failure_type'] = 'http';
        $context['status'] = $response->getStatusCode();

        $requestId = $this->sanitizeRequestId($response->getHeaderLine('X-Request-ID'));
        if ($requestId !== '') {
            $context['request_id'] = $requestId;
        }

        return $context;
    }

    /**
     * Map exception to appropriate Swotto exception and throw it.
     *
     * @param \Exception $exception The original exception
     * @param bool $preserveRawBody Whether to preserve raw body
     * @return never Always throws
     *
     * @throws ApiException|ConnectionException|NetworkException|\Exception
     */
    private function throwMappedException(\Exception $exception, bool $preserveRawBody): never
    {
        if ($exception instanceof RequestException) {
            $code = $exception->getCode();

            if ($exception->hasResponse()) {
                $response = $exception->getResponse();
                if ($response !== null) {
                    $code = $response->getStatusCode();
                    $body = $this->parseResponseBody($response, $preserveRawBody);
                    $this->throwHttpException($code, $body, $response);
                }
            }

            throw new NetworkException(
                'Network request failed.',
                [],
                $code
            );
        }

        if ($exception instanceof \GuzzleHttp\Exception\ConnectException) {
            throw new ConnectionException(
                'Connection failed.',
                $this->sanitizeUriForLogging($this->config->getBaseUrl()),
                [],
                $exception->getCode()
            );
        }

        throw new NetworkException('Network request failed.', [], $exception->getCode());
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
     * @return never Always throws
     *
     * @throws ValidationException|AuthenticationException|ForbiddenException
     * @throws NotFoundException|RateLimitException|ApiException
     */
    private function throwHttpException(
        int $code,
        array $body,
        ResponseInterface $response
    ): never {
        $message = $this->extractErrorMessage($body);
        $publicMessage = in_array($code, self::PUBLIC_MESSAGE_STATUSES, true) ? $message : null;

        switch ($code) {
            case 400:
            case 422:
                throw new ValidationException($publicMessage ?? 'Invalid field', $body, $code);
            case 401:
                throw new AuthenticationException('Unauthorized', $body, $code);
            case 403:
                throw new ForbiddenException('Forbidden', $body, $code);
            case 404:
                throw new NotFoundException('Not Found', $body, $code);
            case 429:
                $retryAfter = $this->parseRetryAfter($response->getHeader('Retry-After')[0] ?? '');
                throw new RateLimitException('Too Many Requests', $body, $retryAfter);
            default:
                if ($code >= 500) {
                    throw new ApiException('Upstream service error.', $body, $code);
                }

                throw new ApiException($publicMessage ?? 'HTTP request rejected.', $body, $code);
        }
    }

    /**
     * Normalize an upstream request identifier before it enters structured logs.
     *
     * Header values are upstream-controlled. Remove control/format characters (including
     * CR/LF log-forging bytes), repair malformed UTF-8 and truncate without splitting a
     * multibyte code point.
     */
    private function sanitizeRequestId(string $requestId): string
    {
        $requestId = mb_scrub(trim($requestId), 'UTF-8');
        $requestId = preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', '', $requestId) ?? '';
        $requestId = trim($requestId);

        return mb_strcut($requestId, 0, self::MAX_LOGGED_REQUEST_ID_LENGTH, 'UTF-8');
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
        $sanitized = [];

        // Body, JSON, form, multipart, query, auth, proxy, cookies, cert, ssl_key,
        // headers, callbacks and cURL options are deliberately absent. Their values are
        // application data or credentials by construction. New Guzzle options are also
        // absent by default: this is an allowlist, not an ever-incomplete denylist.
        if (isset($options['multipart']) && is_array($options['multipart'])) {
            $sanitized['payload_type'] = 'multipart';
            $sanitized['payload_parts'] = count($options['multipart']);
        } elseif (array_key_exists('json', $options)) {
            $sanitized['payload_type'] = 'json';
        } elseif (array_key_exists('form_params', $options)) {
            $sanitized['payload_type'] = 'form';
        } elseif (array_key_exists('body', $options)) {
            $sanitized['payload_type'] = 'body';
        }

        foreach (['timeout', 'connect_timeout', 'read_timeout'] as $key) {
            if (isset($options[$key]) && is_numeric($options[$key])) {
                $sanitized[$key] = (float) $options[$key];
            }
        }

        if (array_key_exists('verify', $options)) {
            $sanitized['verify_ssl'] = $options['verify'] !== false;
        }

        foreach (['http_errors', 'stream'] as $key) {
            if (isset($options[$key]) && is_bool($options[$key])) {
                $sanitized[$key] = $options[$key];
            }
        }

        return $sanitized;
    }
}
