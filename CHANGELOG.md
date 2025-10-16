# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
