# AGENTS.md — Cloudflare D1 Database Driver for Laravel

## What this is

Laravel package (`ntanduy/cloudflare-d1-database`) providing a Cloudflare D1 database driver.
Namespace: `Ntanduy\CFD1\`. Autoload root: `src/`.
Two connection drivers: **REST** (default, via Saloon HTTP client) and **Worker** (proxy through a Cloudflare Worker).

## Commands

| Command | Purpose |
|---|---|
| `vendor/bin/pest` | Run PHP tests |
| `vendor/bin/pint --test` | Check code style (Laravel Pint) — fails if not formatted |
| `vendor/bin/phpstan analyse` | Static analysis (level 5) |
| `composer test` | Alias for `vendor/bin/pest` |

CI runs **style → static analysis → tests** in separate jobs. Run all three locally before pushing.

### Worker (TypeScript)

| Command | Purpose |
|---|---|
| `cd Worker && npm ci && npm test` | Run Worker unit tests (Vitest) |
| `cd Worker && npm run dev` | Local dev server via `wrangler dev` |
| `cd Worker && npm run deploy` | Deploy Worker to Cloudflare |
| `cd Worker && npx wrangler types` | Regenerate types after changing `wrangler.jsonc` bindings |

A separate test-worker project exists at `tests/worker/` (also Vitest + wrangler). It's a mock server for integration testing, not the production Worker.

## Testing architecture

- **Framework**: Pest v2/v3/v4 over PHPUnit, with Orchestra Testbench for Laravel service container.
- **Mocking**: Tests use `MockCloudflareD1Connector` which runs an **in-memory SQLite** database and intercepts Saloon HTTP calls via `MockClient`. No real Cloudflare API calls are made in tests.
- **State**: `MockCloudflareD1Connector::$sqlite` is static — persists across connection re-resolves within a test. Call `MockCloudflareD1Connector::reset()` between tests (done automatically in `TestCase::setUp`).
- **Test base class**: `tests/TestCase.php` extends Orchestra TestCase, loads Laravel migrations into the mock SQLite, and overrides the `d1` database connection with the mock connector.
- **No real transactions**: D1 doesn't support `BEGIN`/`COMMIT`/`ROLLBACK`. The mock simulates them against SQLite for test fidelity, but production executes queries immediately.

## PHPStan

Level 5. Known ignores in `phpstan.neon`:
- Mockery dynamic method calls (`with`, `once`, `andReturn`)
- Eloquent magic methods and properties on test models
- PDO type mismatches from D1-specific behavior

## CI matrix

Tests run across PHP 8.2/8.3/8.4 × Laravel 10/11/12/13 × prefer-lowest/prefer-stable.
Exception: PHP 8.2 is excluded from Laravel 13.

## Key architecture notes

- `D1ServiceProvider` merges `config/d1-database.php` defaults into `database.connections.d1` at boot.
- `D1Connection` extends `SQLiteConnection` and wraps either `CloudflareD1Connector` (REST) or `CloudflareWorkerConnector` (Worker). Both use Saloon HTTP client.
- `D1Pdo` / `D1PdoStatement` implement PDO interfaces for Laravel's database layer. `D1Pdo` extends `PDO` which opens an unused `sqlite::memory:` connection — this is a documented trade-off of the inheritance approach.
- **Transactions are no-ops**: `beginTransaction()`, `commit()`, `rollBack()` all do nothing. `DB::transaction(Closure)` runs queries immediately — no rollback on failure, no atomicity. `D1Connection` also overrides `executeBeginTransactionStatement()`, `createSavepoint()`, and `performRollBack()` as no-ops to prevent any SQL being sent.
- **Retry safety**: `D1Pdo::shouldRetryFor()` only retries `SELECT` and `WITH` queries (idempotent reads). Mutations (`INSERT`, `UPDATE`, `DELETE`) are never retried to avoid duplicate data.
- `D1SchemaGrammar` extends `SQLiteGrammar` and uses reflection to detect whether parent methods accept a `$schema` parameter (Laravel 12+). Replaces `sqlite_master` with `sqlite_schema` for D1 compatibility.
- Circuit breaker state is stored via Laravel Cache — **never use `database` cache driver** (creates dependency loop when D1 is down). Use `file` or `redis`.
- `Worker/` is a standalone npm project with its own `AGENTS.md` (Cloudflare Workers guidance). Keep it independent from the PHP package.

## Environment variables (driver modes)

| Variable | Default | Purpose |
|---|---|---|
| `CF_D1_DRIVER` | `rest` | `rest` or `worker` |
| `CF_D1_DATABASE_ID` | — | D1 database UUID (REST) |
| `CF_D1_API_TOKEN` | — | Cloudflare API token (REST) |
| `CF_D1_ACCOUNT_ID` | — | Cloudflare account ID (REST) |
| `CF_D1_WORKER_URL` | — | Worker endpoint URL (Worker) |
| `CF_D1_WORKER_SECRET` | — | Worker auth secret (Worker) |

## Conventions

- `declare(strict_types=1)` at top of every PHP file.
- PHP files: 4-space indent, LF line endings (`.editorconfig`).
- YAML/JSON/JS: 2-space indent.
- Style enforced by Laravel Pint (not PHP-CS-Fixer).
- Tests use Pest `expect()` API, not PHPUnit `$this->assert*`.
