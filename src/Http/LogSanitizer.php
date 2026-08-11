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
        // Strip authority user-info while the URI is still the caller's raw byte string.
        // mb_scrub() honours the process-wide mb_substitute_character(); if that character
        // is '/', repairing an invalid byte first can manufacture a slash and make the
        // authority regex preserve credentials.
        $uri = preg_replace('#^([a-z][a-z0-9+.-]*://|//)[^/]*@#i', '$1', $uri) ?? '';
        $uri = mb_scrub($uri, 'UTF-8');
        $uri = preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', '', $uri) ?? '';

        return mb_strcut($uri, 0, self::MAX_URI_LENGTH, 'UTF-8');
    }
}
