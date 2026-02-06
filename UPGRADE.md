# Upgrade Guide

## Upgrading from v2.0.0 to v2.1.0

v2.1.0 renames the client classes to follow the Stripe PHP SDK naming convention (brand prefix on public API classes).

### Class Renames

| v2.0.0 | v2.1.0 |
|--------|--------|
| `Swotto\Client` | `Swotto\SwottoClient` |
| `Swotto\Contract\ClientInterface` | `Swotto\Contract\SwottoClientInterface` |

### Migration

Search and replace in your codebase:

```php
// v2.0.0
use Swotto\Client;
use Swotto\Contract\ClientInterface;

$client = new SwottoClient([...]);

// v2.1.0
use Swotto\SwottoClient;
use Swotto\Contract\SwottoClientInterface;

$client = new SwottoClient([...]);
```

### Quick Migration Checklist

- [ ] Replace `use Swotto\Client` with `use Swotto\SwottoClient`
- [ ] Replace `use Swotto\Contract\ClientInterface` with `use Swotto\Contract\SwottoClientInterface`
- [ ] Replace `new Client(` with `new SwottoClient(`
- [ ] Update any type hints from `Client` to `SwottoClient` and `ClientInterface` to `SwottoClientInterface`
- [ ] Run `composer cs-fix && composer phpstan && composer test`

---

## Upgrading from v1.x to v2.0.0

v2.0.0 is a major release with breaking changes. This guide covers every change and how to migrate.

### Requirements

- **PHP 8.3+** (was 8.1+)
- Remove `psr/simple-cache` if you only used it for Swotto

### 1. Configuration Changes

#### `access_token` renamed to `bearer_token`

```php
// v1.x
$client = new SwottoClient([
    'url' => 'https://api.sw4.it',
    'key' => 'YOUR_DEVAPP_TOKEN',
    'access_token' => $userToken,  // OLD
]);

// v2.0.0
$client = new SwottoClient([
    'url' => 'https://api.sw4.it',
    'key' => 'YOUR_DEVAPP_TOKEN',
    'bearer_token' => $userToken,  // NEW
]);
```

#### Removed config keys

| Removed Key | Replacement |
|-------------|-------------|
| `access_token` | `bearer_token` |
| `accept` | Always `application/json` (not configurable) |
| `circuit_breaker_enabled` | Removed entirely |
| `circuit_breaker_failure_threshold` | Removed entirely |
| `circuit_breaker_recovery_timeout` | Removed entirely |

### 2. Setter Methods Removed

All setter methods have been removed. Use config defaults or per-call options instead.

| Removed Method | v2.0.0 Replacement |
|----------------|-------------------|
| `setAccessToken($token)` | Config: `'bearer_token' => $token` or per-call: `['bearer_token' => $token]` |
| `clearAccessToken()` | Create a new Client instance without `bearer_token` |
| `getAccessToken()` | Not needed (client is immutable) |
| `hasAccessToken()` | Not needed (client is immutable) |
| `setSessionId($sid)` | Config: `'session_id' => $sid` or per-call: `['session_id' => $sid]` |
| `setLanguage($lang)` | Config: `'language' => $lang` or per-call: `['language' => $lang]` |
| `setAccept($accept)` | Not available (always `application/json`) |
| `setClientUserAgent($ua)` | Config: `'client_user_agent' => $ua` or per-call: `['client_user_agent' => $ua]` |
| `setClientIp($ip)` | Config: `'client_ip' => $ip` or per-call: `['client_ip' => $ip]` |
| `setLogger($logger)` | Pass logger in constructor: `new SwottoClient($config, $logger)` |

#### Migration Example

```php
// v1.x - Mutable state
$client = new SwottoClient(['url' => '...', 'key' => '...']);
$client->setAccessToken($userToken);
$client->setLanguage('it');
$client->setClientIp($_SERVER['REMOTE_ADDR']);
$data = $client->get('customers');

// v2.0.0 Option A - Config defaults (applied to every request)
$client = new SwottoClient([
    'url' => '...',
    'key' => '...',
    'bearer_token' => $userToken,
    'language' => 'it',
    'client_ip' => $_SERVER['REMOTE_ADDR'],
]);
$data = $client->get('customers');

// v2.0.0 Option B - Per-call options (per-request, overrides defaults)
$client = new SwottoClient(['url' => '...', 'key' => '...']);
$data = $client->get('customers', [
    'bearer_token' => $userToken,
    'language' => 'it',
    'client_ip' => $_SERVER['REMOTE_ADDR'],
]);
```

