<?php

declare(strict_types=1);

namespace Ntanduy\CFD1;

use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Ntanduy\CFD1\Connectors\CloudflareD1Connector;
use Ntanduy\CFD1\Connectors\CloudflareWorkerConnector;
use Ntanduy\CFD1\Console\Commands\D1HealthCommand;
use Ntanduy\CFD1\D1\D1Connection;

class D1ServiceProvider extends ServiceProvider
{
    /**
     * Boot the service provider.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->printStarReminder();

            $this->publishes([
                __DIR__.'/../config/d1-database.php' => config_path('d1-database.php'),
            ], 'd1-config');

            $this->commands([
                D1HealthCommand::class,
            ]);
        }
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/d1-database.php',
            'd1-database'
        );

        $config = $this->app['config'];
        $packageDefaults = $config->get('d1-database', []);
        $userOverrides = $config->get('database.connections.d1', []);
        $config->set('database.connections.d1', array_merge($packageDefaults, $userOverrides));

        $this->registerD1();
    }

    /**
     * Register the D1 service.
     */
    protected function registerD1(): void
    {
        $this->app->resolving('db', function ($db) {
            $db->extend('d1', function ($config, $name) {
                $config['name'] = $name;

                $d1Driver = $config['d1_driver'] ?? 'rest';

                // Performance options with sensible defaults
                $options = $this->getPerformanceOptions($config);

                if ($d1Driver === 'worker') {
                    $connector = $this->createWorkerConnector($config, $options);
                } else {
                    $connector = $this->createRestConnector($config, $options);
                }

                return new D1Connection($connector, $config);
            });
        });
    }

    /**
     * Create a REST connector for the Cloudflare D1 API.
     */
    private function createRestConnector(array $config, array $options): CloudflareD1Connector
    {
        $credentials = $this->getValidatedCredentials($config);
        $api = $config['api'] ?? 'https://api.cloudflare.com/client/v4';

        return new CloudflareD1Connector(
            $credentials['database'],
            $credentials['token'],
            $credentials['account_id'],
            $api,
            $options,
        );
    }

    /**
     * Create a Worker connector for the Cloudflare Worker endpoint.
     */
    private function createWorkerConnector(array $config, array $options): CloudflareWorkerConnector
    {
        $workerUrl = $config['worker_url'] ?? '';
        if (empty($workerUrl)) {
            throw new InvalidArgumentException('D1 Worker driver requires a "worker_url" option.');
        }

        $workerSecret = $config['worker_secret'] ?? '';
        if (empty($workerSecret)) {
            throw new InvalidArgumentException('D1 Worker driver requires a "worker_secret" option.');
        }

        return new CloudflareWorkerConnector(
            $workerUrl,
            $workerSecret,
            $options,
        );
    }

    private function getConfigValue(array $config, string $key, string $default = ''): string
    {
        return $config['auth'][$key] ?? $config[$key] ?? $default;
    }

    /**
     * Validate the D1 configuration.
     *
     * @throws InvalidArgumentException
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
     * Print a one-time reminder to star the GitHub repository.
     */
    private function printStarReminder(): void
    {
        try {
            $flagFile = storage_path('.d1_star_reminder');
        } catch (\Throwable) {
            return;
        }

        if (file_exists($flagFile)) {
            return;
        }

        file_put_contents($flagFile, 'shown');

        $y = "\033[33m";
        $r = "\033[0m";

        echo PHP_EOL;
        echo "{$y}╔══════════════════════════════════════════════════════════╗{$r}".PHP_EOL;
        echo "{$y}║  Thank you for installing cloudflare-d1-database!⭐     ║{$r}".PHP_EOL;
        echo "{$y}║  Star us on GitHub:                                      ║{$r}".PHP_EOL;
        echo "{$y}║  https://github.com/TanDuy03/cloudflare-d1-database      ║{$r}".PHP_EOL;
        echo "{$y}╚══════════════════════════════════════════════════════════╝{$r}".PHP_EOL;
        echo PHP_EOL;
    }
}
