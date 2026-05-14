# AGENTS.md - Cloudflare D1 Laravel Driver

## Project Shape
- Laravel package `ntanduy/cloudflare-d1-database`, namespace `Ntanduy\CFD1\`, autoload root `src/`.
- Two runtime drivers: REST API via `CloudflareD1Connector` and Worker proxy via `CloudflareWorkerConnector`.
- `Worker/` is a standalone Cloudflare Worker npm project with its own `AGENTS.md`; follow that file and fetch current Cloudflare docs before changing Worker APIs, bindings, limits, or deploy behavior.
- `tests/worker/` is a separate mock Worker project for integration-style local testing, not the production Worker template.

## Commands
- PHP verification: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `vendor/bin/pest`.
- PHP single/focused tests: `vendor/bin/pest tests/Unit/BulkInsertTest.php` or `vendor/bin/pest --filter "test name"`.
- Composer shortcut: `composer test` runs `vendor/bin/pest` only.
- CI has separate style, static-analysis, and test jobs; run all three PHP checks before handing off code changes.
- Worker setup/test: use `workdir="Worker"` with `npm ci`, then `npm test`; `npm run dev` starts Wrangler, `npm run deploy` deploys.
- After changing `Worker/wrangler.jsonc` bindings, run `workdir="Worker"` `npx wrangler types`.
- Mock Worker commands live under `tests/worker/`; its `npm test` script is `vitest run`.

## CI Matrix
- PHP tests run on PHP 8.2/8.3/8.4 against Laravel 10/11/12/13 with both `prefer-lowest` and `prefer-stable`.
- Laravel 13 is excluded on PHP 8.2.
- CI installs matrix versions with `composer require laravel/framework:<version> orchestra/testbench:<version> --dev --no-update` before `composer update`.

## Test Architecture
- `tests/TestCase.php` uses Orchestra Testbench, sets `database.default=d1`, loads Laravel migrations into the `d1` connection, and replaces the `d1` driver with `MockCloudflareD1Connector`.
- PHP tests should not make real Cloudflare calls; the mock connector intercepts Saloon requests and executes SQL against static in-memory SQLite.
- `MockCloudflareD1Connector::$sqlite` intentionally persists across connection re-resolves within a test; `TestCase::setUp()` resets it before each test.
- The mock simulates SQLite transactions, but production D1 transaction methods are no-ops.

## Driver Gotchas
- `D1ServiceProvider` merges `config/d1-database.php` into `database.connections.d1`; user connection config overrides package defaults.
- `D1Connection` extends Laravel `SQLiteConnection`; `D1Pdo` extends `PDO` and opens an unused `sqlite::memory:` connection to satisfy PDO inheritance.
- Real transactions do not exist: `beginTransaction()`, `commit()`, `rollBack()`, Laravel savepoints, and rollback SQL are no-ops. Use `D1Connection::batch()` for atomic multi-statement D1 execution.
- Retry safety is intentionally narrow: `D1Pdo::shouldRetryFor()` only retries `SELECT` and `WITH`; never broaden retries to mutations without an idempotency design.
- D1 Sessions and automatic read/write splitting are Worker-only; REST ignores `session`, `read`, and `write` behavior because the REST API has no Sessions API.
- `d1:schema-dump` always uses REST export. Worker-only users still need `CF_D1_API_TOKEN`, `CF_D1_ACCOUNT_ID`, and `CF_D1_DATABASE_ID` for dumps and full `d1:info` metadata.
- D1 batch limit is 100 statements. `bulkInsert()` chunks larger inputs, so data sets over 100 rows are multiple D1 batch calls, not one global atomic batch.
- Circuit breaker state uses Laravel Cache; do not use the `database` cache driver for D1 circuit state because it creates a dependency loop when D1 is down.
- `D1SchemaGrammar` extends SQLite grammar and uses reflection for Laravel 12+ schema method signatures; preserve cross-version compatibility.

## Style
- PHP files require `declare(strict_types=1)`; Pint enforces this with the Laravel preset plus repo rules in `pint.json`.
- Root `.editorconfig`: PHP and most files use 4 spaces, YAML/JSON/JS use 2 spaces, LF endings.
- `Worker/.editorconfig` overrides Worker files to tabs; do not reformat Worker TypeScript to root spacing.
- Tests are Pest-style; prefer `expect()` assertions over PHPUnit assertions.
