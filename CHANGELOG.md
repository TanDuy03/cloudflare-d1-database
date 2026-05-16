# Changelog

All notable changes to `cloudflare-d1-database` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.10.0] - 2026-05-16

### Added
- `D1ConnectorInterface` contract for type-hinting connectors without coupling to Saloon (#2)
- `databaseExec()` and `databaseRaw()` methods on `CloudflareWorkerConnector` (#12)
- Input validation on all Worker endpoints — returns HTTP 400 for malformed requests (#14)
- `d1_driver` config validation — throws `InvalidArgumentException` for invalid values (#19)
- **HMAC request signing** — optional `CF_D1_HMAC=true` config adds per-request HMAC-SHA256 signatures for replay protection (#30)
- Worker supports `HMAC_REQUIRED` and `HMAC_WINDOW_SECONDS` env vars for enforcement
- `CHANGELOG.md` following Keep a Changelog format (#42)

### Changed
- **SECURITY:** Worker secret comparison now uses constant-time `crypto.subtle.timingSafeEqual` (#10)
- Worker error responses now return HTTP 500 for D1 execution failures instead of 200 (#13)
- `$accountId` and `$apiUrl` on `CloudflareConnector` changed from `public` to `protected` — use `getAccountId()` accessor (#9)
- `D1PdoStatement::execute($params)` now merges params with previously bound values (matching real PDO behavior) instead of replacing (#23)
- Replaced `Cache` facade with `$this->app['cache']` in ServiceProvider for safer resolution (#21)
- Config merge simplified — only operational defaults are merged; credentials missing from user config now trigger validation errors (#4)
- `CircuitBreaker` uses `now()->timestamp` instead of `time()` for testability
- Test Worker rewritten to use switch-based routing matching production Worker; removed `itty-router` dependency (#15)
- README latency claims replaced with relative descriptions instead of specific ms ranges (#36)
- Cleaned up `composer.json` keywords — removed unrelated `kv`, `r2`, `workers` terms (#20)

### Removed
- `printStarReminder()` method and star reminder behavior from `D1ServiceProvider` (#5)
- Legacy `tests/database/factories/UserFactory.php` (#7)
- Dead code in `tests/Pest.php` (`toBeOne` expectation, `something()` function) (#8)

### Fixed
- Laravel 10 test compatibility — all command tests use `$this->artisan()` instead of `BufferedOutput`
- Circuit breaker test flakiness on slow CI runners — replaced `sleep()` with `Carbon::setTestNow()`
- README `npm run start` → `npm run dev` to match Worker `package.json` scripts (#37)
- README `CONTRIBUTING.md` link replaced with inline contribution note (#38)

## [0.9.1] - 2025-05-16

### Fixed
- Byte formatting constants updated for clarity and consistency

## [0.9.0] - 2025-05-16

### Added
- `FormatsBytes` trait for byte formatting utility
- D1 Export request refactored to consolidate dump options
- Time Travel feature: `d1:time-travel` command for point-in-time recovery
- `D1ImportCommand` for importing SQL files into D1
- `D1InfoCommand` for displaying database metadata and connection status
- Read/Write splitting support with sticky reads
- `databaseInfo()` and `databaseImport()` methods on REST connector

## [0.8.0] - 2025-05-15

### Added
- `bulkInsert()` column validation and name escaping
- Circuit breaker enable flag in configuration
- SQL dump download retry mechanism

### Changed
- Streamlined D1ServiceProvider Worker connector handling

## [0.7.0] - 2025-04-28

### Added
- `bulkInsert()` method on `D1Connection` with D1 100-statement batch chunking
- Circuit breaker pattern for fault tolerance
- Query logger support on connectors
- D1 Sessions API support for Worker driver (read replication)

## [0.6.0] - 2025-04-20

### Added
- `d1:schema-dump` command for exporting D1 database schema
- `d1:health` command for connection diagnostics
- Batch query support via `D1Connection::batch()`
- Documentation for batch queries and circuit breaker

## [0.5.0] - 2025-04-10

### Added
- Worker driver (`CloudflareWorkerConnector`) for low-latency queries via Cloudflare Workers
- Worker template project in `Worker/` directory
- Retry with exponential backoff and jitter (`sendWithRetry`)

## [0.4.0] - 2025-03-28

### Changed
- Minimum PHP version requirement: 8.2
- Improved exception handling in tests

## [0.3.0] - 2025-03-15

### Added
- `D1SchemaGrammar` with cross-version Laravel compatibility (10/11/12+)
- `MapsSqlState` trait for SQLSTATE error code mapping

## [0.2.0] - 2025-03-01

### Fixed
- `compileTableExists` compatibility with `SQLiteGrammar`

## [0.1.0] - 2025-02-15

### Added
- Initial release
- REST driver (`CloudflareD1Connector`) using Cloudflare D1 REST API via Saloon
- PDO extension pattern (`D1Pdo`, `D1PdoStatement`) for full Laravel compatibility
- Eloquent and Query Builder support
- Laravel 10/11/12 support, PHP 8.2+
- Transaction no-ops (D1 is stateless)
- Basic test suite with `MockCloudflareD1Connector`

[Unreleased]: https://github.com/TanDuy03/cloudflare-d1-database/compare/v0.9.1...HEAD
[0.9.1]: https://github.com/TanDuy03/cloudflare-d1-database/compare/v0.9.0...v0.9.1
[0.9.0]: https://github.com/TanDuy03/cloudflare-d1-database/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/TanDuy03/cloudflare-d1-database/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/TanDuy03/cloudflare-d1-database/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/TanDuy03/cloudflare-d1-database/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/TanDuy03/cloudflare-d1-database/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/TanDuy03/cloudflare-d1-database/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/TanDuy03/cloudflare-d1-database/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/TanDuy03/cloudflare-d1-database/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/TanDuy03/cloudflare-d1-database/releases/tag/v0.1.0
