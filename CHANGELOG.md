# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-02-05

### Breaking Changes

- **PHP 8.3 required** (was 8.1)
- **Removed Circuit Breaker**: `CircuitBreakerHttpClient`, `CircuitBreaker`, `CircuitState`, `CircuitBreakerOpenException` all removed
  - Remove `circuit_breaker_enabled`, `circuit_breaker_failure_threshold`, `circuit_breaker_recovery_timeout` from config
  - Remove `GuzzleHttpClient::withCircuitBreaker()` factory method
  - Remove `psr/simple-cache` dependency
- **Removed POP methods**: `PopTrait` and all 27+ `get*Pop()` / `fetchPop()` methods removed
- **Removed Parsed methods**: `getParsed()`, `postParsed()`, `putParsed()`, `patchParsed()`, `deleteParsed()` removed
- **Removed all setter methods**: `setAccessToken()`, `clearAccessToken()`, `getAccessToken()`, `hasAccessToken()`, `setSessionId()`, `setLanguage()`, `setAccept()`, `setClientUserAgent()`, `setClientIp()`, `setLogger()` removed
- **Removed `access_token` config key**: Use `bearer_token` instead
- **Removed `accept` config key**: Accept header is always `application/json`
- **Configuration is fully immutable**: No `update()` method, no setters
- **`HttpClientInterface` simplified**: Only `request()` and `requestRaw()`, no `initialize()`
- **Removed `psr/event-dispatcher` dependency**
- **Removed `squizlabs/php_codesniffer` dev dependency** (using php-cs-fixer only)

### Added

- **Default options pattern** (Stripe-inspired): Context options (`bearer_token`, `language`, `session_id`, `client_ip`, `client_user_agent`) can be set in config and are automatically merged with per-call options on every request
- Per-call options always override defaults (`array_merge` semantics)
- `PATCH` method on `ClientInterface`

### Changed

- All properties `readonly` across `Client`, `Configuration`, `RetryHttpClient`
- `Configuration::getHeaders()` returns only transport headers (`Accept`, `x-devapp`); context headers flow via `defaultOptions` → `mergeOptions` → `extractPerCallOptions`
- PHPUnit upgraded to ^11, PHPStan to ^2, Mockery to ^1.6
- PHP CS Fixer uses `@PHP83Migration` ruleset
- Test suite: 222 tests, 743 assertions (was 323 tests in v1.4.0)

### Removed

- `src/CircuitBreaker/` directory (3 files)
- `src/Trait/PopTrait.php`
- `src/Exception/CircuitBreakerOpenException.php`
- All deprecated setter methods from `Client`
- `Configuration::update()` method
- `Configuration::getClientUserAgent()` and `Configuration::getClientIp()` methods
- CRLF/null-byte sanitization in Configuration (no longer needed, context flows via per-call options)

### Migration

See [UPGRADE.md](UPGRADE.md) for detailed migration guide from v1.x to v2.0.0.

---

## [1.4.0] - 2026-02-05

### Added

- **Stateless Per-Call Options**: Pass request-specific parameters directly in `$options` instead of mutating client state
  - `bearer_token`: Override Authorization header for single request
  - `language`: Override Accept-Language for single request
  - `session_id`: Override x-sid header for single request
  - `client_ip`: Set Client-Ip header for single request
  - `client_user_agent`: Set User-Agent for single request
- Pattern inspired by Stripe SDK's `stripe_account` per-request option
- Full test coverage for per-call options in `GuzzleHttpClientTest.php`

### Deprecated

- `setAccessToken()`: Use `['bearer_token' => $token]` in per-call options
- `setSessionId()`: Use `['session_id' => $sid]` in per-call options
- `setLanguage()`: Use `['language' => $lang]` in per-call options
- `setAccept()`: Use `['headers' => ['Accept' => $accept]]` in per-call options
- `setClientUserAgent()`: Use `['client_user_agent' => $ua]` in per-call options
- `setClientIp()`: Use `['client_ip' => $ip]` in per-call options

### Changed

- Remove `$_SERVER` auto-detection for `client_ip` and `client_user_agent` in Configuration
- Deprecation warnings via `trigger_error(E_USER_DEPRECATED)` for all deprecated setters

### Notes

- **Worker-Mode Safe**: SDK can now be used as singleton in FrankenPHP/Swoole without state leakage
- **Backward Compatible**: Deprecated setters still work, just emit deprecation warnings
- **Migration Path**: See UPGRADE.md for step-by-step migration guide

---

## [1.3.0] - 2026-01-14

### Added

- **RetryHttpClient**: New retry pattern decorator with exponential backoff and jitter
  - `retry_enabled` configuration option (boolean)
  - `retry_max_attempts` - Maximum retry attempts (default: 3)
  - `retry_initial_delay_ms` - Initial delay in milliseconds (default: 100)
  - `retry_max_delay_ms` - Maximum delay cap (default: 10000)
  - `retry_multiplier` - Exponential backoff multiplier (default: 2.0)
  - `retry_jitter` - Enable ±25% jitter to prevent thundering herd (default: true)
