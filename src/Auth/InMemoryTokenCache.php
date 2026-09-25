<?php

declare(strict_types=1);

namespace Swotto\Auth;

use Swotto\Contract\TokenCacheInterface;

/**
 * InMemoryTokenCache.
 *
 * Default cache: the token lives as long as the instance. The store is an instance
 * property, never static, so two clients in the same worker process never share a token.
 * Expiry is enforced by the provider through `expires_at`; the TTL is not tracked here.
 */
final class InMemoryTokenCache implements TokenCacheInterface
{
    /**
     * @var array<string, array{access_token: string, expires_at: int}>
     */
    private array $tokens = [];

    public function get(string $key): ?array
    {
        return $this->tokens[$key] ?? null;
    }

    public function set(string $key, array $token, int $ttl): void
    {
        $this->tokens[$key] = $token;
    }

    public function delete(string $key): void
    {
        unset($this->tokens[$key]);
    }
}
