# Repository Guidelines

## Project Structure & Module Organization

This PHP 8.3 SDK keeps production code in `src/` under the `Swotto\` PSR-4 namespace. Follow the existing layout: configuration in `src/Config/`, interfaces in `src/Contract/`, HTTP transport in `src/Http/`, responses in `src/Response/`, retry behavior in `src/Retry/`, and exceptions in `src/Exception/`. PHPUnit tests mirror these concerns in `tests/`; response tests are in `tests/Response/`. User guidance and release notes belong in `README.md`, `UPGRADE.md`, and `CHANGELOG.md`.

## Build, Test, and Development Commands

- `composer install` installs runtime and development dependencies.
- `composer test` runs the complete PHPUnit 11 suite configured by `phpunit.xml.dist`.
- `composer test -- --filter SwottoClientTest` runs a focused class or test name.
- `composer cs` checks PHP files with PHP CS Fixer without changing them.
- `composer cs-fix` applies the configured formatting rules.
- `composer phpstan` performs level 8 static analysis on `src/` and `tests/`.

There is no application build or local server. Before submitting, run `composer cs && composer phpstan && composer test`.

## Coding Style & Naming Conventions

Follow PSR-12 and the PHP 8.3 migration rules in `.php-cs-fixer.dist.php`. Use two-space indentation, LF line endings, strict types, short arrays, single quotes, ordered imports, and trailing commas in multiline constructs. Classes use `PascalCase`, methods and properties use `camelCase`, and interfaces end in `Interface`. Add precise scalar, return, and PHPDoc generic types so PHPStan level 8 remains clean.

## Testing Guidelines

Tests use PHPUnit, with Mockery available for test doubles. Name files and classes after the subject with a `Test` suffix, such as `RetryHttpClientTest.php`, and use descriptive `test...` methods. Add regression coverage for fixes and cover success, validation, security, and failure paths where relevant. No numeric coverage threshold is configured; prioritize observable behavior and edge cases.

## Commit & Pull Request Guidelines

History primarily follows Conventional Commits: `feat:`, `feat(scope):`, `docs:`, and `feat!:` for breaking changes. Keep commits focused and use an imperative summary. Pull requests should explain motivation and behavior changes, link related issues, list verification commands, and note backward compatibility. Update `README.md`, `CHANGELOG.md`, and `UPGRADE.md` when public APIs or migration requirements change; screenshots are only useful for rendered documentation changes.

## Security & Configuration

Never commit API keys, bearer tokens, certificates, logs, or environment files. Use placeholders in examples and environment variables for real SW4 credentials. Keep tests isolated from live services and real customer data.
