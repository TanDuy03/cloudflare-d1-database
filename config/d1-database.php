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
    'timeout' => env('CF_D1_TIMEOUT', 10),
    'connect_timeout' => env('CF_D1_CONNECT_TIMEOUT', 5),
    'retries' => env('CF_D1_RETRIES', 2),
    'retry_delay' => env('CF_D1_RETRY_DELAY', 100),

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
