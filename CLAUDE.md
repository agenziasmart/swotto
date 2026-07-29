# CLAUDE.md — Swotto PHP SDK

Official PHP client library for integrating third-party applications with the SW4 API.
PHP >= 8.3 · MIT · `composer require agenziasmart/swotto`.

> **This is a public repository.** Before any commit: no PII, no real credentials or tokens,
> no internal URLs or IP addresses, no confidential SW4 information. Use placeholders in
> examples (`YOUR_API_KEY`, `example.com`).

User-facing documentation lives in `README.md`; released versions and migration notes in
`CHANGELOG.md` and `UPGRADE.md` — **this file does not restate them.**

## Commands

```bash
composer test                          # PHPUnit 11 suite
composer test -- --filter TestName     # focused run
composer cs                            # PSR-12 check (no changes)
composer cs-fix                        # apply formatting
composer phpstan                       # static analysis, level 8, on src/ and tests/

composer cs-fix && composer phpstan && composer test   # before every commit
```

There is no build step and no local server.

## Architecture

Production code lives in `src/` under the `Swotto\` PSR-4 namespace: `Config/`, `Contract/`,
`Http/`, `Response/`, `Retry/`, `Exception/`. Tests in `tests/` mirror that layout.

**`SwottoClient`** is the entry point and implements `SwottoClientInterface`. It is **fully
immutable** — every property `readonly`, no setters, no mutable state — and takes its logger
and HTTP client by injection (PSR-3, PSR-18).

**`Http/`** holds `GuzzleHttpClient` (PSR-7/PSR-18) and `RetryHttpClient`, a decorator adding
retry with exponential backoff. `extractPerCallOptions()` turns context options into HTTP
headers.

**`Response/SwottoResponse`** wraps responses for multi-format content: content-type detection
(JSON, CSV, PDF, binary), memory-safe streaming (10 MB threshold, 50 MB hard limit), and
guarded file operations (path traversal, permissions).

**`Config/Configuration`** requires `url`; accepts `key` (DevApp token), `bearer_token`,
context keys (`language`, `session_id`, `client_ip`, `client_user_agent`), retry settings and
`timeout` / `verify_ssl`. Note that `getHeaders()` returns **transport headers only**
(`Accept`, `x-devapp`): context headers travel a different path, described below.

**`Exception/`** is a hierarchy rooted in `SwottoExceptionInterface` → `SwottoException`, with
HTTP (`ApiException`, `AuthenticationException`, `ForbiddenException`, `NotFoundException`,
`ValidationException`), network (`NetworkException`, `ConnectionException`,
`RateLimitException`), security (`SecurityException`, `FileOperationException`,
`MemoryException`) and `StreamingException`.

### Default options + merge

Context options set on the client become defaults for every request, and per-call options
override them with `array_merge` semantics. This is the Stripe SDK pattern, and it is the part
of the design that is easiest to get wrong:

```php
$client = new SwottoClient([
    'url' => 'https://api.example.com',
    'key' => 'YOUR_DEVAPP_TOKEN',
    'bearer_token' => $defaultToken,
    'language' => 'it',
]);

$data = $client->get('customers', ['language' => 'en']);   // overrides just this call
```

The chain is `config` → `extractDefaultOptions()` → `mergeOptions(defaults, perCall)` →
`extractPerCallOptions()` → HTTP headers. Context keys: `bearer_token`, `language`,
`session_id`, `client_ip`, `client_user_agent`.

### Dual authentication

Two tokens answer two different questions, and they travel by different routes:

- **DevApp token** (`key`) identifies the *application*. It goes into the base Guzzle headers
  via `Configuration::getHeaders()` as `x-devapp`.
- **Bearer token** authenticates the *end user*. It goes through default or per-call options
  and becomes the `Authorization` header.

Data is isolated per organization (`_oid`), and the DevApp token's organization **always
overrides** the user's.

### Responses, uploads, retry

```php
$data = $client->get('customers');                    // decoded array

