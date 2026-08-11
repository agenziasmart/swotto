# Upgrade Guide

## Upgrading from v2.3.0 to the next patch

This security patch keeps exception classes, HTTP status codes and `getErrorData()` intact,
but intentionally narrows exception messages. API-provided messages remain public for `400`,
`402`, `409` and `422`; `401`, `403`, `404`, `429`, `5xx`, default HTTP, network, connection
and unexpected transport failures now use constants and have no raw previous exception.

If application code matches message text, switch it to `getStatusCode()` and the exception
class. If it displays validation/business feedback, keep reading the message for the four
public statuses or structured fields from `getErrorData()`. Never send `getErrorData()` or
public messages to logs wholesale.

Two transport details are intentionally stricter. An unexpected `RuntimeException` caught at
the Guzzle boundary is now mapped to `NetworkException` instead of being rethrown with its raw
message and previous chain. A `ConnectionException` created by the SDK now returns an empty
`getTraceDetails()` array rather than copying Guzzle handler context; its class, status/code and
sanitized base URL remain available. Code that caught `RuntimeException` specifically should
catch `NetworkException`, and diagnostics should use API-side correlation instead of the removed
transport trace.

Request-option debug logs also changed from a redacted copy to a strict metadata allowlist.
This removes payload/header visibility intentionally; correlate the SDK status/request ID with
the API-side log when deeper diagnosis is required. Request-line and retry metadata share one
boundary: methods are uppercased only when allowlisted (otherwise `UNKNOWN`), while URIs lose
userinfo, query, fragment and control/format separators, are repaired to valid UTF-8 and are
limited to 512 bytes without splitting a code point.

## Upgrading from v2.2.x to v2.3.0

No breaking API changes: no class was renamed, no signature narrowed, no exception moved in
the hierarchy. Four behaviours changed, and each is worth a look before deploying.

### HTTP 422 now raises `ValidationException`

The SW4 API answers 422 for validation errors. The SDK mapped only 400 to
`ValidationException`, so every real validation failure arrived as a generic `ApiException`.

This is backward compatible — `ValidationException extends ApiException` — so existing
handlers keep catching it:

```php
// Kept working before, keeps working now
try {
    $client->post('orders', $payload);
} catch (ApiException $e) {
    if ($e->getStatusCode() === 422) { /* ... */ }
}

// Now possible, and clearer
try {
    $client->post('orders', $payload);
} catch (ValidationException $e) {
    $details = $e->getErrorData()['error']['details'] ?? [];
}
```

If you branch on the exception *class* and have a `ValidationException` arm that previously
never ran for 422, it will start running. Check that it does the right thing.

### Exception messages come from the API

`$e->getMessage()` used to return a hardcoded fallback (`'Not Found'`, `'Invalid field'`) or
the raw Guzzle message, because the SDK looked for a top-level `message` while the API nests
it under `error.message`. It now returns what the API actually said.

If you match on exception message text, those assertions will need updating. Matching on
`getStatusCode()` or `getErrorData()` was and remains the reliable approach.

### POST and PATCH are no longer retried automatically

Retry is opt-in (`retry_enabled`), and it now applies only to methods that can be replayed
safely: `GET`, `HEAD`, `PUT`, `DELETE`, `OPTIONS`, `TRACE`. A network error cannot tell you
whether the request reached the server, so replaying a `POST` risks duplicating an order, a
document or an upload.

Where an endpoint is genuinely safe to repeat, say so per request:

```php
$client->post('webhooks/ping', $payload, ['retry_non_idempotent' => true]);
```

### Non-array request data is now sent

`post()`, `put()` and `patch()` declare `mixed $data`, but anything that was not a non-empty
array used to be discarded silently — `post($uri, 'raw-payload')` sent an empty request.
Strings, streams, resources and scalars now become the raw request body.

If you relied on that data being dropped, pass `[]` instead. An unsupported type (an object
that is not a stream, for instance) now raises `InvalidArgumentException` rather than being
ignored.

### Also worth knowing

- Request options, including the body, moved from `info` to `debug` level. If you relied on
  info-level logs to capture payloads, lower your handler's threshold.
- `url` is now validated: a non-string, empty, relative or non-HTTP value raises
  `ConfigurationException` at construction instead of failing later. `http` remains valid.
- `saveToFile()` fails explicitly rather than writing an empty file when the stream cannot
  be rewound, and rejects a body shorter than its `Content-Length` (see v2.2.1).

---

## Upgrading from v2.1.0 to v2.2.0

v2.2.0 adds SDK identification headers and fixes the User-Agent collision between SDK and end-user forwarding.

### Breaking Change: `client_user_agent` header renamed

| v2.1.0 | v2.2.0 |
|--------|--------|
| `client_user_agent` → `User-Agent` header | `client_user_agent` → `X-Client-User-Agent` header |

**Impact**: If your SW4 API server reads the `User-Agent` header to extract end-user browser info, you must update it to read `X-Client-User-Agent` instead.

**SDK code** (no change needed):
```php
// This code works the same in both versions
$client->get('customers', [
    'client_user_agent' => $_SERVER['HTTP_USER_AGENT'],
]);
```

**Server-side** (update required):
```php
// v2.1.0 server code
$endUserAgent = $request->getHeaderLine('User-Agent');

// v2.2.0 server code
$endUserAgent = $request->getHeaderLine('X-Client-User-Agent');
$sdkVersion = $request->getHeaderLine('User-Agent'); // Now contains "Swotto/v1 PHP-SDK/2.2.0 ..."
```

### New: SDK User-Agent

All requests now automatically include an SDK identification User-Agent:
```
User-Agent: Swotto/v1 PHP-SDK/2.2.0 PHP/8.3.14
```

### New: Telemetry Header

All requests include `X-Swotto-Client-Info` with JSON metadata:
```json
{"sdk_version":"2.2.0","lang":"php","lang_version":"8.3.14","os":"Linux"}
```

### New: App Identification

Optional config options to identify your application:
```php
$client = new SwottoClient([
    'url' => 'https://api.sw4.it',
    'key' => 'YOUR_DEVAPP_TOKEN',
    'app_name' => 'MyERP',
    'app_version' => '1.0.0',
]);
// User-Agent: Swotto/v1 PHP-SDK/2.2.0 MyERP/1.0.0 PHP/8.3.14
```

### Quick Migration Checklist

- [ ] Update server-side code to read `X-Client-User-Agent` instead of `User-Agent` for end-user browser info
- [ ] (Optional) Add `app_name` and `app_version` to config for better server-side tracking
- [ ] Run `composer cs-fix && composer phpstan && composer test`

---

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
