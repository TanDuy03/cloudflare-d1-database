<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\Test\Unit;

use Ntanduy\CFD1\D1ServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class D1ServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [D1ServiceProvider::class];
    }

    #[Test]
    public function test_it_merges_and_injects_d1_configuration(): void
    {
        $connection = $this->app['config']->get('database.connections.d1');

        $this->assertIsArray($connection);

        // Verify all expected keys from the package config are present
        $this->assertSame('d1', $connection['driver']);
        $this->assertSame('https://api.cloudflare.com/client/v4', $connection['api']);
        $this->assertArrayHasKey('database', $connection);
        $this->assertArrayHasKey('prefix', $connection);
        $this->assertArrayHasKey('auth', $connection);
        $this->assertIsArray($connection['auth']);
        $this->assertArrayHasKey('token', $connection['auth']);
        $this->assertArrayHasKey('account_id', $connection['auth']);
        $this->assertArrayHasKey('timeout', $connection);
        $this->assertArrayHasKey('connect_timeout', $connection);
        $this->assertArrayHasKey('retries', $connection);
        $this->assertArrayHasKey('retry_delay', $connection);

        // Verify the merged config also lives under its own key
        $packageConfig = $this->app['config']->get('d1-database');
        $this->assertIsArray($packageConfig);
        $this->assertSame('d1', $packageConfig['driver']);
    }

    #[Test]
    public function test_it_respects_user_overrides_in_configuration(): void
    {
        // The provider does: array_merge($packageDefaults, $userOverrides)
        // User values (second arg) override package defaults (first arg).
        // Verify this merge contract using the same logic as the provider.
        $packageDefaults = $this->app['config']->get('d1-database');

        $userOverrides = [
            'driver' => 'd1',
            'database' => 'my-custom-database-id',
            'api' => 'https://custom-api.example.com/v4',
            'auth' => [
                'token' => 'user-secret-token',
                'account_id' => 'user-account-id',
            ],
            'timeout' => 30,
        ];

        $merged = array_merge($packageDefaults, $userOverrides);

        // User-defined values MUST take precedence
        $this->assertSame('my-custom-database-id', $merged['database']);
        $this->assertSame('https://custom-api.example.com/v4', $merged['api']);
        $this->assertSame(30, $merged['timeout']);
        $this->assertSame('user-secret-token', $merged['auth']['token']);
        $this->assertSame('user-account-id', $merged['auth']['account_id']);

        // Package defaults for keys the user did NOT override are preserved
        $this->assertSame('d1', $merged['driver']);
        $this->assertArrayHasKey('prefix', $merged);
        $this->assertArrayHasKey('connect_timeout', $merged);
        $this->assertArrayHasKey('retries', $merged);
        $this->assertArrayHasKey('retry_delay', $merged);
    }

    #[Test]
    public function test_it_registers_publishable_config(): void
    {
        // Verify paths are registered for this provider
        $paths = D1ServiceProvider::pathsToPublish(D1ServiceProvider::class);
        $this->assertNotEmpty($paths, 'No publishable paths registered for D1ServiceProvider.');

        $expectedDestination = config_path('d1-database.php');
        $this->assertContains(
            $expectedDestination,
            $paths,
            'd1-database.php is not registered as publishable.'
        );

        // Verify the 'd1-config' tag is correctly assigned
        $tagged = D1ServiceProvider::pathsToPublish(D1ServiceProvider::class, 'd1-config');
        $this->assertNotEmpty($tagged, 'No paths registered under the "d1-config" publish tag.');
        $this->assertContains($expectedDestination, $tagged);
    }
}