### 3. Parsed Methods Removed

`getParsed()`, `postParsed()`, `putParsed()`, `patchParsed()`, `deleteParsed()` are removed.

Use the standard methods and process the response directly:

```php
// v1.x
$parsed = $client->getParsed('customers');
$customers = $parsed['data'];
$paginator = $parsed['paginator'];

// v2.0.0
$response = $client->get('customers');
$customers = $response['data'];
$pagination = $response['meta']['pagination'];
```

### 4. POP Methods Removed

All `get*Pop()` methods and `fetchPop()` are removed. Call the API endpoints directly:

```php
// v1.x
$countries = $client->getCountryPop();
$genders = $client->getGenderPop();

// v2.0.0
$countries = $client->get('pop/country');
$genders = $client->get('pop/gender');
```

If you need caching, implement it in your application layer.

### 5. Circuit Breaker Removed

The entire Circuit Breaker subsystem has been removed:

```php
// v1.x
use Swotto\Http\GuzzleHttpClient;
use Swotto\Exception\CircuitBreakerOpenException;

$client = new Client(
    $config,
    $logger,
    GuzzleHttpClient::withCircuitBreaker($configuration, $logger, $cache)
);

try {
    $client->get('test');
} catch (CircuitBreakerOpenException $e) {
    // handle
}

// v2.0.0 - Use Retry instead (built-in), or implement CB externally
$client = new SwottoClient([
    'url' => '...',
    'key' => '...',
    'retry_enabled' => true,
    'retry_max_attempts' => 3,
]);
```

### 6. HttpClientInterface Simplified

If you implemented a custom `HttpClientInterface`:

```php
// v1.x - Had initialize() method
class MyHttpClient implements HttpClientInterface {
    public function initialize(array $config): void { ... }
    public function request(...): array { ... }
    public function requestRaw(...): ResponseInterface { ... }
}

// v2.0.0 - Only request() and requestRaw()
class MyHttpClient implements HttpClientInterface {
    public function request(string $method, string $uri, array $options = []): array { ... }
    public function requestRaw(string $method, string $uri, array $options = []): ResponseInterface { ... }
}
```

### 7. Exception Hierarchy

`CircuitBreakerOpenException` is removed from the exception hierarchy:

```
// v2.0.0 Exception Hierarchy
SwottoExceptionInterface
└── SwottoException
    ├── ApiException (HTTP 400-599)
    │   ├── AuthenticationException (401)
    │   ├── ForbiddenException (403)
    │   ├── NotFoundException (404)
    │   ├── ValidationException (422)
    │   └── RateLimitException (429)
    ├── NetworkException
    │   └── ConnectionException
    ├── SecurityException
    │   ├── FileOperationException
    │   └── MemoryException
    └── StreamingException
```

### 8. Default Options Pattern (New)

v2.0.0 introduces the Stripe-inspired default options pattern:

```php
// Config-level defaults are merged with per-call options
$client = new SwottoClient([
    'url' => 'https://api.sw4.it',
    'key' => 'YOUR_DEVAPP_TOKEN',
    'bearer_token' => 'default-token',   // applied to every request
    'language' => 'it',                   // applied to every request
]);

// Per-call options override defaults
$data = $client->get('customers', [
    'language' => 'en',  // overrides 'it' for this request only
]);

// Next request uses default 'it' again
$other = $client->get('products');
```

### Quick Migration Checklist

- [ ] Update PHP to 8.3+
- [ ] Replace `access_token` with `bearer_token` in config
- [ ] Remove all `set*()` calls, use config defaults or per-call options
- [ ] Replace `getParsed()` calls with `get()` + direct response processing
- [ ] Replace `get*Pop()` calls with `get('pop/...')`
- [ ] Remove Circuit Breaker config and `CircuitBreakerOpenException` catches
- [ ] Remove `accept` from config (if used)
- [ ] Remove `psr/simple-cache` from composer.json (if only used for Swotto)
- [ ] Run `composer cs-fix && composer phpstan && composer test`
