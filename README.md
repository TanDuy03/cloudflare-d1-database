# D1 - Cloudflare bindings for Laravel

[![Tests](https://github.com/TanDuy03/cloudflare-d1-database/actions/workflows/tests.yml/badge.svg)](https://github.com/TanDuy03/cloudflare-d1-database/actions/workflows/tests.yml)
![Packagist Dependency Version](https://img.shields.io/packagist/dependency-v/ntanduy/cloudflare-d1-database/php)
[![Latest Stable Version](https://poser.pugx.org/ntanduy/cloudflare-d1-database/v/stable)](https://packagist.org/packages/ntanduy/cloudflare-d1-database)
[![Total Downloads](https://poser.pugx.org/ntanduy/cloudflare-d1-database/downloads)](https://packagist.org/packages/ntanduy/cloudflare-d1-database)
[![Monthly Downloads](https://poser.pugx.org/ntanduy/cloudflare-d1-database/d/monthly)](https://packagist.org/packages/ntanduy/cloudflare-d1-database)
[![License](https://poser.pugx.org/ntanduy/cloudflare-d1-database/license)](https://packagist.org/packages/ntanduy/cloudflare-d1-database)

Integrate Cloudflare bindings into your PHP/Laravel application.

## 🎯 Requirements

- **PHP**: >= 8.2
- **Laravel**: 10.x, 11.x, or 12.x

## ✨ Features

This package offers support for:

- [x] [Cloudflare D1](https://developers.cloudflare.com/d1)

## 🚀 Installation

```bash
composer require ntanduy/cloudflare-d1-database
```

## 👏 Usage

### Integrate Cloudflare D1 with Laravel

Add a new connection in your `config/database.php` file:

```php
'connections' => [
    'd1' => [
        'driver' => 'd1',
        'prefix' => '',
        'database' => env('CLOUDFLARE_D1_DATABASE_ID', ''),
        'api' => 'https://api.cloudflare.com/client/v4',
        'auth' => [
            'token' => env('CLOUDFLARE_TOKEN', ''),
            'account_id' => env('CLOUDFLARE_ACCOUNT_ID', ''),
        ],
        'timeout' => env('D1_TIMEOUT', 10),
        'connect_timeout' => env('D1_CONNECT_TIMEOUT', 5),
        'retries' => env('D1_RETRIES', 2),
        'retry_delay' => env('D1_RETRY_DELAY', 100),
    ],
]
```

Next, configure your Cloudflare credentials in the `.env` file:

```
CLOUDFLARE_TOKEN=your_api_token
CLOUDFLARE_ACCOUNT_ID=your_account_id
CLOUDFLARE_D1_DATABASE_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
```

### Configuration Options

| Option            | Default | Description                          |
| ----------------- | ------- | ------------------------------------ |
| `timeout`         | 10      | Request timeout in seconds           |
| `connect_timeout` | 5       | Connection timeout in seconds        |
| `retries`         | 2       | Max retry attempts on 5xx/429 errors |
| `retry_delay`     | 100     | Base delay between retries (ms)      |

For production, you can tune these via `.env`:

```
D1_TIMEOUT=10
D1_CONNECT_TIMEOUT=5
D1_RETRIES=2
D1_RETRY_DELAY=100
```

The `d1` driver will forward PDO queries to the Cloudflare D1 API to execute them.

## ⚠️ Limitations

- **No real transactions**: D1 doesn't support `BEGIN`/`COMMIT`/`ROLLBACK`. The driver simulates transaction state for Laravel compatibility, but queries are executed immediately.
- **REST API latency**: Each query is an HTTP request (~100-500ms). For low-latency needs, consider using Cloudflare Workers with native D1 bindings.

## 🌱 Testing

Start the built-in Worker to simulate the Cloudflare API:

```bash
cd tests/worker
npm ci
npm run start
```

In a separate terminal, run the tests:

```bash
vendor/bin/pest
```

## 🤝 Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## 🔒 Security

If you discover any security related issues, please email <contact@ntanduy.com> instead of using the issue tracker.

## 🎉 Credits

- [TanDuy03](https://github.com/TanDuy03)
- [All Contributors](../../contributors)
