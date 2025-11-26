<?php

namespace Ntanduy\CFD1;

use InvalidArgumentException;
use Illuminate\Support\ServiceProvider;
use Ntanduy\CFD1\D1\D1Connection;
use Ntanduy\CFD1\CloudflareD1Connector;

class D1ServiceProvider extends ServiceProvider
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
                $this->validateConfig($config);

                // Support both nested and flat config structure
                $token = $config['auth']['token'] ?? $config['token'] ?? '';
                $accountId = $config['auth']['account_id'] ?? $config['account_id'] ?? '';
                $api = $config['api'] ?? 'https://api.cloudflare.com/client/v4';

                $connection = new D1Connection(
                    new CloudflareD1Connector(
                        $config['database'],
                        $token,
                        $accountId,
                        $api,
                    ),
                    $config,
                );

                return $connection;
            });
        });
    }

    /**
     * Validate the D1 configuration.
     *
     * @param array $config
     * @return void
     *
     * @throws \InvalidArgumentException
     */
    protected function validateConfig(array $config): void
    {
        if (empty($config['database'])) {
            throw new InvalidArgumentException(
                'D1 database configuration requires a "database" (Database ID) option.'
            );
        }

        $token = $config['auth']['token'] ?? $config['token'] ?? null;
        if (empty($token)) {
            throw new InvalidArgumentException(
                'D1 database configuration requires a "token" (Cloudflare API Token) option.'
            );
        }

        $accountId = $config['auth']['account_id'] ?? $config['account_id'] ?? null;
        if (empty($accountId)) {
            throw new InvalidArgumentException(
                'D1 database configuration requires an "account_id" (Cloudflare Account ID) option.'
            );
        }
    }
}
