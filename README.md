# Cloudflare D1 Database Driver for Laravel

[![codecov](https://codecov.io/gh/TanDuy03/cloudflare-d1-database/graph/badge.svg?token=9MSJ527ZMX)](https://codecov.io/gh/TanDuy03/cloudflare-d1-database)
[![Tests](https://github.com/TanDuy03/cloudflare-d1-database/actions/workflows/tests.yml/badge.svg)](https://github.com/TanDuy03/cloudflare-d1-database/actions/workflows/tests.yml)
![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?logo=php)
![Laravel](https://img.shields.io/badge/Laravel-10--13.x-FF2D20?logo=laravel)
[![Latest Stable Version](https://poser.pugx.org/ntanduy/cloudflare-d1-database/v/stable)](https://packagist.org/packages/ntanduy/cloudflare-d1-database)
[![Total Downloads](https://poser.pugx.org/ntanduy/cloudflare-d1-database/downloads)](https://packagist.org/packages/ntanduy/cloudflare-d1-database)
[![Monthly Downloads](https://poser.pugx.org/ntanduy/cloudflare-d1-database/d/monthly)](https://packagist.org/packages/ntanduy/cloudflare-d1-database)
[![License](https://poser.pugx.org/ntanduy/cloudflare-d1-database/license)](https://packagist.org/packages/ntanduy/cloudflare-d1-database)

Use [Cloudflare D1](https://developers.cloudflare.com/d1) as a native Laravel database driver — full Eloquent ORM, Query Builder, and Migration support.

## 🎯 Requirements

- **PHP**: >= 8.2
- **Laravel**: 10.x, 11.x, 12.x, or 13.x

## ✨ Features

- **Full Laravel Integration** — Eloquent ORM, Query Builder, Migrations, Seeding
- **Two Connection Drivers** — REST API (zero infrastructure) or Worker (low latency)
- **Batch Queries** — Execute multiple statements in a single HTTP round-trip
- **Bulk Insert** — Insert hundreds of rows efficiently via D1 batch execution
- **Sessions / Read Replication** — Leverage D1 global read replicas for lower-latency reads (Worker driver)
- **Auto Read/Write Splitting** — Automatic routing of SELECTs to replicas and writes to primary (Worker driver)
- **Import** — Import SQL files into D1 via `php artisan d1:import`
- **Schema Dump** — Export your D1 database via `php artisan d1:schema-dump`
- **Time Travel** — Point-in-time recovery via `php artisan d1:time-travel`
- **Database Info** — Inspect your D1 database with `php artisan d1:info`
- **Circuit Breaker** — Fail fast on sustained outages instead of blocking on retries
- **Automatic Retries** — Exponential backoff with jitter for 5xx/429 errors
- **Query Logging** — Optional callback for monitoring and debugging
- **Health Check** — Built-in `php artisan d1:health` to verify connection and measure latency

## 🚀 Installation

```bash
composer require ntanduy/cloudflare-d1-database
```

## 👏 Usage

### Step 1: Publish Configuration

```bash
php artisan vendor:publish --tag="d1-config"
```

This creates `config/d1-database.php` with all available options.

### Step 2: Choose a Driver

This package supports two drivers to connect Laravel with Cloudflare D1:

| Driver | How it works | Latency | Setup |
|--------|-------------|---------|-------|
| **REST** (default) | Calls [Cloudflare D1 REST API](https://developers.cloudflare.com/api/resources/d1/subresources/database/methods/query/) directly | Higher (extra HTTP hop) | API Token only |
| **Worker** | Routes queries through your own [Cloudflare Worker](https://developers.cloudflare.com/workers/) | Lower (co-located with D1) | Requires deploying a Worker |

---

### Driver 1: REST API (Default)

The simplest setup — no extra infrastructure needed. Queries are sent to Cloudflare's REST API.

**Add to your `.env`:**

```env
CF_D1_API_TOKEN=your_api_token
CF_D1_ACCOUNT_ID=your_account_id
CF_D1_DATABASE_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
```

**How to get these values:**

1. **API Token** — Go to [Cloudflare Dashboard → API Tokens](https://dash.cloudflare.com/profile/api-tokens) → Create Token → use the "Edit Cloudflare D1" template
2. **Account ID** — Found on your Cloudflare Dashboard overview page (right sidebar)
3. **Database ID** — Go to [Workers & Pages → D1](https://dash.cloudflare.com/?to=/:account/workers/d1) → click your database → copy the Database ID

That's it! Your Laravel app can now use D1.

---

### Driver 2: Worker (Low Latency)

For production apps that need lower latency, deploy a Cloudflare Worker as a proxy between Laravel and D1.

**Add to your `.env`:**

```env
CF_D1_DRIVER=worker
CF_D1_WORKER_URL=https://your-d1-worker.your-subdomain.workers.dev
CF_D1_WORKER_SECRET=a-strong-shared-secret
```

#### Deploy the Worker

A ready-to-deploy Worker template is included in the [`Worker/`](Worker/) directory. To deploy:

```bash
cd Worker
npm install
npx wrangler secret put WORKER_SECRET
npm run deploy
```

Before deploying, update `wrangler.jsonc` with your D1 database binding:

```jsonc
name = "ntanduy-d1-worker"
main = "src/index.ts"
compatibility_date = "2026-03-10"

[[d1_databases]]
binding = "DB"
database_name = "your-database-name"
database_id = "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
```

> **Important:** Set `WORKER_SECRET` using `npx wrangler secret put WORKER_SECRET` — never put secrets in `wrangler.jsonc`. This secret must match the `CF_D1_WORKER_SECRET` in your Laravel `.env`.

#### HMAC Request Signing (Optional)

For additional security, enable HMAC request signing. Each request gets a unique signature that prevents body tampering and provides per-isolate replay detection.

**Laravel `.env`:**

```env
CF_D1_HMAC=true
```

**Worker (optional enforcement):**

```bash
npx wrangler secret put HMAC_REQUIRED        # Set to "true" to reject unsigned requests
npx wrangler secret put HMAC_WINDOW_SECONDS  # Replay window (default: 300 = 5 minutes)
```

When enabled, the PHP driver adds three headers to every request:

- `X-D1-Timestamp` — current Unix timestamp
- `X-D1-Nonce` — random 32-character hex string (unique per request)
- `X-D1-Signature` — HMAC-SHA256 of `timestamp.nonce.body` using the shared secret

The Worker verifies these when present, rejects expired timestamps, and tracks seen nonces to prevent replay within the same isolate. The per-request nonce ensures that two identical requests within the same second produce different signatures and are not falsely rejected as replays. Without `HMAC_REQUIRED=true`, unsigned requests still work (backward compatible).

> **Note:** Replay detection is per-isolate — it resets on Worker cold starts and is separate per Cloudflare colo. For stricter guarantees, consider using Durable Objects for global nonce storage.

#### Worker Endpoints

The Worker exposes these endpoints:

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/health` | GET | ❌ | Health check |
| `/query` | POST | ✅ Bearer | Execute a single SQL query |
| `/batch` | POST | ✅ Bearer | Execute multiple statements atomically |
| `/exec` | POST | ✅ Bearer | Execute raw DDL/migration SQL |
| `/raw` | POST | ✅ Bearer | Execute a query and return raw array-of-arrays |

---

### Step 3: Set as Default Connection

To use D1 as the default database, add to your `.env`:

```env
DB_CONNECTION=d1
```

### Step 4: Verify Connection

Run the built-in health check to verify your setup:

```bash
php artisan d1:health
```

```
  D1 Health Check
  Connection : d1
  Driver     : worker

+-------------------------+---------+------------------------------------------+
| Check                   | Status  | Detail                                   |
+-------------------------+---------+------------------------------------------+
| worker_url configured   | ✓ OK    | https://d1-proxy.name.workers.dev        |
| worker_secret configured| ✓ OK    | ******cret                               |
| Query test passed       | ✓ OK    | SELECT 1 as ok                           |
| End-to-end latency      | ✓ OK    | 24 ms                                    |
+-------------------------+---------+------------------------------------------+

  Overall: HEALTHY ✓
```

### Step 5: Run Migrations

```bash
php artisan migrate --database=d1
```

## 📖 Examples

### Eloquent ORM

```php
use App\Models\Post;

// Create
$post = Post::create([
    'title' => 'Hello from D1',
    'body' => 'This is stored in Cloudflare D1!',
]);

// Read
$posts = Post::where('published', true)->orderBy('created_at', 'desc')->get();

// Update
$post->update(['title' => 'Updated Title']);

// Delete
$post->delete();
```

### Query Builder

```php
use Illuminate\Support\Facades\DB;

// Select
$users = DB::connection('d1')->table('users')
    ->where('active', true)
    ->limit(10)
    ->get();

// Insert
DB::connection('d1')->table('users')->insert([
    'name' => 'John Doe',
    'email' => 'john@example.com',
]);

// Raw queries
$results = DB::connection('d1')->select('SELECT * FROM users WHERE id = ?', [1]);
```

### Query Logger

Monitor queries for debugging or performance analysis:

```php
use Ntanduy\CFD1\D1\D1Connection;

/** @var D1Connection $connection */
$connection = DB::connection('d1');

$connection->d1()->setQueryLogger(function (
    string $query,
    array $params,
    float $timeMs,
    bool $success,
    ?array $error
) {
    if (! $success) {
        Log::error("D1 query failed: {$query}", [
            'params' => $params,
            'error' => $error,
            'time_ms' => $timeMs,
        ]);
    }
});
```

### Runtime Driver Detection

```php
use Ntanduy\CFD1\D1\D1Connection;

/** @var D1Connection $connection */
$connection = DB::connection('d1');

$connection->getDriver();      // 'rest' or 'worker'
$connection->isWorkerDriver(); // true or false
```

### Batch Queries

Execute multiple SQL statements in a single HTTP round-trip. On the Worker driver, this uses D1's native `batch()` for atomic execution.

```php
use Ntanduy\CFD1\D1\D1Connection;

/** @var D1Connection $connection */
$connection = DB::connection('d1');

$results = $connection->batch([
    ['sql' => 'SELECT * FROM users WHERE id = ?', 'params' => [1]],
    ['sql' => 'UPDATE stats SET views = views + 1 WHERE id = ?', 'params' => [5]],
    ['sql' => 'SELECT COUNT(*) as total FROM posts'],
]);

$user  = $results[0]; // Result set from first statement
$stats = $results[1]; // Result set from second statement
$count = $results[2]; // Result set from third statement
```

If any statement fails, a `D1BatchException` is thrown with the index of the failing statement:

```php
use Ntanduy\CFD1\D1\Exceptions\D1BatchException;

try {
    $results = $connection->batch($statements);
} catch (D1BatchException $e) {
    // $e->getMessage() includes the failing statement index
}
```

### Driver Feature Matrix

| Feature | REST | Worker |
|---------|------|--------|
| Bulk Insert | ✅ | ✅ |
| Sessions / Read Replication | ❌ Not supported | ✅ Full support |
| Auto Read/Write Splitting | ❌ Not supported | ✅ Full support |
| Import (`d1:import`) | ✅ | ✅ (via REST credentials) |
| Time Travel (`d1:time-travel`) | ✅ | ✅ (via REST credentials) |
| Schema Dump | ✅ | ✅ (via REST credentials) |
| Database Info (`d1:info`) | ✅ Full metadata | ✅ Query test + REST metadata |
| Batch Queries | ✅ | ✅ |
| Circuit Breaker | ✅ | ✅ |
| Automatic Retries | ✅ | ✅ |

### Bulk Insert

Insert multiple rows efficiently using D1 batch execution:

```php
use Ntanduy\CFD1\D1\D1Connection;

/** @var D1Connection $connection */
$connection = DB::connection('d1');

$connection->bulkInsert('users', [
    ['name' => 'Alice', 'email' => 'alice@example.com'],
    ['name' => 'Bob', 'email' => 'bob@example.com'],
    ['name' => 'Charlie', 'email' => 'charlie@example.com'],
]);
```

- Each row becomes a parameterized INSERT (SQL injection safe)
- Each chunk of up to 100 rows is sent as a D1 batch (atomic per chunk — if any statement in a chunk fails, that chunk is rolled back)
- Datasets exceeding D1's 100-statement batch limit are automatically chunked into multiple HTTP calls — **earlier chunks are committed even if a later chunk fails**
- Works with both REST and Worker drivers

### Sessions / Read Replication (Worker Driver Only)

D1 supports [global read replication](https://developers.cloudflare.com/d1/best-practices/read-replication/) — read queries can be served by nearby replicas for lower latency. The Sessions API ensures sequential consistency across queries.

> **Important:** Sessions are only available with the **Worker driver**. The REST API does not support D1 Sessions — this is a [Cloudflare platform limitation](https://developers.cloudflare.com/d1/best-practices/read-replication/).

#### Enable via Config

Add to your `.env`:

```env
CF_D1_SESSION_ENABLED=true
CF_D1_SESSION_MODE=first-unconstrained   # or 'first-primary'
```

This automatically enables sessions for all queries on the Worker driver.

#### Enable Programmatically

```php
use Ntanduy\CFD1\D1\D1Connection;

/** @var D1Connection $connection */
$connection = DB::connection('d1');

// Start a session — first query goes to any instance (fastest)
$connection->withSession('first-unconstrained');

// Or start with the latest data from primary
$connection->withSession('first-primary');

// Execute queries — bookmarks are tracked automatically
$users = DB::table('users')->get();
$posts = DB::table('posts')->get();

// Get the current bookmark (for passing to another request/session)
$bookmark = $connection->getBookmark();

// Start a new session from a previous bookmark
$connection->withSession($bookmark);

// End the session when done
$connection->endSession();
```

#### Session Modes

| Mode | First Query | Use When |
|------|------------|----------|
| `first-unconstrained` | Any instance (primary or replica) | Lowest latency, eventual consistency OK |
| `first-primary` | Primary database | Need the latest data for first query |
| `<bookmark>` | At least as fresh as the bookmark | Continuing from a previous session |

#### How It Works

1. PHP sends a `session` parameter with each query to the Worker
2. Worker calls `env.DB.withSession(param)` to create a D1 session
3. Worker returns a `bookmark` in the response
4. PHP stores the bookmark and uses it for the next query
5. This ensures **sequential consistency** across HTTP calls

#### Worker Template

The Worker template in [`Worker/`](Worker/) already includes session support. If you're upgrading from a previous version, redeploy the Worker:

```bash
cd Worker && npm run deploy
```

### Auto Read/Write Splitting (Worker Driver Only)

Automatically route `SELECT` queries to D1 replicas and `INSERT`/`UPDATE`/`DELETE` to the primary — zero code changes required.

```php
// config/database.php
'd1' => [
    'driver' => 'd1',
    'd1_driver' => 'worker',
    'worker_url' => env('CF_D1_WORKER_URL'),
    'worker_secret' => env('CF_D1_WORKER_SECRET'),

    'read' => [
        'session' => ['mode' => 'first-unconstrained'],
    ],
    'write' => [
        'session' => ['mode' => 'first-primary'],
    ],
    'sticky' => true,  // After write, reads use primary for consistency
],
```

Once configured, Laravel handles everything:

```php
// Automatically goes to replica (fast, nearby)
$users = User::all();

// Automatically goes to primary
User::create(['name' => 'Alice', 'email' => 'alice@example.com']);

// With sticky=true, this read goes to primary (sees the new user)
$user = User::where('email', 'alice@example.com')->first();
```

- **`sticky` (default: `true`)** — after a write, subsequent reads in the same request use the write connector's bookmark for sequential consistency
- Works alongside manual `withSession()` — R/W splitting handles the base routing, you can still use sessions for fine-grained control
- **REST driver ignores** `read`/`write` config — no sessions support, all queries go to primary

### Database Info

Inspect your D1 database metadata and connection status:

```bash
php artisan d1:info
```

Displays database name, UUID, size, table count, read replication mode, R/W splitting status, circuit breaker state, and runs a query test.

```bash
# Specify a connection
php artisan d1:info --connection=d1
```

> Uses the [D1 REST API](https://developers.cloudflare.com/api/resources/d1/subresources/database/methods/get/) for metadata. Worker-only users see table count and query test but need REST credentials for full metadata.

### Import

Import a SQL file into your D1 database:

```bash
php artisan d1:import path/to/file.sql
```

The command handles the full import flow automatically:
1. Computes MD5 hash and sends `init` request to get a presigned upload URL
2. Uploads the SQL file to R2
3. Triggers ingestion
4. Polls until import is complete

```bash
# Specify connection
php artisan d1:import database/seeds/data.sql --connection=d1
```

> **Note:** Like `d1:schema-dump`, the import command always uses the REST API. Worker-only users must also set `CF_D1_API_TOKEN`, `CF_D1_ACCOUNT_ID`, and `CF_D1_DATABASE_ID` in their `.env`.

### Time Travel

D1 automatically creates restore points (bookmarks) for up to 30 days. Use `d1:time-travel` to get the current bookmark or restore your database to any point in time:

```bash
# Get the current bookmark
php artisan d1:time-travel

# Get the bookmark at a specific timestamp
php artisan d1:time-travel --timestamp="2024-01-15T10:30:00+00:00"

# Unix timestamps also work
php artisan d1:time-travel --timestamp=1705312200
```

To restore the database to a previous state:

```bash
# Restore to a specific bookmark
php artisan d1:time-travel --restore --bookmark="00000085-0000024c-00004c6d-abc123"

# Restore to a timestamp
php artisan d1:time-travel --restore --timestamp="2024-01-15T10:30:00+00:00"
```

> **Warning:** Restore is a destructive operation — it overwrites the database in place. In-flight queries will be cancelled. The command will prompt for confirmation before proceeding. The previous bookmark is shown after restore so you can undo if needed.

### Schema Dump

Export your D1 database schema (and optionally data) as a SQL file:

```bash
php artisan d1:schema-dump
```

This uses the [D1 export REST API](https://developers.cloudflare.com/api/resources/d1/subresources/database/methods/export/) with polling mode. The dump is saved to `database/schema/{connection}-schema.sql`.

#### Options

```bash
# Schema only (no data)
php artisan d1:schema-dump --no-data

# Custom output path
php artisan d1:schema-dump --path=./backup.sql

# Delete migration files after dumping (same as native schema:dump --prune)
php artisan d1:schema-dump --prune

# Specify connection name
php artisan d1:schema-dump --connection=d1
```

> **Note:** `d1:schema-dump` always uses the REST API for export, even when the Worker driver is your primary connection. Worker-only users must also set `CF_D1_API_TOKEN`, `CF_D1_ACCOUNT_ID`, and `CF_D1_DATABASE_ID` in their `.env` for the dump command to work.

### Circuit Breaker

Prevents cascading failures when Cloudflare Workers experience cold starts or sustained outages. Instead of blocking for 30s+ on retries, the circuit breaker fails fast after consecutive failures.

**States:**

```
CLOSED → requests pass through normally
  ↓ (threshold consecutive failures)
OPEN → requests rejected immediately, no HTTP call
  ↓ (after cooldown seconds)
HALF_OPEN → one probe request allowed through
  ↓ success → CLOSED  |  failure → OPEN
```

**Enable in your config** (`config/database.php` or `config/d1-database.php`):

```php
'd1' => [
    // ... other options ...
    'circuit_breaker' => [
        'enabled'      => env('CF_D1_CB_ENABLED', false),
        'threshold'    => env('CF_D1_CB_THRESHOLD', 5),     // failures before opening
        'cooldown'     => env('CF_D1_CB_COOLDOWN', 30),     // seconds before probe
        'cache_driver' => env('CF_D1_CB_CACHE_DRIVER', 'file'),
    ],
],
```

**Handle the exception:**

```php
use Ntanduy\CFD1\D1\Exceptions\CircuitBreakerOpenException;

try {
    $users = DB::connection('d1')->table('users')->get();
} catch (CircuitBreakerOpenException $e) {
    // Circuit is open — fail fast, use fallback or return cached data
}
```

> **Note:** Use `file` or `redis` as the `cache_driver`. Avoid `database` to prevent a dependency loop when D1 itself is down.

### Retry & Backoff

The driver automatically retries failed requests with exponential backoff and jitter:

- **Retried:** 5xx server errors, 429 rate limiting, connection timeouts
- **Not retried:** 4xx client errors (400, 401, 403, 404, etc.)

```env
CF_D1_RETRIES=2          # Max retry attempts (default: 2)
CF_D1_RETRY_DELAY=100    # Base delay in ms (default: 100)
CF_D1_TIMEOUT=10         # Request timeout in seconds (default: 10)
CF_D1_CONNECT_TIMEOUT=5  # Connection timeout in seconds (default: 5)
```

Backoff formula: `delay × 2^(attempt-1) + random jitter (0-100ms)`

| Attempt | Base Delay (100ms) |
|---------|-------------------|
| 1       | ~100-200ms        |
| 2       | ~200-300ms        |

## ⚙️ Configuration Reference

### Manual Setup (Alternative)

Instead of publishing the config, you can add the connection directly to `config/database.php`:

```php
'connections' => [
    'd1' => [
        'driver' => 'd1',
        'd1_driver' => env('CF_D1_DRIVER', 'rest'),         // 'rest' or 'worker'
        'prefix' => '',
        'database' => env('CF_D1_DATABASE_ID', ''),

        // REST driver credentials
        'api' => 'https://api.cloudflare.com/client/v4',
        'auth' => [
            'token' => env('CF_D1_API_TOKEN', ''),
            'account_id' => env('CF_D1_ACCOUNT_ID', ''),
        ],

        // Worker driver credentials
        'worker_url' => env('CF_D1_WORKER_URL', ''),
        'worker_secret' => env('CF_D1_WORKER_SECRET', ''),
        'hmac' => env('CF_D1_HMAC', false),

        // Performance tuning
        'timeout' => env('CF_D1_TIMEOUT', 10),
        'connect_timeout' => env('CF_D1_CONNECT_TIMEOUT', 5),
        'retries' => env('CF_D1_RETRIES', 2),
        'retry_delay' => env('CF_D1_RETRY_DELAY', 100),

        // Sessions / Read Replication (Worker driver only)
        'session' => [
            'enabled' => env('CF_D1_SESSION_ENABLED', false),
            'mode'    => env('CF_D1_SESSION_MODE', 'first-unconstrained'),
        ],

        // Circuit breaker (optional)
        'circuit_breaker' => [
            'enabled'      => env('CF_D1_CB_ENABLED', false),
            'threshold'    => env('CF_D1_CB_THRESHOLD', 5),
            'cooldown'     => env('CF_D1_CB_COOLDOWN', 30),
            'cache_driver' => env('CF_D1_CB_CACHE_DRIVER', 'file'),
        ],
    ],
],
```

### Options Reference

| Option               | Default                                | Description                                                                 |
|----------------------|----------------------------------------|-----------------------------------------------------------------------------|
| `d1_driver`          | `rest`                                 | Connection driver: `rest` (Cloudflare REST API) or `worker` (custom Worker) |
| `database`           | —                                      | Your Cloudflare D1 Database ID                                              |
| `api`                | `https://api.cloudflare.com/client/v4` | Cloudflare API base URL (REST driver only)                                  |
| `auth.token`         | —                                      | Cloudflare API Token (REST driver only)                                     |
| `auth.account_id`    | —                                      | Cloudflare Account ID (REST driver only)                                    |
| `worker_url`         | —                                      | Your Worker URL (Worker driver only)                                        |
| `worker_secret`      | —                                      | Shared secret for Worker auth (Worker driver only)                          |
| `hmac`               | `false`                                | Enable HMAC request signing for body-tamper protection and replay detection (Worker driver only) |
| `timeout`            | `10`                                   | HTTP request timeout in seconds                                             |
| `connect_timeout`    | `5`                                    | HTTP connection timeout in seconds                                          |
| `retries`            | `2`                                    | Max retry attempts on 5xx/429 errors                                        |
| `retry_delay`        | `100`                                  | Base delay between retries in milliseconds                                  |
| `transaction_mode`       | `silent`                           | How transaction APIs are handled: `silent` (no-op), `log` (warn once), `exception` (throw) |
| `session.enabled`        | `false`                            | Enable D1 sessions for read replication (Worker driver only)                |
| `session.mode`           | `first-unconstrained`              | Session mode: `first-primary` or `first-unconstrained`                      |
| `read_write_splitting.enabled`    | `false`                   | Route SELECT to read replicas, writes to primary (Worker driver only)       |
| `read_write_splitting.sticky`     | `true`                    | After a write, route subsequent reads to write connector for consistency    |
| `read_write_splitting.read_mode`  | `first-unconstrained`     | Session mode for the read connector                                         |
| `read_write_splitting.write_mode` | `first-primary`           | Session mode for the write connector                                        |
| `circuit_breaker.enabled` | `false`                           | Enable circuit breaker for fail-fast behavior                               |
| `circuit_breaker.threshold` | `5`                             | Consecutive failures before opening the circuit                             |
| `circuit_breaker.cooldown` | `30`                              | Seconds before allowing a probe request                                     |
| `circuit_breaker.cache_driver` | `file`                        | Laravel cache driver for circuit state (`file`, `redis`)                    |


### Environment Variables

```env
# Driver selection
CF_D1_DRIVER=rest                    # 'rest' or 'worker'

# REST driver
CF_D1_API_TOKEN=your_api_token
CF_D1_ACCOUNT_ID=your_account_id
CF_D1_DATABASE_ID=your_database_id

# Worker driver
CF_D1_WORKER_URL=https://your-worker.workers.dev
CF_D1_WORKER_SECRET=your_shared_secret
CF_D1_HMAC=false                     # Enable HMAC request signing

# Performance tuning (optional)
CF_D1_TIMEOUT=10
CF_D1_CONNECT_TIMEOUT=5
CF_D1_RETRIES=2
CF_D1_RETRY_DELAY=100

# Transaction behavior (optional)
CF_D1_TRANSACTION_MODE=silent          # 'silent', 'log', or 'exception'

# Sessions / Read Replication (Worker driver only)
CF_D1_SESSION_ENABLED=false
CF_D1_SESSION_MODE=first-unconstrained

# Circuit breaker (optional)
CF_D1_CB_ENABLED=false
CF_D1_CB_THRESHOLD=5
CF_D1_CB_COOLDOWN=30
CF_D1_CB_CACHE_DRIVER=file
```

## ⚠️ Limitations

- **No real transactions** — D1 is stateless over HTTP and doesn't support `BEGIN`/`COMMIT`/`ROLLBACK`. The driver makes these methods no-ops so Laravel internals (auth, sessions, middleware) work without crashing.

  - `DB::transaction(Closure)` **will execute the closure**, but provides **no atomicity** — each query runs immediately and cannot be rolled back on failure.
  - `DB::transaction(Closure, attempts: N)` retries the closure on any exception, but without real deadlock detection or isolation.
  - Manual `beginTransaction()`, `commit()`, and `rollBack()` calls are also no-ops. `transaction_mode=log` warns once per connection/request; `transaction_mode=exception` throws immediately.
  - For atomic multi-statement execution, use **`batch()`** which leverages D1's native batch API:

    ```php
    $connection->batch([
        ['sql' => 'INSERT INTO orders ...', 'params' => [...]],
        ['sql' => 'UPDATE inventory ...', 'params' => [...]],
    ]);
    ```

- **REST API latency** — Each query is an HTTP request routed through the Cloudflare API. The Worker driver offers significantly lower latency because the Worker is co-located with your D1 database. Latency varies by region, database size, and query complexity.
- **Sessions / Read Replication — Worker driver only** — The D1 Sessions API is only available via the Worker Binding. The REST API does not support sessions; all queries go to the primary database. This is a [Cloudflare platform limitation](https://developers.cloudflare.com/d1/best-practices/read-replication/).
- **Schema dump requires REST credentials** — `d1:schema-dump` uses the D1 export REST API. Even Worker-only users must set `CF_D1_API_TOKEN`, `CF_D1_ACCOUNT_ID`, and `CF_D1_DATABASE_ID`.
- **Export blocks queries** — During export, D1 may be unavailable for queries (Cloudflare limitation for large databases).
- **Bulk insert batch limit** — D1 batch supports max 100 statements. `bulkInsert()` automatically chunks larger datasets but each chunk is a separate HTTP call.
- **No streaming** — Large result sets are loaded entirely into memory.

## 🌱 Testing

### PHP Tests

```bash
vendor/bin/pest
```

### Worker Tests (Vitest)

```bash
cd Worker
npm ci
npm test
```

### Local Development with Worker

Start the built-in Worker to test against a local D1 instance:

```bash
cd Worker
npm ci
npm run dev
```

## 🤝 Contributing

Contributions are welcome! Please open an issue or pull request on [GitHub](https://github.com/TanDuy03/cloudflare-d1-database).

## 🔒 Security

If you discover any security related issues, please email <contact@ntanduy.com> instead of using the issue tracker.

## 🎉 Credits

- [TanDuy03](https://github.com/TanDuy03)
- [All Contributors](../../contributors)
