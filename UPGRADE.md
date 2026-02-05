# Upgrade Guide

## Upgrading to v1.4.0

### Breaking Changes

None. All existing code continues to work.

### Deprecations

The following setter methods now emit `E_USER_DEPRECATED` warnings:

| Deprecated Method | Replacement |
|-------------------|-------------|
| `setAccessToken($token)` | `['bearer_token' => $token]` |
| `setSessionId($sid)` | `['session_id' => $sid]` |
| `setLanguage($lang)` | `['language' => $lang]` |
| `setAccept($accept)` | `['headers' => ['Accept' => $accept]]` |
| `setClientUserAgent($ua)` | `['client_user_agent' => $ua]` |
| `setClientIp($ip)` | `['client_ip' => $ip]` |

### Why This Change?

The setter methods mutate client state, which causes **state leakage** in long-running
processes like FrankenPHP worker mode. Per-call options are stateless and safe.

### Migration Examples

#### Before (v1.3.x)

```php
$client = new Client(['url' => '...', 'key' => '...']);

// State mutation - unsafe in worker mode
$client->setAccessToken($userToken);
$client->setClientIp($_SERVER['REMOTE_ADDR']);
$client->setLanguage('it');

$data = $client->get('customers');
```

#### After (v1.4.0+)

```php
$client = new Client(['url' => '...', 'key' => '...']);

// Stateless - safe in worker mode
$data = $client->get('customers', [
    'bearer_token' => $userToken,
    'client_ip' => $_SERVER['REMOTE_ADDR'],
    'language' => 'it'
]);
```

### Suppressing Deprecation Warnings

If you can't migrate immediately, suppress warnings:

```php
// Temporarily suppress (not recommended)
@$client->setAccessToken($token);

// Or via error handler
set_error_handler(function($errno, $errstr) {
    if (str_contains($errstr, 'deprecated since Swotto')) {
        return true; // suppress
    }
    return false;
}, E_USER_DEPRECATED);
```

### Timeline

- **v1.4.0**: Setters deprecated with warnings
- **v2.0.0** (future): Setters will be removed

We recommend migrating to per-call options as soon as possible.
