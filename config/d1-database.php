<?php

declare(strict_types=1);

return [
    'driver' => 'd1',
    'd1_driver' => env('D1_DRIVER', 'rest'),
    'prefix' => '',
    'database' => env('CLOUDFLARE_D1_DATABASE_ID', ''),
    'api' => 'https://api.cloudflare.com/client/v4',
    'auth' => [
        'token' => env('CLOUDFLARE_TOKEN', ''),
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID', ''),
    ],
    'worker_url' => env('D1_WORKER_URL', ''),
    'worker_secret' => env('D1_WORKER_SECRET', ''),
    'timeout' => env('D1_TIMEOUT', 10),
    'connect_timeout' => env('D1_CONNECT_TIMEOUT', 5),
    'retries' => env('D1_RETRIES', 2),
    'retry_delay' => env('D1_RETRY_DELAY', 100),
];
