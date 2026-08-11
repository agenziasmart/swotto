# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security

- **HTTP and network failure logs no longer copy upstream-controlled content.** Exception
  messages, response bodies, URL user-info and query strings could contain credentials,
  personal data or internal error details. Failure logs now use constant messages and an
  allowlisted, bounded context: failure type, exception class, HTTP status and at most 128
  bytes of valid UTF-8 from `X-Request-ID`, with control characters removed. Retry logs follow
  the same rule. Debug request-option logs now use an allowlist of payload kind, multipart part
  count and scalar timeout/TLS flags: body, JSON, form, multipart values, headers, `auth`,
  proxy, cookies, certificates, query, cURL options, callbacks and unknown future Guzzle
  options are never copied into a log.
- **Transport and non-business HTTP exception messages are now constant.** Network,
  connection, unexpected transport, `401`, `403`, `404`, `429`, `5xx` and default HTTP
  failures no longer expose the Guzzle message, URL or upstream error text through
  `getMessage()`/`getPrevious()`. The exception class, status and complete response
  `getErrorData()` contract remain available to callers. API messages remain public only for
  the business/validation statuses `400`, `402`, `409` and `422`; “public to the caller” does
  not mean “safe to log”.

## [2.3.0] - 2026-08-08

Aligns the public contract with the API the SDK actually talks to, and stops replaying
requests that cannot be replayed safely.

> Includes everything listed under 2.2.1 below. That entry was written on 2026-07-29 and
> never got a tag of its own, so its fixes reach consumers here — there is no `v2.2.1` to
> install and there will not be one.

### Changed

- **HTTP 422 now raises `ValidationException`.** The SW4 API answers 422 for validation
  errors, not 400, so every validation failure used to surface as a generic `ApiException` —
  contradicting both the README and the API. `ValidationException` extends `ApiException`,
  so code that catches `ApiException` keeps working unchanged. 400 still maps there too.
- **Exception messages are read from the API's error envelope.** The SDK looked for a
  top-level `message`, while the API nests it under `error.message`. Every exception
  therefore carried a hardcoded English fallback or the raw Guzzle message. A `message` that
  is not a non-empty string now falls back to the default instead of raising a `TypeError`.
- **Only idempotent methods are retried automatically** — `GET`, `HEAD`, `PUT`, `DELETE`,
  `OPTIONS`, `TRACE`. A network error is ambiguous, so replaying a `POST` could duplicate an
  order or an upload. Pass `['retry_non_idempotent' => true]` per request to accept that
  risk. Retry is opt-in and no consumer had it enabled, so nothing in production changes.
- **`post()`, `put()` and `patch()` honour their `mixed $data` signature.** A string, stream,
  resource or scalar becomes a raw request body; previously anything that was not a non-empty
  array was discarded without a word, so `post($uri, 'raw-payload')` sent nothing. An array
  still becomes a JSON body, an empty array still sends no body, and an explicit body option
  still wins. An unsupported type now raises `InvalidArgumentException`.
- **Request bodies are logged at debug level, not info.** In an ERP that body is full of
  commercial and personal data; the info-level line keeps method and path, with the query
  string stripped since tokens travel there often enough to matter.

- **Minimum dependency versions raised to releases with no open advisories**:
  `guzzlehttp/guzzle` to `^7.15.1` and `guzzlehttp/psr7`, previously only transitive, now
  required explicitly at `^2.12.3`. The old `^7.5` floor allowed a resolution with known
  host-confusion and CRLF-injection issues in the component that carries both tokens.

### Documentation

- **README examples use endpoints that exist.** `auth/login`, `customers`,
  `account/profile`, `documents` and the rest were invented; SW4 names resources in the
  singular and the routes are `auth`, `customer`, `me`, `product/{uuid}/documents`. Login
  takes `username`, not `email`, and returns `data.access_token`, not `data.token`. Upload
  examples now name the field each endpoint expects — `logo`, `document`, `file` — which is
  not guessed from the filename. Every endpoint in the README was verified against a running
  SW4 API.

### Fixed

- **Application headers no longer follow a cross-origin redirect.** Guzzle strips
  `Authorization` and `Cookie` on its own but knows nothing about `x-devapp`,
  `X-Swotto-Client-Info` and `x-sid`, which used to travel to whatever host a redirect
  pointed at. Redirects are also capped at 5 and, for an `https` base URL, refused over
  cleartext.
- **Log redaction is recursive and case-insensitive.** Only the first level of `json` and
  `form_params` was checked and `query` was not checked at all, so a secret one level deeper
  — or in a query string — reached the logger in the clear. Multipart parts are matched by
  field name, since the value lives under `contents` while the name lives under `name`.
- **CSV parsing no longer tears quoted multi-line fields apart.** Records were split on
  `\n` before parsing, so a quoted field containing a line break became two rows.
