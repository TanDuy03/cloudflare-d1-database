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
| **REST** (default) | Calls [Cloudflare D1 REST API](https://developers.cloudflare.com/api/resources/d1/subresources/database/methods/query/) directly | ~100-500ms/query | API Token only |
| **Worker** | Routes queries through your own [Cloudflare Worker](https://developers.cloudflare.com/workers/) | ~10-50ms/query | Requires deploying a Worker |

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

        // Performance tuning
        'timeout' => env('CF_D1_TIMEOUT', 10),
        'connect_timeout' => env('CF_D1_CONNECT_TIMEOUT', 5),
        'retries' => env('CF_D1_RETRIES', 2),
        'retry_delay' => env('CF_D1_RETRY_DELAY', 100),

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
| `timeout`            | `10`                                   | HTTP request timeout in seconds                                             |
| `connect_timeout`    | `5`                                    | HTTP connection timeout in seconds                                          |
| `retries`            | `2`                                    | Max retry attempts on 5xx/429 errors                                        |
| `retry_delay`        | `100`                                  | Base delay between retries in milliseconds                                  |
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

# Performance tuning (optional)
CF_D1_TIMEOUT=10
CF_D1_CONNECT_TIMEOUT=5
CF_D1_RETRIES=2
CF_D1_RETRY_DELAY=100

# Circuit breaker (optional)
CF_D1_CB_ENABLED=false
CF_D1_CB_THRESHOLD=5
CF_D1_CB_COOLDOWN=30
CF_D1_CB_CACHE_DRIVER=file
```

## ⚠️ Limitations

- **No real transactions** — D1 doesn't support `BEGIN`/`COMMIT`/`ROLLBACK`. The driver simulates transaction state for Laravel compatibility, but queries are executed immediately.
- **REST API latency** — Each query is an HTTP request (~100-500ms). Use the Worker driver for lower latency (~10-50ms).
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
npm run start
```

## 🤝 Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## 🔒 Security

If you discover any security related issues, please email <contact@ntanduy.com> instead of using the issue tracker.

## 🎉 Credits

- [TanDuy03](https://github.com/TanDuy03)
- [All Contributors](../../contributors)