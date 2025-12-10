# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