$response = $client->getResponse('reports/monthly');  // wrapper
$response->asArray(); $response->asString(); $response->saveToFile(); $response->isJson();

$client->downloadToFile('exports/data.csv', '/path/to/file.csv');

$client->postFile($uri, $fileResource, 'file', $metadata);
$client->postFiles($uri, $files, $metadata);
$client->putFile($uri, $fileResource);
$client->patchFile($uri, $fileResource);
```

Retry is opt-in (`retry_enabled`) and tunable (`retry_max_attempts`, `retry_initial_delay_ms`,
`retry_max_delay_ms`, `retry_multiplier`, `retry_jitter`). `RetryHttpClient::isRetryable()`
retries exactly three things: `NetworkException` (and therefore `ConnectionException`, which
extends it), `RateLimitException` — a 429 **is** retried, using its `Retry-After` as the delay
instead of the backoff — and `ApiException` with a status of 500 or above. Everything else,
including 401, 403, 404 and 422, fails on the first attempt.

## Branches and releases

`master` holds stable releases and takes **no direct commits**; all work happens on `develop`
and reaches `master` through a pull request.

**Tags are created on `master` only** — never on `develop`, never without updating
`CHANGELOG.md` first. Use annotated tags and semantic versioning (`git tag -a v2.2.0`).

Pushing a `v*` tag triggers `.github/workflows/release.yml`, which extracts that version's
section from `CHANGELOG.md` and publishes a GitHub Release. Two details worth knowing, because
they are easy to assume wrong:

- **A missing CHANGELOG section does not fail the release.** The workflow falls back to a
  generic body pointing at the changelog, so a release can succeed and still ship empty notes —
  check the published release, not just the green check.
- **The workflow does not notify Packagist.** The final step only echoes that Packagist will
  update; the actual refresh comes from the GitHub → Packagist webhook. If the package does not
  appear, look at the webhook, not at the Actions log.

Downloadable `.zip` and `.tar.gz` archives are the ones GitHub attaches to any tag — the
workflow does not build them. To remove a tag: `git tag -d <tag>` locally,
`git push origin :refs/tags/<tag>` remotely.

## Testing

PHPUnit 11 with Mockery for test doubles; configuration in `phpunit.xml.dist`. Name a test
file after its subject with a `Test` suffix. There is no coverage threshold: cover observable
behaviour and edge cases, and add a regression test with every fix.

Four rules that keep the suite honest:

- Mock data only — **no real SW4 endpoints and no real credentials**, ever.
- No actual API calls in unit tests; mock HTTP with Guzzle's `MockHandler`.
- Use placeholder organization IDs, and test multitenant isolation with them.
- Never reference a real organization or customer in code, tests or examples.

## Code quality

PSR-12 with the `@PHP83Migration` ruleset (`.php-cs-fixer.php`): **four-space indentation**,
LF endings, `declare(strict_types=1)` everywhere, short arrays, single quotes, ordered imports,
trailing commas in multiline constructs. Classes `PascalCase`, methods and properties
`camelCase`, interfaces suffixed `Interface`.

PHPStan runs at **level 8** (`phpstan.neon`) over `src/` and `tests/`. Keep it clean by adding
precise scalar, return and PHPDoc generic types rather than by widening the ignore list.

Commits follow Conventional Commits (`feat:`, `fix:`, `docs:`, `feat!:` for breaking changes),
with a focused scope and an imperative summary. A pull request should explain the motivation,
list the verification commands, and state backward compatibility; update `README.md`,
`CHANGELOG.md` and `UPGRADE.md` whenever the public API or the migration path changes.

## Security

Never log tokens or credentials — the default logger is a `NullLogger`, and a real one is
injected. DevApp tokens come from the environment and are never hardcoded. File operations
guard against path traversal and invalid characters, enforce directory permissions, and cap
memory (streaming above 10 MB, 50 MB hard limit).