- Automatic retry on 5xx server errors and network failures
- Respects `Retry-After` header on 429 Rate Limit responses
- Works alongside CircuitBreakerHttpClient for comprehensive resilience

### Changed

- Comprehensive test suite expansion from 98 to **323 tests** (+225 tests, +556 assertions)
  - CircuitBreakerStateTest - Circuit breaker state machine tests
  - ClientFileUploadTest - File upload scenarios
  - ClientMethodsTest - HTTP method coverage
  - EdgeCasesTest - Boundary conditions and edge cases
  - ExceptionFactoryTest - Exception hierarchy tests
  - GuzzleHttpClientTest - HTTP client implementation
  - PopTraitCompleteTest - Complete POP method coverage
  - SwottoResponseAdvancedTest - Response handling edge cases
  - RetryHttpClientTest - Retry pattern tests
  - SecurityTest - Security validation tests
- Enhanced README documentation for Retry and Circuit Breaker patterns

### Notes

- RetryHttpClient is opt-in via `retry_enabled => true` configuration
- Requires PSR-16 cache for state persistence (same as Circuit Breaker)
- No breaking changes - existing code continues to work without modifications

---

## [1.2.0] - 2026-01-06

### Added

- Add 24 POP (lookup data) methods to `ClientInterface`:
  - **Enum-based lookups**: `getGenderPop()`, `getUserRolePop()`, `getShiptypePop()`
  - **System lookups**: `getCountryPop()`, `getSysLanguagePop()`, `getCurrencyPop()`
  - **Entity lookups**: `getCustomerPop()`, `getSupplierPop()`, `getProductPop()`, `getCarrierPop()`, `getCategoryPop()`, `getWarehousePop()`, `getWarehouseZonePop()`, `getProjectPop()`, `getTemplatePop()`, `getFamilyPop()`, `getAgreementPop()`
  - **Specialized lookups**: `getIncotermPop()`, `getIncotermByCode()`, `getPaymentType()`, `getWhsreasonPop()`, `getWhsinboundPop()`, `getWhsorderPop()`
  - **Organization**: `getMeOrganization()`

### Notes

- `ClientInterface` now exposes all POP methods from `PopTrait`, enabling proper mocking in tests
- Applications can now type-hint `ClientInterface` for all lookup data operations
- No breaking changes - existing code continues to work without modifications

---

## [1.1.0] - 2026-01-06

### Added

- Add `getResponse()` method to `ClientInterface` for advanced response handling (CSV, PDF, binary)
- Add `downloadToFile()` method to `ClientInterface` for direct file downloads with security validation
- Add `setClientUserAgent()` method to `ClientInterface` for forwarding client metadata
- Add `setClientIp()` method to `ClientInterface` for forwarding client metadata

### Notes

- `ClientInterface` now exposes response handling and client metadata methods
- No breaking changes - existing code continues to work without modifications

---

## [1.0.4] - 2025-12-09

### Security

- **SEC-001: HTTP Header Injection Fix (CWE-113)**: Added `sanitizeHeaderValue()` method to prevent CRLF injection attacks in User-Agent and Client-IP headers
- **SEC-003: SSL Verification Warning**: Added warning log when SSL verification is disabled to alert developers of insecure configuration

### Fixed

- **BUG-001: JSON Decode Type Safety**: Fixed `json_decode()` potentially returning `null` on invalid JSON, now correctly returns empty array to satisfy `array` return type
- **BUG-003: Stream Rewind**: Added `stream->rewind()` before reading response body to prevent empty reads on already-consumed streams
- **PHP-001: PHPStan str_getcsv Fix**: Fixed incorrect `false` comparison for `str_getcsv()` which always returns array in PHP 8.0+

### Changed

- **PHP-004: Final Classes**: Added `final` keyword to core classes (`Client`, `Configuration`, `GuzzleHttpClient`, `SwottoResponse`) to prevent unintended inheritance
- **PHP-003: Readonly Property**: Made `Configuration::$config` property `readonly` to enforce immutability

### Added

- Comprehensive `AUDIT_REPORT.md` with code review findings and remediation plan

### Technical Details

- PHPStan: 0 errors (previously 15 in test files)
- Test Coverage: 98 tests, 290 assertions
- All quality checks passing: PSR-12, PHPStan Level 8
- Zero breaking changes for public API

---

## [1.0.3] - 2025-10-31

### Fixed

