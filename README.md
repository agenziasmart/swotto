# Swotto PHP SDK

Official PHP client library for integrating with the **SW4 API** - a comprehensive B2B/ERP platform providing centralized access to:

- **Customer & Supplier Management** (Master Data/Anagrafiche)
- **Inventory & Stock Management** (Magazzino)
- **Product Information Management** (PIM)
- **Document Management** (Orders, Invoices, DDT, Agreements)

Swotto simplifies API integration with built-in authentication, error handling, file operations, and smart response handling.

[![Latest Version](https://img.shields.io/packagist/v/agenziasmart/swotto.svg)](https://packagist.org/packages/agenziasmart/swotto)
[![PHP Version](https://img.shields.io/packagist/php-v/agenziasmart/swotto.svg)](https://packagist.org/packages/agenziasmart/swotto)
[![License](https://img.shields.io/packagist/l/agenziasmart/swotto.svg)](LICENSE)

## Why Swotto?

- **Type-safe** - PHPStan Level 8 compliant
- **Resilient** - Built-in Retry with Exponential Backoff
- **Immutable** - Fully stateless, worker-safe (FrankenPHP/Swoole)
- **Flexible** - Dual authentication (DevApp + Bearer tokens)
- **Smart responses** - Auto-detect JSON, CSV, PDF formats
- **Tested** - 302 tests, 895 assertions

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Authentication](#authentication)
- [Basic Usage](#basic-usage)
- [Advanced Features](#advanced-features)
  - [Multi-Format Responses](#multi-format-responses)
  - [Retry with Exponential Backoff](#retry-with-exponential-backoff)
  - [Per-Call Options](#per-call-options)
  - [Default Options Pattern](#default-options-pattern)
- [File Uploads](#file-uploads)
- [Error Handling](#error-handling)
- [Configuration Reference](#configuration-reference)
- [Testing](#testing)
- [FAQ](#faq)
- [Support](#support)
- [License](#license)

## Installation

Install via Composer:

```bash
composer require agenziasmart/swotto
```

### Requirements

- PHP 8.3 or higher
- Composer
- A valid SW4 API account with DevApp credentials

## Quick Start

```php
<?php
require_once 'vendor/autoload.php';

use Swotto\SwottoClient;

// Initialize the client
$client = new SwottoClient([
    'url' => 'https://api.sw4.it',
    'key' => 'YOUR_DEVAPP_TOKEN',
]);

// Make your first API call
$customers = $client->get('customer');
print_r($customers);
```

## Authentication

Swotto supports **dual authentication** to identify both your application and end users.

### DevApp Token (Application Authentication)

Identifies your third-party application to SW4. **Required for all requests.**

```php
$client = new SwottoClient([
    'url' => 'https://api.sw4.it',
    'key' => 'YOUR_DEVAPP_TOKEN',
]);
```

> **Security Note**: Never commit DevApp tokens to version control. Use environment variables:
> ```php
> $client = new SwottoClient([
>     'url' => $_ENV['SW4_API_URL'],
>     'key' => $_ENV['SW4_DEVAPP_TOKEN'],
> ]);
> ```

### Bearer Token (User Authentication)

Authenticates specific end users within your application. Can be set as default or per-call.

```php
// Option A: Config default (applied to every request)
$client = new SwottoClient([
    'url' => 'https://api.sw4.it',
    'key' => 'YOUR_DEVAPP_TOKEN',
    'bearer_token' => $userBearerToken,
]);

// Option B: Per-call (overrides default for this request)
$orders = $client->get('salesorder', [
    'bearer_token' => $userBearerToken,
]);
```

### Complete Authentication Flow

```php
// 1. Initialize with DevApp token and user context
$client = new SwottoClient([
    'url' => 'https://api.sw4.it',
    'key' => 'YOUR_DEVAPP_TOKEN',
    'client_ip' => $_SERVER['REMOTE_ADDR'],
    'client_user_agent' => $_SERVER['HTTP_USER_AGENT'],
]);

// 2. User login
$loginResponse = $client->post('auth', [
    'username' => 'user@example.com',
    'password' => 'YOUR_PASSWORD',
]);

// 3. Create authenticated client with Bearer token
$authClient = new SwottoClient([
    'url' => 'https://api.sw4.it',
    'key' => 'YOUR_DEVAPP_TOKEN',
    'bearer_token' => $loginResponse['data']['access_token'],
    'client_ip' => $_SERVER['REMOTE_ADDR'],
    'client_user_agent' => $_SERVER['HTTP_USER_AGENT'],
]);

// 4. All requests are now authenticated
$profile = $authClient->get('me');
$customers = $authClient->get('customer');

// 5. End the session
$authClient->post('auth/logout');
```

The login response carries `data.access_token` and `data.expires_at`; the token is what
`bearer_token` expects.

**For FrankenPHP/Swoole workers**, use per-call options instead:

```php
$client = new SwottoClient([
    'url' => 'https://api.sw4.it',
    'key' => 'YOUR_DEVAPP_TOKEN',
]);

// Each request carries its own context - no state leakage
$profile = $client->get('me', [
    'bearer_token' => $userToken,
    'client_ip' => $_SERVER['REMOTE_ADDR'],
    'client_user_agent' => $_SERVER['HTTP_USER_AGENT'],
    'language' => 'it',
]);
```

**How It Works**:
- **DevApp token** determines which organization's data you can access
- **Bearer token** identifies which user is making the request
- **Data isolation**: All responses are automatically filtered by organization ID

## Basic Usage

### HTTP Methods

SW4 resources are named in the singular, and records are addressed by UUID:

```php
// GET request
$data = $client->get('customer');
$data = $client->get('customer', ['query' => ['limit' => 10]]);

// POST request
$result = $client->post('customer', [
    'name' => 'ACME Srl',
    'business_code' => 'ACME01',
    'tax_code' => 'CMEXXX00X00X000X',
]);

// PUT request (full update)
$result = $client->put("customer/{$uuid}", [
    'name' => 'ACME Holding Srl',
]);

// PATCH request (partial update)
$result = $client->patch("customer/{$uuid}", [
    'email' => 'info@example.com',
]);

// DELETE request
$result = $client->delete("customer/{$uuid}");
```

### Pagination

Every list endpoint answers with `data` plus a `meta.pagination` block:

```php
$response = $client->get('customer', ['query' => ['page' => 1, 'limit' => 50]]);

$customers = $response['data'];
$pagination = $response['meta']['pagination'];

echo "Page {$pagination['current_page']} of {$pagination['total_pages']}";
echo "Total: {$pagination['total']} customers";
```

## Advanced Features

### Multi-Format Responses

Handle JSON, CSV, PDF, and binary content:

```php
// Get smart response wrapper
$response = $client->getResponse('customer/export/csv');

// Content type detection
if ($response->isJson()) {
    $data = $response->asArray();
} elseif ($response->isCsv()) {
    $rows = $response->asArray();   // one entry per record, keyed by header
    $csv = $response->asString();   // or the raw payload
} elseif ($response->isPdf() || $response->isBinary()) {
    $response->saveToFile('/path/to/report.pdf');
}

// Direct file download
$client->downloadToFile('customer/export/csv', '/path/to/customers.csv');
```

Most SW4 collections expose `{resource}/export/csv` — `customer`, `product`, `supplier`,
`salesorder`, `purchaseorder`, `invoice`, `ddt` and others. The delimiter is detected from
the payload, so a semicolon-separated export parses correctly without configuration.

### Retry with Exponential Backoff

Automatic retry for transient errors with configurable backoff:

```php
$client = new SwottoClient([
    'url' => 'https://api.sw4.it',
    'key' => 'YOUR_DEVAPP_TOKEN',

    // Retry configuration (opt-in)
    'retry_enabled' => true,
    'retry_max_attempts' => 3,           // Total attempts (1 + 2 retries)
    'retry_initial_delay_ms' => 100,     // First retry delay
    'retry_max_delay_ms' => 10000,       // Maximum delay cap
    'retry_multiplier' => 2.0,           // Exponential factor
    'retry_jitter' => true,              // +/-25% randomization
]);

// Automatic retry on:
// - Network errors (NetworkException, ConnectionException)
// - Server errors (5xx status codes)
// - Rate limits (429 - Retry-After is honoured, capped at retry_max_delay_ms)

// NO retry on client errors:
// - 401 Unauthorized
// - 403 Forbidden
// - 404 Not Found
// - 422 Validation Error
```

Only safe and idempotent methods are retried automatically: `GET`, `HEAD`, `PUT`, `DELETE`,
`OPTIONS`, `TRACE`. A network error is ambiguous — the request may well have reached the
server — so replaying a `POST` or `PATCH` could duplicate an order, a document or an upload.
Accept that risk per request when the endpoint is safe to repeat — cancelling an already
cancelled batch, for instance, changes nothing the second time:

```php
$client->post("batch/{$uuid}/cancel", [], ['retry_non_idempotent' => true]);
```

### Per-Call Options

Pass request-specific parameters directly in options:

```php
$client = new SwottoClient([
    'url' => 'https://api.sw4.it',
    'key' => 'YOUR_DEVAPP_TOKEN',
]);

// Each request carries its own context
$ordersA = $client->get('salesorder', [
    'bearer_token' => $userAToken,
    'client_ip' => $requestA->getClientIp(),
    'language' => 'it',
]);

// No state leakage between requests
$ordersB = $client->get('salesorder', [
    'bearer_token' => $userBToken,
    'client_ip' => $requestB->getClientIp(),
    'language' => 'en',
]);
```

**Available per-call options:**

| Option | Header | Description |
|--------|--------|-------------|
| `bearer_token` | `Authorization` | Bearer token for this request |
| `language` | `Accept-Language` | Response language |
| `session_id` | `x-sid` | Session ID |
| `client_ip` | `Client-Ip` | Original client IP |
| `client_user_agent` | `X-Client-User-Agent` | Original client User-Agent |

### Default Options Pattern

Set context options in config as defaults. Per-call options override defaults.

```php
$client = new SwottoClient([
    'url' => 'https://api.sw4.it',
    'key' => 'YOUR_DEVAPP_TOKEN',
    'bearer_token' => 'default-token',  // applied to every request
    'language' => 'it',                  // applied to every request
]);

// Uses defaults: bearer_token=default-token, language=it
$data = $client->get('customer');

// Override language for this request only
$data = $client->get('customer', ['language' => 'en']);

// Next request uses default 'it' again (immutable)
$other = $client->get('product');
```

## File Uploads

Each SW4 upload endpoint expects a specific field name — `logo` for a customer logo,
`document` for a product attachment, `file` for a batch import. Pass it as the third
argument; it is not guessed from the filename.

```php
// Customer logo — field name "logo"
$fileHandle = fopen('/path/to/logo.png', 'r');
$result = $client->postFile("customer/{$uuid}/logo", $fileHandle, 'logo');

// Product attachment — field name "document", with metadata
$fileHandle = fopen('/path/to/datasheet.pdf', 'r');
$result = $client->postFile("product/{$uuid}/documents", $fileHandle, 'document', [
    'title' => 'Technical datasheet',
]);

// CSV batch import — field name "file"
$fileHandle = fopen('/path/to/customers.csv', 'r');
$result = $client->postFile('customer/batch', $fileHandle, 'file');

// Several files in one request
$files = [
    'document' => fopen('/path/to/first.pdf', 'r'),
    'attachment' => fopen('/path/to/second.jpg', 'r'),
];
$result = $client->postFiles("product/{$uuid}/documents", $files, [
    'title' => 'Product pack',
]);

// Replace or amend an existing record with a file
$result = $client->putFile("customer/{$uuid}/logo", fopen('/path/to/new-logo.png', 'r'), 'logo');
$result = $client->patchFile("product/{$uuid}/documents/{$docUuid}", $fileHandle, 'document');
```

## Error Handling

### Exception Hierarchy

```
SwottoExceptionInterface (interface)
+-- SwottoException (base class)
    +-- ApiException (HTTP 400-599)
    |   +-- AuthenticationException (401)
    |   +-- ForbiddenException (403)
    |   +-- NotFoundException (404)
    |   +-- ValidationException (400, 422)
    |   +-- RateLimitException (429)
    +-- NetworkException (connection issues)
    |   +-- ConnectionException
    +-- SecurityException (security violations)
    |   +-- FileOperationException
    |   +-- MemoryException
    +-- StreamingException
```

### Best Practices

```php
use Swotto\Exception\{
    AuthenticationException,
    NotFoundException,
    ValidationException,
    RateLimitException,
    NetworkException,
    SwottoException
};

try {
    $result = $client->post('customer', $data);

} catch (ValidationException $e) {
    // Handle validation errors (400 and 422)
    // SW4 reports the offending fields under error.details, keyed by field name
    $details = $e->getErrorData()['error']['details'] ?? [];

} catch (AuthenticationException $e) {
    // Token expired or invalid (401)

} catch (NotFoundException $e) {
    // Resource doesn't exist (404)

} catch (RateLimitException $e) {
    // Too many requests (429)
    $retryAfter = $e->getRetryAfter(); // seconds

} catch (NetworkException $e) {
    // Network connectivity issues

} catch (SwottoException $e) {
    // Catch-all for other API errors
    error_log("API Error: " . $e->getMessage());
}
```

## Configuration Reference

### Required Options

| Option | Type | Description |
|--------|------|-------------|
| `url` | `string` | SW4 API base URL (e.g., `https://api.sw4.it`) |

### Authentication Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `key` | `string` | `null` | DevApp token for application authentication |
| `bearer_token` | `string` | `null` | Bearer token for user authentication |
| `session_id` | `string` | `null` | Session ID |

### HTTP Client Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `timeout` | `int` | `10` | Request timeout in seconds |
| `verify_ssl` | `bool` | `true` | Verify SSL certificates |

### Retry Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `retry_enabled` | `bool` | `false` | Enable automatic retry with backoff |
| `retry_max_attempts` | `int` | `3` | Total attempts (1-10) |
| `retry_initial_delay_ms` | `int` | `100` | Initial delay in milliseconds |
| `retry_max_delay_ms` | `int` | `10000` | Maximum delay cap in milliseconds, `Retry-After` included |
| `retry_multiplier` | `float` | `2.0` | Exponential backoff multiplier (1.0-5.0) |
| `retry_jitter` | `bool` | `true` | Add +/-25% randomization |

`retry_non_idempotent` is a **per-call** option, not a config key: pass it in the options of
a single `POST` or `PATCH` to allow that request to be retried.

### Client Metadata

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `client_user_agent` | `string` | `null` | End-user User-Agent (sent as `X-Client-User-Agent`) |
| `client_ip` | `string` | `null` | Client IP address |
| `language` | `string` | `null` | Preferred response language |

### App Identification

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `app_name` | `string` | `null` | Your application name (included in `User-Agent`) |
| `app_version` | `string` | `null` | Your application version (included in `User-Agent`) |

### Complete Example

```php
$client = new SwottoClient([
    // Required
    'url' => 'https://api.sw4.it',

    // Authentication
    'key' => $_ENV['SW4_DEVAPP_TOKEN'],
    'bearer_token' => $userToken,

    // HTTP
    'timeout' => 60,
    'verify_ssl' => true,

    // App identification (optional)
    'app_name' => 'MyERP',
    'app_version' => '1.0.0',

    // Retry (handles transient errors)
    'retry_enabled' => true,
    'retry_max_attempts' => 3,

    // Client context (default for all requests)
    'language' => 'en',
    'client_ip' => $_SERVER['REMOTE_ADDR'],
    'client_user_agent' => $_SERVER['HTTP_USER_AGENT'],
]);
```

## Testing

Run the test suite:

```bash
# Run all tests
composer test

# Run specific tests
composer test -- --filter SwottoClientTest

# Code style check
composer cs

# Fix code style
composer cs-fix

# Static analysis
composer phpstan
```

## FAQ

### How do I get DevApp credentials?

Contact SW4 support or visit your organization dashboard at `https://app.sw4.it/settings/devapps`.

### What's the difference between DevApp token and Bearer token?

- **DevApp token**: Identifies your application and determines data scope (organization)
- **Bearer token**: Identifies the end user making requests through your app

### Can I use this SDK without authentication?

No. SW4 API requires at least a DevApp token for all requests.

### What PHP versions are supported?

PHP 8.3 or higher.

### How do I debug API requests?

Inject a PSR-3 logger in the constructor:

```php
$client = new SwottoClient($config, $yourPsr3Logger);
```

### Can I use this with Laravel/Symfony/other frameworks?

Yes! Swotto is framework-agnostic and works with any PHP application.

### Is Swotto thread-safe?

Yes. The client is fully immutable - no mutable state. A single client instance can be safely shared across requests in FrankenPHP/Swoole workers using per-call options.

### How do I handle large file downloads?

Use `downloadToFile()` for memory-safe streaming to disk:

```php
// Direct download to disk (memory-safe)
$client->downloadToFile('product/export/csv', '/path/to/products.csv');
```

`downloadToFile()` streams straight to disk and never buffers the whole body.

`asString()` and `asArray()` read the body in 8 KB chunks and count the bytes actually
received, throwing `MemoryException` past 50 MB. The limit applies whether or not the
response carries a `Content-Length` header, so a chunked response cannot bypass it.

`saveToFile()` rewinds the stream when it can, so saving after inspecting the response
still writes the full content. A non-seekable stream that has already been consumed
raises `StreamingException` instead of writing an empty file, and a body shorter than the
advertised `Content-Length` is rejected rather than saved truncated.

## Support

- **Issues**: [GitHub Issues](https://github.com/agenziasmart/swotto/issues)
- **Email**: support@sw4.it

### Getting Help

1. Check the [FAQ](#faq)
2. Search [existing issues](https://github.com/agenziasmart/swotto/issues)
3. Create a new issue with:
   - SDK version (`composer show agenziasmart/swotto`)
   - PHP version (`php -v`)
   - Minimal code example
   - Expected vs actual behavior

## License

MIT License. See [LICENSE](LICENSE) file for details.

---

**Copyright 2025 AgenziaSmart**
