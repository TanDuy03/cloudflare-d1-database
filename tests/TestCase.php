<?php

namespace Ntanduy\CFD1\Test;

use Orchestra\Testbench\TestCase as Orchestra;
use Ntanduy\CFD1\D1\D1Connection;
use Ntanduy\CFD1\Test\Mocks\MockCloudflareD1Connector;

abstract class TestCase extends Orchestra
{
    protected static $latestResponse = null;

    /**
     * {@inheritdoc}
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->loadLaravelMigrations(['--database' => 'd1']);

        // Don't use legacy factories - we'll use modern factories
        // $this->withFactories(__DIR__.'/database/factories');
    }

    /**
     * {@inheritdoc}
     */
    protected function getPackageProviders($app)
    {
        return [
            \Ntanduy\CFD1\D1ServiceProvider::class,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getEnvironmentSetUp($app)
    {
        $app['config']->set('app.key', 'wslxrEFGWY6GfGhvN9L3wH3KSRJQQpBD');
        $app['config']->set('auth.providers.users.model', Models\User::class);
        $app['config']->set('database.default', 'd1');
        $app['config']->set('database.connections.d1', [
            'driver' => 'd1',
            'prefix' => '',
            'database' => 'DB1',
            'api' => 'http://127.0.0.1:8787/api/client/v4',
            'auth' => [
                'token' => env('CLOUDFLARE_TOKEN', getenv('CLOUDFLARE_TOKEN')),
                'account_id' => env('CLOUDFLARE_ACCOUNT_ID', getenv('CLOUDFLARE_ACCOUNT_ID')),
            ],
        ]);

        // Override the D1 database connection with a mock connector
        $app->resolving('db', function ($db) {
            $db->extend('d1', function ($config, $name) {
                $config['name'] = $name;

                $connection = new D1Connection(
                    new MockCloudflareD1Connector(
                        $config['database'],
                        $config['auth']['token'],
                        $config['auth']['account_id'],
                        $config['api'] ?? 'https://api.cloudflare.com/client/v4',
                    ),
                    $config,
                );

                return $connection;
            });
        });
    }
}
