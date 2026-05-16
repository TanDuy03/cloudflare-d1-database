<?php

declare(strict_types=1);

namespace Ntanduy\CFD1;

use Illuminate\Cache\Repository;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Ntanduy\CFD1\Connectors\CloudflareD1Connector;
use Ntanduy\CFD1\Connectors\CloudflareWorkerConnector;
use Ntanduy\CFD1\Console\Commands\D1HealthCommand;
use Ntanduy\CFD1\Console\Commands\D1ImportCommand;
use Ntanduy\CFD1\Console\Commands\D1InfoCommand;
use Ntanduy\CFD1\Console\Commands\D1SchemaDumpCommand;
use Ntanduy\CFD1\Console\Commands\D1TimeTravelCommand;
use Ntanduy\CFD1\D1\D1Connection;

class D1ServiceProvider extends ServiceProvider
{
    /**
     * Boot the service provider.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/d1-database.php' => config_path('d1-database.php'),
            ], 'd1-config');

            $this->commands([
                D1HealthCommand::class,
                D1ImportCommand::class,
                D1InfoCommand::class,
                D1SchemaDumpCommand::class,
                D1TimeTravelCommand::class,
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

        // Ensure database.connections.d1 exists so other packages that reference
        // this connection during boot (e.g. spatie/permission) don't crash.
        // User-defined config in database.connections.d1 takes priority.
        if (! $this->app['config']->has('database.connections.d1')) {
            $this->app['config']->set(
                'database.connections.d1',
                $this->app['config']->get('d1-database', [])
            );
        }

        $this->registerD1();
    }

    /**
     * Register the D1 service.
     */
    protected function registerD1(): void
    {
        $this->app->resolving('db', function ($db) {
            $db->extend('d1', function ($config, $name) {
                // Merge operational defaults (d1-database.php) under user's connection config.
                // Credentials (auth, database, worker_secret) are excluded — missing
                // credentials should trigger validation errors, not be silently filled.
                $defaults = $this->app['config']->get('d1-database', []);
                unset($defaults['auth'], $defaults['database'], $defaults['worker_secret'], $defaults['worker_url']);
                $config = array_replace_recursive($defaults, $config);
                $config['name'] = $name;

                $d1Driver = $config['d1_driver'] ?? 'rest';

                if (!in_array($d1Driver, ['rest', 'worker'], true)) {
                    throw new InvalidArgumentException(
                        "Invalid D1 driver '{$d1Driver}'. Must be 'rest' or 'worker'."
                    );
                }

                // Performance options with sensible defaults
                $options = $this->getPerformanceOptions($config);

                if ($d1Driver === 'worker') {
                    $connector = $this->createWorkerConnector($config, $options);
                } else {
                    $connector = $this->createRestConnector($config, $options);
                }

                // Attach circuit breaker if enabled
                $cbConfig = $config['circuit_breaker'] ?? [];
                if (!empty($cbConfig['enabled'])) {
                    /** @var Repository $cacheStore */
                    $cacheStore = $this->app['cache']->store(
                        $cbConfig['cache_driver'] ?? 'file'
                    );
                    $connector->setCircuitBreaker(new CircuitBreaker(
                        connectionName: $name,
                        threshold: (int) ($cbConfig['threshold'] ?? 5),
                        cooldown: (int) ($cbConfig['cooldown'] ?? 30),
                        cache: $cacheStore,
                    ));
                }

                // Enable D1 session for Worker driver when configured
                $sessionConfig = $config['session'] ?? [];
                if (
                    !empty($sessionConfig['enabled'])
                    && $connector instanceof CloudflareWorkerConnector
                ) {
                    $connector->enableSession(
                        $sessionConfig['mode'] ?? 'first-unconstrained'
                    );
                }

                // Read/Write splitting — create a separate read connector (Worker only)
                $readConnector = null;
                if (
                    isset($config['read'])
                    && $d1Driver === 'worker'
                ) {
                    $readConnector = $this->createWorkerConnector($config, $options);

                    // Apply circuit breaker to read connector too
                    if (!empty($cbConfig['enabled'])) {
                        /** @var Repository $readCacheStore */
                        $readCacheStore = $this->app['cache']->store($cbConfig['cache_driver'] ?? 'file');
                        $readConnector->setCircuitBreaker(new CircuitBreaker(
                            connectionName: $name.'-read',
                            threshold: (int) ($cbConfig['threshold'] ?? 5),
                            cooldown: (int) ($cbConfig['cooldown'] ?? 30),
                            cache: $readCacheStore,
                        ));
                    }

                    $readSessionMode = $config['read']['session']['mode'] ?? 'first-unconstrained';
                    $readConnector->enableSession($readSessionMode);

                    // Enable session on write connector with write mode
                    $writeSessionMode = $config['write']['session']['mode'] ?? 'first-primary';
                    $connector->enableSession($writeSessionMode);
                }

                return new D1Connection($connector, $config, $readConnector);
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
            hmac: !empty($config['hmac']),
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
}