- **Critical Security Fix: Binary String Sanitization**: Fixed incomplete log sanitization where binary data passed as **strings** (e.g., from `file_get_contents()`) were logged in full instead of being sanitized
  - Root cause: The `sanitizeOptionsForLogging()` method only checked `!is_string($contents)`, which sanitized resources/streams but **missed binary strings**
  - Impact: Binary file uploads (images, PDFs, documents) containing PII were logged unencrypted, causing:
    - GDPR Article 5(1)(c) violations (data minimization principle)
    - Massive log file bloat (1.9 MB per file upload observed in production)
    - Potential exposure of personal data (photos, identity documents, signatures)
  - Solution: Added `isBinaryString()` method using sample-based detection (null bytes + UTF-8 validation on first 1KB)
  - Performance: 37x faster than full content scan (~0.001ms per MB), zero memory overhead
  - Accuracy: 99%+ tested with images, PDFs, UTF-8 text, JSON, emoji strings

### Added

- New private method `isBinaryString()` for efficient binary data detection in strings
- Comprehensive test coverage for binary string scenarios:
  - `testSanitizeBinaryStringInMultipart()`: Tests the original bug fix
  - `testPreserveUtf8StringWithEmojisInMultipart()`: Ensures UTF-8 text not flagged as binary

### Technical Details

- Test Coverage: 98 tests (+2), 286 assertions (+10) - was 96/276
- Algorithm: Two-stage detection (512-byte null check + 1KB UTF-8 validation)
- Backward Compatibility: 100% - all existing behavior preserved
- Security: Closes CRITICAL vulnerability in v1.0.2 implementation
- Complies with: OWASP Logging Cheat Sheet, GDPR Article 5 & 32

### Migration Notes

**No action required** - fix is automatic and transparent. Binary strings will now be sanitized in logs as `<binary data: X bytes>` instead of full content.

---

## [1.0.2] - 2025-10-31

### Fixed

- **Critical Security Fix: Log Sanitization for Binary Data**: Fixed critical privacy/GDPR violation where binary file contents and sensitive data were logged in full
- Implemented automatic sanitization of request options before logging to prevent exposure of:
  - Binary file contents in multipart uploads (e.g., avatar images, PDF documents)
  - Stream/resource bodies
  - Sensitive headers (Authorization, Cookie, X-Devapp, API keys)
  - Sensitive form parameters (passwords, tokens, secrets)
  - Sensitive JSON body fields
- Logs now display safe metadata like `<binary data: 12345 bytes>` instead of full binary content
- Prevents log file bloat (files were reaching 1.9 MB for single upload operations)
- Complies with OWASP Logging Cheat Sheet and GDPR data minimization requirements

### Added

- New private method `sanitizeOptionsForLogging()` in `GuzzleHttpClient` for automatic log sanitization
- New private method `getContentSize()` to safely measure content size without exposing data
- Comprehensive test suite: `LogSanitizationTest` with 7 new test cases covering all sanitization scenarios

### Technical Details

- Test Coverage: 96 tests (was 89), 276 assertions (was 234)
- All quality checks passing: PSR-12, PHPStan Level 8
- Zero breaking changes: sanitization is automatic and transparent to users
- Performance impact: minimal (only affects logging path, not actual HTTP requests)

---

## [1.0.1] - 2025-10-16

### Fixed

- **Circuit Breaker False Positives**: Fixed critical bug where circuit breaker was incorrectly incrementing failure count for 4xx client errors (401 Unauthorized, 403 Forbidden, 404 Not Found, 422 Validation, 429 Rate Limit)
- Circuit breaker now correctly increments ONLY for 5xx server errors (500, 502, 503, 504) and network failures (NetworkException, ConnectionException)
- Prevents authentication and validation errors from incorrectly triggering circuit breaker state transitions
- Added comprehensive test coverage (11 new tests, 23 new assertions) to validate correct behavior

### Technical Details

- Test Coverage: 89 tests (was 78), 234 assertions (was 211)
- All quality checks passing: PSR-12, PHPStan Level 8

---

## [1.0.0] - 2025-10-02

### Added

Initial public release of Swotto PHP SDK for SW4 API integration.

#### Core Features
- **User-Friendly API**: Simplified interface with automatic JSON detection
- **File Upload Methods**: Enterprise-grade file handling (`postFile`, `postFiles`, `putFile`, `patchFile`)
- **Smart Response Parsing**: Multiple response modes with content-type auto-detection
- **Multi-Format Support**: Handle JSON, CSV, PDF, and binary content
- **POP Convenience Methods**: 27+ helper methods for SW4 lookup data
- **Circuit Breaker Pattern**: Enterprise resilience for production environments
- **Dual Authentication**: DevApp tokens + Bearer tokens with multi-tenant isolation

#### Technical Details
- PHP >= 8.1 with strict types enforcement
- PSR Compliance: PSR-7, PSR-12, PSR-16, PSR-18
- Code Quality: PHPStan Level 8, PSR-12 code style
- Test Coverage: 78 tests, 211 assertions
- Security: Path traversal protection, memory guards, input validation

---

For complete documentation, see [README.md](README.md).
