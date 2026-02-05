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
- **Tested** - 222 tests, 743 assertions

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

use Swotto\Client;

// Initialize the client
$client = new Client([
    'url' => 'https://api.sw4.it',
    'key' => 'YOUR_DEVAPP_TOKEN',
]);

// Make your first API call
$customers = $client->get('customers');
print_r($customers);
```

## Authentication

Swotto supports **dual authentication** to identify both your application and end users.

### DevApp Token (Application Authentication)

Identifies your third-party application to SW4. **Required for all requests.**

```php
$client = new Client([
    'url' => 'https://api.sw4.it',
    'key' => 'YOUR_DEVAPP_TOKEN',
]);
```

> **Security Note**: Never commit DevApp tokens to version control. Use environment variables:
> ```php
> $client = new Client([
>     'url' => $_ENV['SW4_API_URL'],
>     'key' => $_ENV['SW4_DEVAPP_TOKEN'],
> ]);
> ```

### Bearer Token (User Authentication)

Authenticates specific end users within your application. Can be set as default or per-call.

```php
// Option A: Config default (applied to every request)
$client = new Client([
    'url' => 'https://api.sw4.it',
    'key' => 'YOUR_DEVAPP_TOKEN',
    'bearer_token' => $userBearerToken,
]);

// Option B: Per-call (overrides default for this request)
$orders = $client->get('orders', [
    'bearer_token' => $userBearerToken,
]);
```

### Complete Authentication Flow

```php
// 1. Initialize with DevApp token and user context
$client = new Client([
    'url' => 'https://api.sw4.it',
    'key' => 'YOUR_DEVAPP_TOKEN',
    'client_ip' => $_SERVER['REMOTE_ADDR'],
    'client_user_agent' => $_SERVER['HTTP_USER_AGENT'],
]);

// 2. User login
$loginResponse = $client->post('auth/login', [
    'email' => 'user@example.com',
    'password' => 'password',
]);

// 3. Create authenticated client with Bearer token
$authClient = new Client([
    'url' => 'https://api.sw4.it',
    'key' => 'YOUR_DEVAPP_TOKEN',
    'bearer_token' => $loginResponse['data']['token'],
    'client_ip' => $_SERVER['REMOTE_ADDR'],
    'client_user_agent' => $_SERVER['HTTP_USER_AGENT'],
]);

// 4. All requests are now authenticated
$profile = $authClient->get('account/profile');
$customers = $authClient->get('customers');
```

**For FrankenPHP/Swoole workers**, use per-call options instead:

```php
$client = new Client([
    'url' => 'https://api.sw4.it',
    'key' => 'YOUR_DEVAPP_TOKEN',
]);

