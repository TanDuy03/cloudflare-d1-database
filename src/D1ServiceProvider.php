<?php

declare(strict_types=1);

namespace Ntanduy\CFD1;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Ntanduy\CFD1\D1\D1Connection;

class D1ServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Boot the service provider.
     *
     * @return void
     */
    public function boot()
    {
        //
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->registerD1();
    }

    /**
     * Register the D1 service.
     *
     * @return void
     */
    protected function registerD1()
    {
        $this->app->resolving('db', function ($db) {
            $db->extend('d1', function ($config, $name) {
                $config['name'] = $name;

                // Validate required configuration
                $credentials = $this->getValidatedCredentials($config);

                // Support both nested and flat config structure
                $api = $config['api'] ?? 'https://api.cloudflare.com/client/v4';

                // Performance options with sensible defaults
                $options = $this->getPerformanceOptions($config);

                $connector = new CloudflareD1Connector(
                    $credentials['database'],
                    $credentials['token'],
                    $credentials['account_id'],
                    $api,
                    $options,
                );

                return new D1Connection($connector, $config);
            });
        });
    }

    private function getConfigValue(array $config, string $key, string $default = ''): string
    {
        return $config['auth'][$key] ?? $config[$key] ?? $default;

    }

    /**
     * Validate the D1 configuration.
     *
     * @return void
     *
     * @throws \InvalidArgumentException
     */
    private function getValidatedCredentials(array $config): array
    {
        $database = $config['database'] ?? null;

        if (empty($database)) {
            throw new InvalidArgumentException('D1 database configuration requires a "database" (Database ID) option.');
        }

        $token = $this->getConfigValue($config, 'token');
        if (empty($token)) {
            throw new InvalidArgumentException('D1 database configuration requires a "token" (Cloudflare API Token) option.');
        }

        $accountId = $this->getConfigValue($config, 'account_id');
        if (empty($accountId)) {
            throw new InvalidArgumentException('D1 database configuration requires an "account_id" (Cloudflare Account ID) option.');
        }

        return [
            'database' => $database,
            'token' => $token,
            'account_id' => $accountId,
        ];
    }

    /**
     * Get performance and retry configuration options
     *
     * Returns configuration for HTTP client behavior:
     * - timeout: Maximum time to wait for response (seconds)
     * - connect_timeout: Maximum time to establish connection (seconds)
     * - retries: Number of retry attempts for failed requests
     * - retry_delay: Base delay for exponential backoff (milliseconds)
     *
     * Retry strategy uses exponential backoff with jitter to handle:
     * - Server errors (5xx)
     * - Rate limiting (429)
     * - Network failures
     *
     * @param  array  $config  Database connection configuration
     * @return array Performance options with sensible defaults
     */
    private function getPerformanceOptions(array $config): array
    {
        return [
            'timeout' => (int) ($config['timeout'] ?? 10),
            'connect_timeout' => (int) ($config['connect_timeout'] ?? 5),
            'retries' => (int) ($config['retries'] ?? 2),
            'retry_delay' => (int) ($config['retry_delay'] ?? 100),
        ];
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return ['db', 'db.connection.d1'];
    }
}
