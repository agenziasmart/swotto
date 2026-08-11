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
composer test                          # PHPUnit 12 suite
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
and HTTP client by injection (PSR-3, PSR-18). One deliberate exception:
`GuzzleHttpClient::$client` is **not** `readonly`, so tests can swap the transport in by
reflection. Nothing in production writes it after construction.

**`Http/`** holds `GuzzleHttpClient` (PSR-7/PSR-18) and `RetryHttpClient`, a decorator adding
retry with exponential backoff. `extractPerCallOptions()` turns context options into HTTP
headers.

**`Response/SwottoResponse`** wraps responses for multi-format content: content-type detection
(JSON, CSV, PDF, binary), memory-safe streaming and guarded file operations. The body is
always read in 8 KB chunks and capped at 50 MB **on the bytes actually received** — an
absent or lying `Content-Length` cannot get past it. `saveToFile()` rewinds a seekable
stream, refuses a consumed non-seekable one rather than writing an empty file, and rejects a
body shorter than its advertised `Content-Length`.

Be precise about what the path validation promises: it checks the **basename** for traversal
sequences, separators and control characters, and requires the target directory to exist and
be writable. It is not a sandbox — `../` inside the directory part is canonicalised by
`realpath()` and accepted. That is the right scope, because the path comes from the calling
application; the case that matters is a filename derived from a server-controlled
`Content-Disposition`, and that is exactly what the basename check covers.

**`Config/Configuration`** requires `url` — validated as a non-empty string with an `http`
or `https` scheme and a host; `http` stays valid because local development reaches the API
over `http://host.docker.internal:8081`. It also accepts `key` (DevApp token), `bearer_token`,
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
`retry_max_delay_ms`, `retry_multiplier`, `retry_jitter`). Two independent gates must both
open before a request is replayed, and confusing them is the easiest mistake to make here:

- **the method** — only `GET`, `HEAD`, `PUT`, `DELETE`, `OPTIONS` and `TRACE` are replayed by
  default, because a network error cannot tell you whether the request reached the server.
  `POST` and `PATCH` need `['retry_non_idempotent' => true]` in that call's options;
- **the exception** — `RetryHttpClient::isRetryable()` accepts exactly three things:
  `NetworkException` (and therefore `ConnectionException`, which extends it),
  `RateLimitException` — a 429 **is** retried, using its `Retry-After` as the delay instead
  of the backoff, **capped at `retry_max_delay_ms`** — and `ApiException` with a status of
  500 or above. Everything else, 401, 403, 404 and 422 included, fails on the first attempt.

`Retry-After` is read in both RFC 9110 forms, delay-seconds and HTTP-date; an unparsable or
past value yields 0 and the backoff takes over.

### Errors

The API answers with `{"success": false, "error": {"type", "message", "status", "details"},
"timestamp"}`. The message is read from `error.message`, falling back to a flat `message` and
then to a per-status default — a value that is not a non-empty string is discarded rather
than passed on. **HTTP 422 is the API's validation status**, not 400, and both map to
`ValidationException`, which extends `ApiException` so status-code-based handling keeps
working.

## Branches and releases

`master` holds stable releases and takes **no direct commits**; all work happens on `develop`
and reaches `master` through a pull request.

**Tags are created on `master` only** — never on `develop`, never without updating
`CHANGELOG.md` first. Use annotated tags and semantic versioning (`git tag -a v2.2.0`).

`.github/workflows/ci.yml` runs the quality gate — `composer validate`,
`check-platform-reqs`, `cs`, `phpstan`, `test` on PHP 8.3, the tests again on 8.4 and 8.5, and
`composer audit` over a fresh `--no-dev` install — on every push to `master` and `develop` and
on every pull request.

Pushing a `v*` tag triggers `.github/workflows/release.yml`, which calls that same gate as a
prerequisite job, extracts the version's section from `CHANGELOG.md` and publishes a GitHub
Release. It **fails** when that section is missing or empty, rather than shipping generic
notes. One detail still worth knowing: **the workflow does not notify Packagist.** The final
step only echoes that Packagist will update; the actual refresh comes from the GitHub →
Packagist webhook. If the package does not appear, look at the webhook, not at the Actions
log.

Downloadable `.zip` and `.tar.gz` archives are the ones GitHub attaches to any tag — the
workflow does not build them. To remove a tag: `git tag -d <tag>` locally,
`git push origin :refs/tags/<tag>` remotely.

## Testing

PHPUnit 12 with Mockery for test doubles; configuration in `phpunit.xml.dist`. Name a test
file after its subject with a `Test` suffix. There is no coverage threshold: cover observable
behaviour and edge cases, and add a regression test with every fix.

Four rules that keep the suite honest:

- Mock data only — **no real SW4 endpoints and no real credentials**, ever.
- No actual API calls in unit tests; mock HTTP with Guzzle's `MockHandler`.
- Use placeholder organization IDs, and test multitenant isolation with them.
- Never reference a real organization or customer in code, tests or examples.

Run containerized tests as a non-root user. The path-security tests create a deliberately
non-writable directory; root bypasses its mode bits and produces three false failures. A
read-only mount plus the host UID keeps the test meaningful, for example:

```bash
docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp \
  -v "$PWD:/app:ro" -w /app php:8.5-cli-alpine \
  php vendor/bin/phpunit --do-not-cache-result
```

