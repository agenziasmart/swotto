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
