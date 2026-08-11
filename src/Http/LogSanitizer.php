<?php

declare(strict_types=1);

namespace Swotto\Http;

/**
 * Shared request-metadata boundary for SDK logs.
 *
 * @internal This is SDK infrastructure, not an application-facing API.
 */
final class LogSanitizer
{
    private const MAX_URI_LENGTH = 512;

    /** @var list<string> */
    private const HTTP_METHODS = [
        'GET',
        'HEAD',
        'POST',
        'PUT',
        'PATCH',
        'DELETE',
        'OPTIONS',
        'TRACE',
        'CONNECT',
    ];

    private function __construct()
    {
    }

    /** Return an uppercase allowlisted HTTP method, or a constant fallback. */
    public static function httpMethod(string $method): string
    {
        $method = strtoupper($method);

        return in_array($method, self::HTTP_METHODS, true) ? $method : 'UNKNOWN';
    }

    /**
     * Strip credentials, query, fragment and log-forging characters from a URI.
     *
     * Query and fragment delimiters are ASCII, so they can be removed before malformed
     * UTF-8 is repaired. This avoids confusing a replacement character with a delimiter.
     */
    public static function uri(string $uri): string
    {
        $uri = substr($uri, 0, strcspn($uri, '?#'));
        // Strip credentials while the URI is still the caller's raw byte string. In particular,
        // mb_scrub() honours the process-wide mb_substitute_character(); if that character
        // is '/', repairing an invalid byte first can manufacture an authority delimiter.
        // Treat every raw '@' conservatively: malformed URIs are logged before the transport
        // rejects them, so preserving a questionable path is less important than redaction.
        $userInfoDelimiter = strrpos($uri, '@');
        if (false !== $userInfoDelimiter) {
            $authorityDelimiter = strpos($uri, '//');
            $safePrefix = false !== $authorityDelimiter && $authorityDelimiter < $userInfoDelimiter
                ? substr($uri, 0, $authorityDelimiter + 2)
                : '';
            $uri = $safePrefix . substr($uri, $userInfoDelimiter + 1);
        }
        $uri = mb_scrub($uri, 'UTF-8');
        $uri = preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', '', $uri) ?? '';

        return mb_strcut($uri, 0, self::MAX_URI_LENGTH, 'UTF-8');
    }
}
