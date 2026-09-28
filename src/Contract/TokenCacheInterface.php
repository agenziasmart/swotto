<?php

declare(strict_types=1);

namespace Swotto\Contract;

/**
 * Interface TokenCacheInterface.
 *
 * Keeps an OAuth access token between requests (a PSR-16 shape reduced to what the
 * client_credentials round needs; a Redis-backed adapter is a few lines). Keys are
 * sha256(token_url|client_id|scope), so two clients never read each other's token.
 */
interface TokenCacheInterface
{
    /**
     * @param string $key Cache key
     * @return array{access_token: string, expires_at: int}|null The token, or null when absent
     */
    public function get(string $key): ?array;

    /**
     * @param string $key Cache key
     * @param array{access_token: string, expires_at: int} $token Token and its absolute expiry (unix seconds)
     * @param int $ttl Time to live in seconds
     */
    public function set(string $key, array $token, int $ttl): void;

    /**
     * @param string $key Cache key
     */
    public function delete(string $key): void;
}