On PHP 8.5, `ReflectionProperty::setAccessible()` and
`ReflectionMethod::setAccessible()` are deprecated because they have had no effect since PHP
8.1. Do not add them to new test helpers. As of 2026-08-12 the full suite still emits 26
test-only deprecations from older helpers; remove those before making deprecations a failing
CI gate. The production sources do not trigger them.

## Code quality

PSR-12 with the `@PHP83Migration` ruleset (`.php-cs-fixer.php`): **four-space indentation**,
LF endings, `declare(strict_types=1)` everywhere, short arrays, single quotes, ordered imports,
trailing commas in multiline constructs. Classes `PascalCase`, methods and properties
`camelCase`, interfaces suffixed `Interface`.

PHPStan runs at **level 8** (`phpstan.neon`) over `src/` and `tests/`. **`src/` passes with no
exemptions** — every entry in `ignoreErrors` is scoped to `tests/`, where fixture arrays and
mock chains are not worth the annotation noise. Keep it that way: a project-wide
`missingType` ignore silently hands consumers untyped arrays, and consumers run PHPStan too.
Fix findings with precise scalar, return and PHPDoc generic types, never by widening a
path.

Commits follow Conventional Commits (`feat:`, `fix:`, `docs:`, `feat!:` for breaking changes),
with a focused scope and an imperative summary. A pull request should explain the motivation,
list the verification commands, and state backward compatibility; update `README.md`,
`CHANGELOG.md` and `UPGRADE.md` whenever the public API or the migration path changes.

## Security

Never log tokens or credentials — the default logger is a `NullLogger`, and a real one is
injected. DevApp tokens come from the environment and are never hardcoded.

`sanitizeOptionsForLogging()` is a strict **allowlist**, not a redacted copy. It emits only
payload kind, multipart part count, numeric timeouts, boolean TLS state, `http_errors` and
`stream`. It never emits body/JSON/form/multipart values, headers, query, `auth`, proxy,
cookies, `cert`, `ssl_key`, cURL options, callbacks or unknown future Guzzle options. The
info line keeps only an uppercase allowlisted method (or `UNKNOWN`) and a bounded path. Both
the base client and retry decorator must call the shared `Http\LogSanitizer`; URL user-info,
query, fragment, malformed UTF-8 and `Cc`/`Cf`/`Zl`/`Zp` characters are stripped, and the URI
is capped at 512 bytes without splitting a code point. Do not create a second local sanitizer:
that drift was how retry warnings retained an unsafe boundary after the base client was fixed.

### Failure logging lesson

The observed symptom was that an HTTP/network failure copied the Guzzle exception message,
response body and URI credentials/query into the consumer's PSR-3 log; the retry decorator
independently copied the mapped exception message and raw URI. A second review found the same
boundary remained open outside the failure logger: plain request bodies, top-level Guzzle
`auth`/proxy/cookies/certificate options, unknown future options, transport exception
messages, raw `previous` chains and byte-truncated request IDs could still cross into a
consumer. The root cause was treating key-name redaction and truncation as data
classification. A secret under an innocent key is still a secret, and a bounded body is still
a body. Sentinel mutants for each carrier provided direct evidence.

The impact is highest on authentication/session requests, whose failures can carry
credentials, provider details or internal error envelopes. Failure logs therefore follow a
strict allowlist: constant messages plus bounded failure type, exception class, HTTP status
and `X-Request-ID` (valid UTF-8, controls removed, maximum 128 bytes). Network, connection,
unexpected transport, auth, authorization, lookup, rate-limit, server and default HTTP
exceptions also use constant messages and no raw `previous`. The response remains available
in `getErrorData()` because that is an application-facing API, not a logging API. Only `400`,
`402`, `409` and `422` keep an API message for UX/business handling. Public to the application
never implies safe to log.

Prevent recurrence with distinct sentinels for exception message/previous, raw response,
request/option carriers, exhausted retries, control/invalid UTF-8 request IDs and both decoded
and `requestRaw()` flows. Exercise the shared request metadata boundary through both the base
client and retry warnings with CRLF, control/format characters, invalid UTF-8, long multibyte
paths, URL credentials/query/fragment and a caller-controlled method. Roll out the SDK patch
first, then the bridge classification patch,
then regenerate and test each consumer lockfile. Verify the released tags and exact installed
versions, exercise synthetic `401`, public `422`, `5xx`, malformed response and network
failures without real credentials, and confirm both SDK and consumer logs contain only
type/status/request ID—not any sentinel. Roll back the consumer lock before either shared
package; reverting only the SDK while leaving the bridge/consumer assumes the safe boundary
still exists and reopens the leak.

The DevApp token is bound to the configured origin: a middleware at the bottom of the Guzzle
handler stack removes `x-devapp`, `X-Swotto-Client-Info` and `x-sid` whenever the host
changes, which is the only position from which redirected requests are visible. Guzzle itself
removes `Authorization` and `Cookie` across origins. Redirects are capped at 5 and, when the
base URL is `https`, refused over cleartext.

File operations validate the **filename** against traversal sequences and control characters
and require a writable directory — see the scope note in the architecture section above — and
memory is capped at 50 MB on the bytes actually read.