// Each request carries its own context - no state leakage
$profile = $client->get('account/profile', [
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

```php
// GET request
$data = $client->get('customers');
$data = $client->get('customers', ['query' => ['limit' => 10]]);

// POST request
$result = $client->post('customers', [
    'name' => 'John Doe',
    'email' => 'john@example.com',
]);

// PUT request (full update)
$result = $client->put('customers/123', [
    'name' => 'Jane Doe',
]);

// PATCH request (partial update)
$result = $client->patch('customers/123', [
    'email' => 'jane@example.com',
]);

// DELETE request
$result = $client->delete('customers/123');
```

### Pagination

```php
$response = $client->get('customers', ['query' => ['page' => 1, 'limit' => 50]]);

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
$response = $client->getResponse('reports/monthly');

// Content type detection
if ($response->isJson()) {
    $data = $response->asArray();
} elseif ($response->isCsv()) {
    $csv = $response->asString();
} elseif ($response->isPdf()) {
    $response->saveToFile('/path/to/report.pdf');
}

// Direct file download
$client->downloadToFile('exports/large-dataset.csv', '/path/to/data.csv');
```

### Retry with Exponential Backoff

Automatic retry for transient errors with configurable backoff:

```php
$client = new Client([
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
// - Rate limits (429 - respects Retry-After header)

// NO retry on client errors:
// - 401 Unauthorized
// - 403 Forbidden
// - 404 Not Found
// - 422 Validation Error
```

### Per-Call Options

Pass request-specific parameters directly in options:

```php
$client = new Client([
    'url' => 'https://api.sw4.it',
    'key' => 'YOUR_DEVAPP_TOKEN',
]);

// Each request carries its own context
$ordersA = $client->get('orders', [
    'bearer_token' => $userAToken,
    'client_ip' => $requestA->getClientIp(),
    'language' => 'it',
]);

// No state leakage between requests
$ordersB = $client->get('orders', [
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
| `client_user_agent` | `User-Agent` | Original client User-Agent |

### Default Options Pattern

Set context options in config as defaults. Per-call options override defaults.

```php
$client = new Client([
    'url' => 'https://api.sw4.it',
    'key' => 'YOUR_DEVAPP_TOKEN',
    'bearer_token' => 'default-token',  // applied to every request
    'language' => 'it',                  // applied to every request
]);

// Uses defaults: bearer_token=default-token, language=it
$data = $client->get('customers');

// Override language for this request only
$data = $client->get('customers', ['language' => 'en']);

// Next request uses default 'it' again (immutable)
$other = $client->get('products');
```

## File Uploads

```php
// Upload single file
$fileHandle = fopen('/path/to/document.pdf', 'r');
$result = $client->postFile('documents', $fileHandle, 'document', [
    'title' => 'Important Document',
    'category' => 'contracts',
]);

// Upload multiple files
$files = [
    'attachment1' => fopen('/path/to/file1.pdf', 'r'),
    'attachment2' => fopen('/path/to/file2.jpg', 'r'),
];
$result = $client->postFiles('documents/batch', $files, [
    'batch_name' => 'Monthly Reports',
]);

// Update with file (PUT)
$fileHandle = fopen('/path/to/updated.pdf', 'r');
$result = $client->putFile('documents/123', $fileHandle);

// Patch with file
$fileHandle = fopen('/path/to/partial.pdf', 'r');
$result = $client->patchFile('documents/123', $fileHandle);
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
    |   +-- ValidationException (422)
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
    $result = $client->post('customers', $data);

} catch (ValidationException $e) {
    // Handle validation errors (422)
    $errors = $e->getErrorData();

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
| `retry_max_delay_ms` | `int` | `10000` | Maximum delay cap in milliseconds |
| `retry_multiplier` | `float` | `2.0` | Exponential backoff multiplier (1.0-5.0) |
| `retry_jitter` | `bool` | `true` | Add +/-25% randomization |

### Client Metadata

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `client_user_agent` | `string` | `null` | Custom User-Agent header |
| `client_ip` | `string` | `null` | Client IP address |
| `language` | `string` | `null` | Preferred response language |

### Complete Example

```php
$client = new Client([
    // Required
    'url' => 'https://api.sw4.it',

    // Authentication
    'key' => $_ENV['SW4_DEVAPP_TOKEN'],
    'bearer_token' => $userToken,

    // HTTP
    'timeout' => 60,
    'verify_ssl' => true,

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
composer test -- --filter ClientTest

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
$client = new Client($config, $yourPsr3Logger);
```

### Can I use this with Laravel/Symfony/other frameworks?

Yes! Swotto is framework-agnostic and works with any PHP application.

### Is Swotto thread-safe?

Yes. The client is fully immutable - no mutable state. A single client instance can be safely shared across requests in FrankenPHP/Swoole workers using per-call options.

### How do I handle large file downloads?

Use `downloadToFile()` for memory-safe streaming to disk:

```php
// Direct download to disk (memory-safe)
$client->downloadToFile('exports/huge-dataset.csv', '/path/to/file.csv');
```

Automatic streaming: < 10MB in-memory, > 10MB streamed, > 50MB throws `MemoryException`.

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
