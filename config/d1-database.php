<?php

declare(strict_types=1);

return [
    'driver' => 'd1',
    'd1_driver' => env('CF_D1_DRIVER', 'rest'),
    'prefix' => '',
    'database' => env('CF_D1_DATABASE_ID', ''),
    'api' => 'https://api.cloudflare.com/client/v4',
    'auth' => [
        'token' => env('CF_D1_API_TOKEN', ''),
        'account_id' => env('CF_D1_ACCOUNT_ID', ''),
    ],
    'worker_url' => env('CF_D1_WORKER_URL', ''),
    'worker_secret' => env('CF_D1_WORKER_SECRET', ''),
    'hmac' => env('CF_D1_HMAC', false),
    'timeout' => env('CF_D1_TIMEOUT', 10),
    'connect_timeout' => env('CF_D1_CONNECT_TIMEOUT', 5),
    'retries' => env('CF_D1_RETRIES', 2),
    'retry_delay' => env('CF_D1_RETRY_DELAY', 100),

    /*
    |--------------------------------------------------------------------------
    | Transaction Behavior
    |--------------------------------------------------------------------------
    |
    | D1 is stateless over HTTP — real BEGIN/COMMIT/ROLLBACK are impossible.
    | DB::transaction() runs the closure but provides no atomicity or rollback.
    | Manual beginTransaction(), commit(), and rollBack() are also no-ops.
    |
    | This setting controls how the driver handles transaction calls:
    |
    |   'silent'    — (default) no-op, backward compatible. The closure runs
    |                 and manual transaction methods return normally.
    |   'log'       — logs one warning via Log::warning() when transaction
    |                 APIs are used. Useful for detecting unintentional usage.
    |   'exception' — throws D1TransactionException immediately. Use this in
    |                 development/staging to catch transaction usage early.
    |
    | For atomic multi-statement execution, use batch() instead:
    |   DB::connection('d1')->batch([...]);
    |
    */
    'transaction_mode' => env('CF_D1_TRANSACTION_MODE', 'silent'),

    /*
    |--------------------------------------------------------------------------
    | D1 Sessions / Read Replication (Worker driver only)
    |--------------------------------------------------------------------------
    |
    | When enabled, the Worker driver uses D1's Sessions API to leverage
    | global read replicas for lower-latency reads with sequential
    | consistency. REST driver does NOT support sessions — all queries
    | go to the primary database instance regardless of this setting.
    |
    | mode — 'first-primary': first query hits primary, then replicas
    |        'first-unconstrained': first query hits any instance (default)
    |
    */
    'session' => [
        'enabled' => env('CF_D1_SESSION_ENABLED', false),
        'mode' => env('CF_D1_SESSION_MODE', 'first-unconstrained'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Automatic Read/Write Splitting (Worker driver only)
    |--------------------------------------------------------------------------
    |
    | When enabled, the package automatically routes SELECT queries to a
    | read connector (using D1 replicas) and INSERT/UPDATE/DELETE to a
    | write connector (using D1 primary).
    |
    | 'sticky' (default: true) — after a write, subsequent reads in the
    | same request use the write bookmark for sequential consistency.
    |
    | REST driver ignores this setting (no session support).
    |
    */
    'read_write_splitting' => [
        'enabled' => env('CF_D1_RW_SPLITTING', false),
        'sticky' => env('CF_D1_RW_STICKY', true),
        'read_mode' => env('CF_D1_RW_READ_MODE', 'first-unconstrained'),
        'write_mode' => env('CF_D1_RW_WRITE_MODE', 'first-primary'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker
    |--------------------------------------------------------------------------
    |
    | Prevents cascading failures by failing fast when the remote service
    | is experiencing sustained errors (e.g. Worker cold starts, outages).
    |
    | threshold   — consecutive failures before opening the circuit
    | cooldown    — seconds before allowing a probe request
    | cache_driver — Laravel cache driver for storing circuit state
    |
    */
    'circuit_breaker' => [
        'enabled' => env('CF_D1_CB_ENABLED', false),
        'threshold' => env('CF_D1_CB_THRESHOLD', 5),
        'cooldown' => env('CF_D1_CB_COOLDOWN', 30),
        'cache_driver' => env('CF_D1_CB_CACHE_DRIVER', 'file'),
    ],
];
