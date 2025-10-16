# Swotto PHP SDK

Official PHP client library for integrating with the **SW4 API** - a comprehensive B2B/ERP platform providing centralized access to:

- **Customer & Supplier Management** (Master Data/Anagrafiche)
- **Inventory & Stock Management** (Magazzino)
- **Product Information Management** (PIM)
- **Document Management** (Orders, Invoices, DDT, Agreements)

Swotto simplifies API integration with built-in authentication, error handling, file operations, and smart response parsing.

[![Latest Version](https://img.shields.io/packagist/v/agenziasmart/swotto.svg)](https://packagist.org/packages/agenziasmart/swotto)
[![PHP Version](https://img.shields.io/packagist/php-v/agenziasmart/swotto.svg)](https://packagist.org/packages/agenziasmart/swotto)
[![License](https://img.shields.io/packagist/l/agenziasmart/swotto.svg)](LICENSE)

## Why Swotto?

✅ **Zero boilerplate** - Reduces API integration code by 87.5%
✅ **Type-safe** - PHPStan Level 8 compliant
✅ **Resilient** - Built-in Circuit Breaker pattern
✅ **Flexible** - Dual authentication (DevApp + Bearer tokens)
✅ **Smart responses** - Auto-detect JSON, CSV, PDF formats
✅ **Battle-tested** - 78 comprehensive test cases

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Authentication](#authentication)
  - [DevApp Token](#devapp-token-application-authentication)
  - [Bearer Token](#bearer-token-user-authentication)
  - [Complete Authentication Flow](#complete-authentication-flow)
- [Basic Usage](#basic-usage)
  - [HTTP Methods](#http-methods)
  - [Parsed Responses](#parsed-responses)
  - [File Uploads](#file-uploads)
- [Advanced Features](#advanced-features)
  - [Multi-Format Responses](#multi-format-responses)
  - [Circuit Breaker Pattern](#circuit-breaker-pattern)
  - [POP Methods](#pop-methods)
- [Common Use Cases](#common-use-cases)
  - [Fetching Customers with Pagination](#fetching-customers-with-pagination)
  - [Creating a New Order](#creating-a-new-order)
  - [Downloading a PDF Invoice](#downloading-a-pdf-invoice)
- [Error Handling](#error-handling)
  - [Exception Hierarchy](#exception-hierarchy)
  - [Best Practices](#best-practices)
  - [Handling Rate Limits](#handling-rate-limits)
- [Configuration Reference](#configuration-reference)
- [Testing](#testing)
- [FAQ](#faq)
- [Advanced FAQ](#advanced-faq)
- [Support](#support)
- [License](#license)

## Installation

Install via Composer:

```bash
composer require agenziasmart/swotto
```

### Requirements

- PHP 8.1 or higher
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
    'key' => 'YOUR_DEVAPP_TOKEN'
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
    'key' => 'YOUR_DEVAPP_TOKEN'  // Get from SW4 Dashboard
]);
```

> **Security Note**: Never commit DevApp tokens to version control. Use environment variables:
> ```php
> $client = new Client([
>     'url' => $_ENV['SW4_API_URL'],
>     'key' => $_ENV['SW4_DEVAPP_TOKEN']
> ]);
> ```

### Bearer Token (User Authentication)

Authenticates specific end users within your application. **Optional but recommended.**

```php
// Set user token after login
$client->setAccessToken($userBearerToken);

// Now all requests are made on behalf of this user
$userOrders = $client->get('orders');

// Clear token on logout
$client->clearAccessToken();

// Get current token
$token = $client->getAccessToken();
```

### Complete Authentication Flow

Practical example combining DevApp authentication, Bearer token, and request context:

```php
// 1. Initialize with DevApp token (identifies your application)
$client = new Client([
    'url' => 'https://api.sw4.it',
    'key' => 'YOUR_DEVAPP_TOKEN'
]);

// 2. Set end-user context
$client->setClientIp($_SERVER['REMOTE_ADDR']);
$client->setClientUserAgent($_SERVER['HTTP_USER_AGENT']);

// 3. User login in your application
$loginResponse = $client->post('auth/login', [
    'email' => 'user@example.com',
    'password' => 'password'
]);

// 4. Set Bearer token for authenticated requests
$client->setAccessToken($loginResponse['data']['token']);

// 5. Now all requests are authenticated with full context
$profile = $client->get('account/profile');
$customers = $client->getParsed('customers');
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
    'email' => 'john@example.com'
]);

// PUT request (full update)
$result = $client->put('customers/123', [
    'name' => 'Jane Doe'
]);

// PATCH request (partial update)
$result = $client->patch('customers/123', [
    'email' => 'jane@example.com'
]);

// DELETE request
$result = $client->delete('customers/123');
```

### Parsed Responses

Automatic response parsing with standardized structure:

```php
// Returns: ['data' => [...], 'paginator' => [...], 'success' => true]
$parsed = $client->getParsed('customers');
$parsed = $client->postParsed('customers', $data);
$parsed = $client->patchParsed('customers/123', $data);
$parsed = $client->putParsed('customers/123', $data);
$parsed = $client->deleteParsed('customers/123');

// Access data and pagination
$customers = $parsed['data'];
$paginator = $parsed['paginator'];
echo "Retrieved {$paginator['count']} of {$paginator['total']} customers";
```

### File Uploads

Simple file upload with multipart form data:

```php
// Upload single file
$fileHandle = fopen('/path/to/document.pdf', 'r');
$result = $client->postFile('documents', $fileHandle, 'document', [
    'title' => 'Important Document',
    'category' => 'contracts'
]);

// Upload multiple files
$files = [
    'attachment1' => fopen('/path/to/file1.pdf', 'r'),
    'attachment2' => fopen('/path/to/file2.jpg', 'r'),
];
$result = $client->postFiles('documents/batch', $files, [
    'batch_name' => 'Monthly Reports'
]);

// Update with file (PUT)
$fileHandle = fopen('/path/to/updated.pdf', 'r');
$result = $client->putFile('documents/123', $fileHandle);

// Patch with file
$fileHandle = fopen('/path/to/partial.pdf', 'r');
$result = $client->patchFile('documents/123', $fileHandle);
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

### Circuit Breaker Pattern

Automatic fail-fast behavior for unstable APIs:

```php
use Psr\SimpleCache\CacheInterface;
use Swotto\Http\GuzzleHttpClient;

// Enable circuit breaker
$config = [
    'url' => 'https://api.sw4.it',
    'circuit_breaker_enabled' => true,
    'circuit_breaker_failure_threshold' => 5,    // Open after 5 failures
    'circuit_breaker_recovery_timeout' => 30     // Retry after 30 seconds
];

$client = new Client(
    $config,
    $logger,
    GuzzleHttpClient::withCircuitBreaker(
        new \Swotto\Config\Configuration($config),
        $logger,
        $cache  // PSR-16 cache implementation (Redis, Memcached, etc.)
    )
);

try {
    $result = $client->get('customers');
} catch (\Swotto\Exception\CircuitBreakerOpenException $e) {
    // API temporarily unavailable, circuit breaker is open
    echo "Service unavailable, retry after: " . $e->getRetryAfter() . " seconds";
}
```

### POP Methods

Convenience methods for common lookup data:

```php
// Country list
$countries = $client->getCountryPop();

// Gender options
$genders = $client->getGenderPop();

// System languages
$languages = $client->getSysLanguagePop();

// Currency list
$currencies = $client->getCurrencyPop();

// And many more: getCustomerPop(), getSupplierPop(), getWarehousePop(), etc.
```

## Common Use Cases

### Fetching Customers with Pagination

```php
use Swotto\Client;

$client = new Client([
    'url' => 'https://api.sw4.it',
    'key' => 'YOUR_DEVAPP_TOKEN'
]);

try {
    // Get parsed response with pagination metadata
    $response = $client->getParsed('customers', [
        'query' => [
            'page' => 1,
            'limit' => 50
        ]
    ]);

    $customers = $response['data'];
    $pagination = $response['paginator'];

    echo "Retrieved {$pagination['count']} of {$pagination['total']} customers\n";

    foreach ($customers as $customer) {
        echo "- {$customer['name']} ({$customer['email']})\n";
    }

} catch (\Swotto\Exception\AuthenticationException $e) {
    echo "Authentication failed: " . $e->getMessage();
} catch (\Swotto\Exception\ApiException $e) {
    echo "API error: " . $e->getMessage();
}
```

### Creating a New Order

```php
$orderData = [
    'customer_id' => 'cust_123456',
    'items' => [
        ['product_id' => 'prod_789', 'quantity' => 2],
        ['product_id' => 'prod_456', 'quantity' => 1]
    ],
    'shipping_address' => [
        'street' => 'Via Roma 123',
        'city' => 'Milano',
        'postal_code' => '20100'
    ]
];

try {
    $result = $client->post('orders', $orderData);

    echo "Order created: {$result['data']['order_id']}\n";

} catch (\Swotto\Exception\ValidationException $e) {
    echo "Validation errors:\n";
    $errors = $e->getErrorData();
    foreach ($errors as $field => $messages) {
        echo "- {$field}: " . implode(', ', (array)$messages) . "\n";
    }
}
```

### Downloading a PDF Invoice

```php
try {
    $response = $client->getResponse('invoices/INV-2024-001/pdf');

    // Check content type
    if ($response->isPdf()) {
        // Save to file with security validations
        $filePath = $response->saveToFile('/path/to/downloads', 'invoice.pdf');
        echo "Invoice saved to: {$filePath}\n";
    }

} catch (\Swotto\Exception\NotFoundException $e) {
    echo "Invoice not found";
} catch (\Swotto\Exception\FileOperationException $e) {
    echo "Failed to save file: " . $e->getMessage();
}
```

## Error Handling

### Exception Hierarchy

```
SwottoExceptionInterface (interface)
└── SwottoException (base class)
    ├── ApiException (HTTP 400-599)
    │   ├── AuthenticationException (401)
    │   ├── ForbiddenException (403)
    │   ├── NotFoundException (404)
    │   ├── ValidationException (422)
    │   └── RateLimitException (429)
    ├── NetworkException (connection issues)
    │   └── ConnectionException
    ├── SecurityException (security violations)
    │   ├── FileOperationException
    │   └── MemoryException
    └── CircuitBreakerOpenException (service unavailable)
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
    foreach ($errors as $field => $messages) {
        echo "{$field}: " . implode(', ', (array)$messages);
    }

} catch (AuthenticationException $e) {
    // Token expired or invalid (401)
    // Redirect to login or refresh token

} catch (NotFoundException $e) {
    // Resource doesn't exist (404)
    // Show 404 page or create new resource

} catch (RateLimitException $e) {
    // Too many requests (429)
    $retryAfter = $e->getRetryAfter(); // seconds
    echo "Rate limited. Retry after {$retryAfter} seconds.";

} catch (NetworkException $e) {
    // Network connectivity issues
    // Retry with exponential backoff

} catch (SwottoException $e) {
    // Catch-all for other API errors
    error_log("API Error: " . $e->getMessage());
}
```

### Handling Rate Limits

```php
use Swotto\Exception\RateLimitException;

function fetchWithRetry($client, $endpoint, $maxRetries = 3) {
    $attempt = 0;

    while ($attempt < $maxRetries) {
        try {
            return $client->get($endpoint);

        } catch (RateLimitException $e) {
            $retryAfter = $e->getRetryAfter();
            echo "Rate limited. Waiting {$retryAfter} seconds...\n";
            sleep($retryAfter);
            $attempt++;
        }
    }

    throw new \Exception("Max retries exceeded");
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
| `access_token` | `string` | `null` | Bearer token for user authentication |
| `session_id` | `string` | `null` | Session ID for session-based auth |

### HTTP Client Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `timeout` | `int` | `30` | Request timeout in seconds |
| `connect_timeout` | `int` | `10` | Connection timeout in seconds |
| `verify` | `bool` | `true` | Verify SSL certificates |

### Circuit Breaker Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `circuit_breaker_enabled` | `bool` | `false` | Enable circuit breaker pattern |
| `circuit_breaker_failure_threshold` | `int` | `5` | Failures before opening circuit |
| `circuit_breaker_recovery_timeout` | `int` | `30` | Seconds before attempting recovery |

### Client Metadata

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `client_user_agent` | `string` | Auto | Custom User-Agent header |
| `client_ip` | `string` | Auto | Client IP address for logging |
| `language` | `string` | `'it'` | Preferred response language |
| `accept` | `string` | `'application/json'` | Accept header |

### Complete Example

```php
$client = new Client([
    // Required
    'url' => 'https://api.sw4.it',

    // Authentication
    'key' => $_ENV['SW4_DEVAPP_TOKEN'],
    'access_token' => $userToken,

    // HTTP
    'timeout' => 60,
    'verify' => true,

    // Circuit Breaker
    'circuit_breaker_enabled' => true,
    'circuit_breaker_failure_threshold' => 5,
    'circuit_breaker_recovery_timeout' => 30,

    // Metadata
    'language' => 'en',
    'client_ip' => $_SERVER['REMOTE_ADDR']
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

### Is circuit breaker required?

No, it's optional. Enable it for production environments to handle API failures gracefully.

### What PHP versions are supported?

PHP 8.1 or higher. We recommend PHP 8.3 for best performance.

### How do I debug API requests?

Inject a PSR-3 logger:

```php
$client = new Client($config);
$client->setLogger($yourPsr3Logger);
```

### Can I use this with Laravel/Symfony/other frameworks?

Yes! Swotto is framework-agnostic and works with any PHP application.

## Advanced FAQ

<details>
<summary><strong>📊 Response Methods & Data Handling</strong></summary>

### What's the difference between `get()`, `getParsed()`, and `getResponse()`?

**`get()` [RECOMMENDED]**: Returns the response exactly as provided by the SW4 API. Pattern aligned with AWS SDK, Twilio, Stripe - maximum predictability.

```php
$response = $client->get('customers');
$customers = $response['data'];
$totalPages = $response['meta']['pagination']['total_pages'];
```

**`getParsed()`**: Optional helper that transforms `meta.pagination` into a `paginator` object with additional calculated fields (e.g., `range` array for UI pagination). Use only if you need this convenience.

```php
$parsed = $client->getParsed('customers');
$paginator = $parsed['paginator']; // with 'range', 'current', 'last' pre-calculated
```

**`getResponse()`**: For multi-format content (CSV, PDF, binary). Returns `SwottoResponse` object with methods like `isPdf()`, `isCsv()`, `saveToFile()`.

```php
$response = $client->getResponse('exports/report');
if ($response->isPdf()) {
    $response->saveToFile('/path/to/file.pdf');
}
```

### When should I use `getParsed()` instead of `get()`?

Use `getParsed()` only if:
- You're building pagination UI and want pre-calculated `range` array
- You prefer `paginator['current']` instead of `meta['pagination']['current_page']`

For all other cases, **use `get()`** - ensures what you read in SW4 API documentation matches exactly what you receive in the SDK.

### How do I handle pagination with `get()`?

```php
$response = $client->get('customers', ['query' => ['page' => 1, 'limit' => 50]]);

$customers = $response['data'];
$pagination = $response['meta']['pagination'];

echo "Page {$pagination['current_page']} of {$pagination['total_pages']}";
echo "Total: {$pagination['total']} customers";

// Next page
if ($pagination['current_page'] < $pagination['total_pages']) {
    $nextPage = $client->get('customers', [
        'query' => ['page' => $pagination['current_page'] + 1]
    ]);
}
```

### Can I automatically iterate through all pages?

```php
function fetchAllPages($client, $endpoint) {
    $allData = [];
    $page = 1;

    do {
        $response = $client->get($endpoint, [
            'query' => ['page' => $page, 'limit' => 100]
        ]);
        $allData = array_merge($allData, $response['data']);

        $pagination = $response['meta']['pagination'] ?? null;
        $page++;

    } while ($pagination && $page <= $pagination['total_pages']);

    return $allData;
}
```

</details>

<details>
<summary><strong>🔐 Authentication & Multi-tenancy</strong></summary>

### Can I use the SDK without a DevApp token?

No. The DevApp token is **required** for all requests. It identifies your application and determines which SW4 organization you have access to.

```php
// ❌ Fails with AuthenticationException
$client = new Client(['url' => 'https://api.sw4.it']);

// ✅ Correct
$client = new Client([
    'url' => 'https://api.sw4.it',
    'key' => $_ENV['SW4_DEVAPP_TOKEN']
]);
```

### How do I get a DevApp token?

1. Log in to your SW4 account: `https://app.sw4.it`
2. Go to **Settings → DevApps**
3. Create a new application and copy the token
4. **Never** commit the token - use environment variables

### What's the purpose of the Bearer token if I already have a DevApp token?

**DevApp token** = identifies YOUR application (required)
**Bearer token** = identifies the END USER using your app (optional)

```php
// Your SaaS app has 1000 end users
$client = new Client([
    'key' => 'YOUR_APP_DEVAPP_TOKEN' // identifies your app
]);

// User1 logs in to your app
$client->setAccessToken($user1Token); // identifies User1
$orders1 = $client->get('orders'); // sees User1's orders

// User2 logs in
$client->setAccessToken($user2Token); // switch to User2
$orders2 = $client->get('orders'); // sees User2's orders
```

### Is data isolated between organizations?

Yes! **Multi-tenancy guaranteed**:
- DevApp token determines the organization (`_oid`)
- All data automatically filtered for that organization
- Impossible to access other organizations' data even with valid Bearer token

**Isolation priority**: DevApp `_oid` **always overrides** User/Account `_oid`.

</details>

<details>
<summary><strong>📁 File Operations</strong></summary>

### How do I upload a single file with metadata?

```php
$fileHandle = fopen('/path/to/document.pdf', 'r');

$response = $client->postFile(
    'documents',
    $fileHandle,
    'attachment', // form field name
    [
        'title' => 'Contract 2025',
        'category' => 'legal',
        'tags' => ['contract', 'important']
    ]
);

fclose($fileHandle);
echo "Document ID: {$response['data']['id']}";
```

### How do I upload multiple files simultaneously?

```php
$files = [
    'invoice' => fopen('/path/to/invoice.pdf', 'r'),
    'receipt' => fopen('/path/to/receipt.jpg', 'r'),
    'contract' => fopen('/path/to/contract.pdf', 'r'),
];

$response = $client->postFiles('documents/batch', $files, [
    'batch_name' => 'January 2025 Documents',
    'category' => 'accounting'
]);

// Close all files
foreach ($files as $handle) {
    fclose($handle);
}
```

### How does Swotto handle very large files?

Automatic streaming:
- **< 10MB**: in-memory loading (fast)
- **> 10MB**: automatic streaming (memory-safe)
- **> 50MB**: `MemoryException` thrown (protection)

For large downloads, use `downloadToFile()` instead of `get()`:

```php
// ❌ 200MB file loaded in memory = crash
$bigFile = $client->get('exports/huge-dataset.csv');

// ✅ Direct download to disk = memory-safe
$client->downloadToFile('exports/huge-dataset.csv', '/path/to/file.csv');
```

### Can I update only the file without touching metadata?

```php
// PATCH = update only file, metadata remains
$newFile = fopen('/path/to/updated.pdf', 'r');
$client->patchFile('documents/123', $newFile);

// PUT = replace everything (file + metadata reset)
$client->putFile('documents/123', $newFile);
```

</details>

<details>
<summary><strong>⚡ Circuit Breaker Pattern</strong></summary>

### What is Circuit Breaker and when should I use it?

Resilience pattern that prevents "cascading failures" when the SW4 API has issues.

**States**:
1. **CLOSED**: API ok → normal requests
2. **OPEN**: API failed N times → block all requests (fail-fast)
3. **HALF_OPEN**: after timeout → test with 1 request

**When to enable:**
- ✅ Production environment
- ✅ Critical external APIs where API downtime shouldn't crash your app
- ❌ Development/testing (unnecessary overhead)

### How do I enable Circuit Breaker?

```php
use Swotto\Client;
use Swotto\Http\GuzzleHttpClient;
use Swotto\Config\Configuration;

$config = [
    'url' => 'https://api.sw4.it',
    'key' => $_ENV['SW4_DEVAPP_TOKEN'],
    'circuit_breaker_enabled' => true,
    'circuit_breaker_failure_threshold' => 5,   // OPEN after 5 failures
    'circuit_breaker_recovery_timeout' => 30,   // retry after 30s
];

// Requires PSR-16 cache (Redis, Memcached, etc.)
$cache = new \Symfony\Component\Cache\Psr16Cache(
    new \Symfony\Component\Cache\Adapter\RedisAdapter($redisClient)
);

$client = new Client(
    $config,
    $logger,
    GuzzleHttpClient::withCircuitBreaker(
        new Configuration($config),
        $logger,
        $cache
    ),
    $cache
);
```

### What happens when the circuit breaker is OPEN?

```php
use Swotto\Exception\CircuitBreakerOpenException;

try {
    $response = $client->get('customers');
} catch (CircuitBreakerOpenException $e) {
    // API temporarily unavailable
    $retryAfter = $e->getRetryAfter(); // seconds

    // Show user-friendly message
    echo "Service temporarily unavailable. Retry in {$retryAfter} seconds.";

    // Or use cached data
    $customers = $cachedData;
}
```

</details>

<details>
<summary><strong>📊 POP Methods (Lookup Data)</strong></summary>

### What are POPs and when should I use them?

POP = "lookup data" from SW4 (dropdowns, select options). Examples: countries, languages, currencies, genders.

27+ pre-configured helper methods with **automatic 1-hour cache**:

```php
// Country list for dropdown
$countries = $client->getCountryPop();
foreach ($countries as $country) {
    echo "<option value='{$country['code']}'>{$country['name']}</option>";
}

// Gender options
$genders = $client->getGenderPop();

// Currencies
$currencies = $client->getCurrencyPop();

// System languages
$languages = $client->getSysLanguagePop();

// Customer/Supplier for autocomplete
$customers = $client->getCustomerPop();
$suppliers = $client->getSupplierPop();
```

### Do POP methods make HTTP requests every time?

No! **Automatic PSR-16 cache for 1 hour**:
- First call: HTTP request → cache
- Subsequent calls (< 1h): read from cache
- After 1h: cache expired → new HTTP request

**Requires**: PSR-16 cache injected in the client (Redis, Memcached, APCu, File).

### What's the complete list of available POPs?

```php
// Geographic
$client->getCountryPop();
$client->getContinentPop();
$client->getProvincePop();

// Master data
$client->getGenderPop();
$client->getCustomerPop();
$client->getSupplierPop();

// System
$client->getSysLanguagePop();
$client->getCurrencyPop();
$client->getWarehousePop();
$client->getDocumentTypePop();

// Business
$client->getPaymentMethodPop();
$client->getShippingMethodPop();
$client->getTaxRatePop();

// ... total 27+ methods
// Complete list: see PopTrait or SW4 API docs
```

</details>

<details>
<summary><strong>🛡️ Error Handling</strong></summary>

### How do I handle validation errors (422)?

```php
use Swotto\Exception\ValidationException;

try {
    $response = $client->post('customers', [
        'email' => 'invalid-email', // missing @
        'age' => 'twenty' // must be number
    ]);
} catch (ValidationException $e) {
    $errors = $e->getErrorData();

    // $errors = [
    //     'email' => ['The email field must be a valid email address.'],
    //     'age' => ['The age field must be a number.']
    // ]

    foreach ($errors as $field => $messages) {
        echo "{$field}: " . implode(', ', (array)$messages) . "\n";
    }
}
```

### How do I implement retry with exponential backoff?

```php
use Swotto\Exception\{NetworkException, RateLimitException};

function retryRequest($client, $endpoint, $maxRetries = 3) {
    $attempt = 0;
    $baseDelay = 1; // seconds

    while ($attempt < $maxRetries) {
        try {
            return $client->get($endpoint);

        } catch (RateLimitException $e) {
            // Use API's Retry-After header
            sleep($e->getRetryAfter());

        } catch (NetworkException $e) {
            // Exponential backoff: 1s, 2s, 4s, 8s
            $delay = $baseDelay * (2 ** $attempt);
            sleep($delay);
            $attempt++;
        }
    }

    throw new \Exception("Max retries ({$maxRetries}) exceeded");
}
```

### What's the complete exception hierarchy?

```
SwottoExceptionInterface
└── SwottoException (base)
    ├── ApiException (HTTP 400-599)
    │   ├── AuthenticationException (401)
    │   ├── ForbiddenException (403)
    │   ├── NotFoundException (404)
    │   ├── ValidationException (422)
    │   └── RateLimitException (429)
    ├── NetworkException (connectivity)
    │   └── ConnectionException
    ├── SecurityException
    │   ├── FileOperationException (path traversal, etc.)
    │   └── MemoryException (>50MB)
    └── CircuitBreakerOpenException
```

**Best practice**: catch from specific to generic.

### How do I log all requests for debugging?

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('swotto');
$logger->pushHandler(new StreamHandler('/var/log/swotto.log', Logger::DEBUG));

$client = new Client(['url' => '...', 'key' => '...']);
$client->setLogger($logger);

// Automatically logs:
// - Request: method, URI, headers (without tokens!), body
// - Response: status, body
// - Errors: complete exceptions
```

</details>

<details>
<summary><strong>⚙️ Performance & Best Practices</strong></summary>

### How do I reduce the number of API calls?

1. **Local cache** for static data (POPs, configurations)
2. **Batch requests** where available (`postFiles`)
3. **Efficient pagination** (reasonable limits: 50-100 records/page)
4. **Circuit breaker** in production (prevents retry storms)
5. **PSR-16 cache** for POP methods (reduce 1000+ calls → 1 call/hour)

### Can I make parallel HTTP requests?

Yes! Use Guzzle async:

```php
use GuzzleHttp\Promise;

// Prepare promises
$promises = [
    'customers' => $httpClient->requestAsync('GET', 'customers'),
    'orders' => $httpClient->requestAsync('GET', 'orders'),
    'products' => $httpClient->requestAsync('GET', 'products'),
];

// Execute in parallel
$results = Promise\Utils::unwrap($promises);

// Use results
$customers = $results['customers'];
$orders = $results['orders'];
```

**Note**: Swotto Client doesn't expose async methods directly - you must use the underlying HttpClient.

### How do I handle development vs production environments?

```php
// config/swotto.php
return [
    'development' => [
        'url' => 'https://api-dev.sw4.it',
        'key' => $_ENV['SW4_DEV_TOKEN'],
        'verify' => false, // self-signed SSL ok
        'timeout' => 120, // longer debug timeout
        'circuit_breaker_enabled' => false,
    ],
    'production' => [
        'url' => 'https://api.sw4.it',
        'key' => $_ENV['SW4_PROD_TOKEN'],
        'verify' => true, // strict SSL
        'timeout' => 30,
        'circuit_breaker_enabled' => true,
        'circuit_breaker_failure_threshold' => 5,
        'circuit_breaker_recovery_timeout' => 30,
    ],
];

// Bootstrap
$env = $_ENV['APP_ENV'] ?? 'production';
$config = require 'config/swotto.php';
$client = new Client($config[$env]);
```

### Is Swotto thread-safe for multi-threaded applications?

**Client object**: No, not thread-safe. Each thread must create its own Client instance.

```php
// ❌ Don't share between threads
$sharedClient = new Client($config);

// ✅ Create instance per thread
function worker($config) {
    $client = new Client($config);
    // ... use $client only in this thread
}
```

**PSR-16 cache**: Depends on implementation (Redis/Memcached = thread-safe, APCu = no).

### How do I integrate Swotto with Laravel?

```php
// config/services.php
'swotto' => [
    'url' => env('SW4_API_URL'),
    'key' => env('SW4_DEVAPP_TOKEN'),
],

// app/Providers/AppServiceProvider.php
use Swotto\Client;

public function register() {
    $this->app->singleton(Client::class, function ($app) {
        return new Client(config('services.swotto'));
    });
}

// Controller usage
public function index(Client $swotto) {
    $customers = $swotto->get('customers');
    return view('customers', compact('customers'));
}
```

</details>

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

**Copyright © 2025 AgenziaSmart**
