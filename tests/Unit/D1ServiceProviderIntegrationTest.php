<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\Test\Unit;

use InvalidArgumentException;
use Ntanduy\CFD1\D1\D1Connection;
use Ntanduy\CFD1\D1ServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration tests that exercise the real registerD1() closure path
 * without the MockCloudflareD1Connector override from the base TestCase.
 *
 * These tests ensure full branch coverage for:
 * - registerD1 closure (lines 48-70)
 * - getValidatedCredentials (lines 84-106)
 * - getConfigValue (lines 74-76)
 * - getPerformanceOptions (lines 126-134)
 */
class D1ServiceProviderIntegrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [D1ServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'wslxrEFGWY6GfGhvN9L3wH3KSRJQQpBD');
        $app['config']->set('database.default', 'd1');
        $app['config']->set('database.connections.d1', [
            'driver' => 'd1',
            'prefix' => '',
            'database' => 'test-db-id',
            'api' => 'https://api.cloudflare.com/client/v4',
            'auth' => [
                'token' => 'test-token',
                'account_id' => 'test-account-id',
            ],
        ]);
    }

    // ─── Real registerD1 closure path with nested auth ───────────────

    #[Test]
    public function test_creates_d1_connection_with_nested_auth_config(): void
    {
        $connection = $this->app['db']->connection('d1');

        $this->assertInstanceOf(D1Connection::class, $connection);
        $this->assertSame('d1', $connection->getConfig('name'));
        $this->assertSame('test-db-id', $connection->getConfig('database'));
    }

    // ─── Real registerD1 closure path with flat config ───────────────

    #[Test]
    public function test_creates_d1_connection_with_flat_config(): void
    {
        $this->app['config']->set('database.connections.d1', [
            'driver' => 'd1',
            'prefix' => '',
            'database' => 'test-db-flat',
            'token' => 'flat-token',
            'account_id' => 'flat-account',
        ]);

        $connection = $this->app['db']->connection('d1');

        $this->assertInstanceOf(D1Connection::class, $connection);
        $this->assertSame('test-db-flat', $connection->getConfig('database'));
    }

    // ─── api URL ?? default branch ───────────────────────────────────

    #[Test]
    public function test_uses_default_api_when_not_configured(): void
    {
        $this->app['config']->set('database.connections.d1', [
            'driver' => 'd1',
            'prefix' => '',
            'database' => 'test-db-id',
            'auth' => [
                'token' => 'test-token',
                'account_id' => 'test-account-id',
            ],
            // No 'api' key → falls back to default
        ]);

        $connection = $this->app['db']->connection('d1');

        $this->assertInstanceOf(D1Connection::class, $connection);
    }

    #[Test]
    public function test_uses_custom_api_when_configured(): void
    {
        $this->app['config']->set('database.connections.d1', [
            'driver' => 'd1',
            'prefix' => '',
            'database' => 'test-db-id',
            'api' => 'https://custom.api.example.com/v4',
            'auth' => [
                'token' => 'test-token',
                'account_id' => 'test-account-id',
            ],
        ]);

        $connection = $this->app['db']->connection('d1');

        $this->assertInstanceOf(D1Connection::class, $connection);
    }

    // ─── getPerformanceOptions ?? branches via real path ─────────────

    #[Test]
    public function test_uses_default_performance_options(): void
    {
        // Config has no timeout/connect_timeout/retries/retry_delay keys
        $this->app['config']->set('database.connections.d1', [
            'driver' => 'd1',
            'prefix' => '',
            'database' => 'test-db-id',
            'auth' => [
                'token' => 'test-token',
                'account_id' => 'test-account-id',
            ],
        ]);

        $connection = $this->app['db']->connection('d1');

        $this->assertInstanceOf(D1Connection::class, $connection);
    }

    #[Test]
    public function test_uses_custom_performance_options(): void
    {
        $this->app['config']->set('database.connections.d1', [
            'driver' => 'd1',
            'prefix' => '',
            'database' => 'test-db-id',
            'auth' => [
                'token' => 'test-token',
                'account_id' => 'test-account-id',
            ],
            'timeout' => 30,
            'connect_timeout' => 15,
            'retries' => 5,
            'retry_delay' => 500,
        ]);

        $connection = $this->app['db']->connection('d1');

        $this->assertInstanceOf(D1Connection::class, $connection);
    }

    #[Test]
    public function test_uses_partial_performance_options(): void
    {
        $this->app['config']->set('database.connections.d1', [
            'driver' => 'd1',
            'prefix' => '',
            'database' => 'test-db-id',
            'auth' => [
                'token' => 'test-token',
                'account_id' => 'test-account-id',
            ],
            'timeout' => 20,
            // connect_timeout, retries, retry_delay use defaults
        ]);

        $connection = $this->app['db']->connection('d1');

        $this->assertInstanceOf(D1Connection::class, $connection);
    }

    // ─── Validation exceptions via real path ─────────────────────────

    #[Test]
    public function test_throws_when_database_is_missing(): void
    {
        $this->app['config']->set('database.connections.d1', [
            'driver' => 'd1',
            'prefix' => '',
            'auth' => [
                'token' => 'test-token',
                'account_id' => 'test-account-id',
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"database"');

        $this->app['db']->connection('d1');
    }

    #[Test]
    public function test_throws_when_database_is_empty_string(): void
    {
        $this->app['config']->set('database.connections.d1', [
            'driver' => 'd1',
            'prefix' => '',
            'database' => '',
            'auth' => [
                'token' => 'test-token',
                'account_id' => 'test-account-id',
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"database"');

        $this->app['db']->connection('d1');
    }

    #[Test]
    public function test_throws_when_token_is_missing(): void
    {
        $this->app['config']->set('database.connections.d1', [
            'driver' => 'd1',
            'prefix' => '',
            'database' => 'test-db',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"token"');

        $this->app['db']->connection('d1');
    }

    #[Test]
    public function test_throws_when_token_is_empty_string(): void
    {
        $this->app['config']->set('database.connections.d1', [
            'driver' => 'd1',
            'prefix' => '',
            'database' => 'test-db',
            'auth' => [
                'token' => '',
                'account_id' => 'test-account-id',
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"token"');

        $this->app['db']->connection('d1');
    }

    #[Test]
    public function test_throws_when_account_id_is_missing(): void
    {
        $this->app['config']->set('database.connections.d1', [
            'driver' => 'd1',
            'prefix' => '',
            'database' => 'test-db',
            'auth' => [
                'token' => 'test-token',
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"account_id"');

        $this->app['db']->connection('d1');
    }

    #[Test]
    public function test_throws_when_account_id_is_empty_string(): void
    {
        $this->app['config']->set('database.connections.d1', [
            'driver' => 'd1',
            'prefix' => '',
            'database' => 'test-db',
            'auth' => [
                'token' => 'test-token',
                'account_id' => '',
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"account_id"');

        $this->app['db']->connection('d1');
    }
}