- **The CSV delimiter is detected instead of assumed.** The comma was hardcoded, but the SW4
  API exports with a semicolon — the convention Excel expects in most of Europe — so
  `asArray()` on a real export returned one unusable column per record, keyed by the entire
  header line, without anything looking like a failure. Comma, semicolon, tab and pipe are
  now detected from the header with a quote-aware count, and a UTF-8 BOM no longer confuses
  the first column name. Verified against a live export: 262 records went from 1 column to
  10. A test previously asserted the broken behaviour as if it were a design decision; it
  now asserts the correct one.
- **`isBinary()` recognises the formats an ERP exports**: `application/octet-stream`,
  archives, and the Office and OpenDocument families. A spreadsheet download was previously
  reported as non-binary.
- **Configuration validates `url`.** A non-string, empty, relative or non-HTTP URL raises
  `ConfigurationException` instead of a `TypeError` deep inside `rtrim()`. `http` remains
  valid — the documented Docker setup reaches the API over `http://host.docker.internal:8081`.

---

## [2.2.1] - 2026-07-29

> Never released under its own tag. These fixes ship as part of 2.3.0 — install that.

Correctness fixes for response handling and retry pacing. No public API changes: every
behaviour corrected here was either wrong or unspecified.

### Fixed

- **`saveToFile()` no longer writes an empty file and reports success.** The stream is
  rewound when seekable, so saving after `asString()` or `asArray()` writes the full
  content. A non-seekable stream that was already consumed now raises `StreamingException`
  instead of silently producing a 0-byte file.
- **`saveToFile()` handles partial writes.** `fwrite()` may accept fewer bytes than
  requested; the remainder of the chunk was previously lost without any error.
- **`saveToFile()` verifies the downloaded size** against `Content-Length` when the header
  is present, raising `StreamingException` on a truncated body. A failed save no longer
  leaves a partial file on disk.
- **The 50 MB memory ceiling now applies to the bytes actually received.** It previously
  depended entirely on `Content-Length`, so a chunked response — or any response where a
  proxy dropped the header — bypassed it completely.
- **`Retry-After` is capped at `retry_max_delay_ms`.** A legitimate `Retry-After: 86400`
  previously parked the worker for 24 hours on a single response.
- **`Retry-After` accepts the HTTP-date form** allowed by RFC 9110, which was cast to 0 and
  discarded. An unparsable or past value still falls back to the exponential backoff.
- **Scalar JSON raises `StreamingException` instead of a `TypeError`.** A valid but
  non-array payload (`42`, `"text"`, `true`) used to escape as a PHP error. JSON `null`
  still yields an empty array.
- **`getContentLength()` returns null for a non-numeric or negative header** rather than
  coercing it to 0, which downstream checks read as a real length.

### Added

- `ext-mbstring` declared in `composer.json`. `isBinaryString()` calls `mb_check_encoding()`,
  which previously worked only because a dev-only polyfill happened to be installed — a
  `--no-dev` install on a minimal PHP image would have hit a fatal error.

### Build

- `composer cs` now passes `--allow-risky=yes`, matching `cs-fix`. The style check exited 16
  on every run because the ruleset uses `declare_strict_types`, so the verification half of
  the quality gate had never been runnable.

---

## [2.2.0] - 2026-02-06

### Breaking Changes

- **`client_user_agent` now maps to `X-Client-User-Agent` header** (was `User-Agent`). This separates end-user UA forwarding from SDK identification. If your server reads `User-Agent` for end-user data, update it to read `X-Client-User-Agent` instead.

### Added

- **SDK User-Agent identification**: All requests now include `User-Agent: Swotto/v1 PHP-SDK/2.2.0 PHP/8.3.x`, following Stripe/Twilio/SendGrid convention
- **Telemetry header**: `X-Swotto-Client-Info` JSON header with `sdk_version`, `lang`, `lang_version`, `os` (and optionally `app_name`, `app_version`)
- **App identification**: New config options `app_name` and `app_version` to identify third-party apps in User-Agent and telemetry (like Stripe's `setAppInfo()` pattern)

### Changed

- `client_user_agent` per-call option now sets `X-Client-User-Agent` header instead of `User-Agent`
- `User-Agent` header is now reserved for SDK identification and is never overwritten by per-call options
- Removed legacy `x-author` header (replaced by standard `User-Agent` and `X-Swotto-Client-Info`)

### Migration

See [UPGRADE.md](UPGRADE.md) for detailed migration guide.

---

## [2.1.0] - 2026-02-06

### Breaking Changes

- **`Client` renamed to `SwottoClient`**: `Swotto\Client` → `Swotto\SwottoClient`
- **`ClientInterface` renamed to `SwottoClientInterface`**: `Swotto\Contract\ClientInterface` → `Swotto\Contract\SwottoClientInterface`

### Changed

- Client classes now follow Stripe PHP SDK naming convention (brand prefix on public API classes)
- All internal references, tests, and documentation updated

### Migration

Search and replace in your codebase:
- `use Swotto\Client` → `use Swotto\SwottoClient`
- `use Swotto\Contract\ClientInterface` → `use Swotto\Contract\SwottoClientInterface`
- `new Client(` → `new SwottoClient(`

See [UPGRADE.md](UPGRADE.md) for detailed migration guide.

---

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
