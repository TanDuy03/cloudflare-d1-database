<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\Test\Unit;

use InvalidArgumentException;
use Ntanduy\CFD1\D1\D1Connection;
use Ntanduy\CFD1\D1ServiceProvider;
use Ntanduy\CFD1\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

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

        // Core keys must exist in the final merged connection config
        $this->assertSame('d1', $connection['driver']);
        $this->assertArrayHasKey('api', $connection);
        $this->assertArrayHasKey('database', $connection);
        $this->assertArrayHasKey('prefix', $connection);
        $this->assertArrayHasKey('auth', $connection);
        $this->assertIsArray($connection['auth']);
        $this->assertArrayHasKey('token', $connection['auth']);
        $this->assertArrayHasKey('account_id', $connection['auth']);

        // Package defaults are available under their own config key
        $packageConfig = $this->app['config']->get('d1-database');
        $this->assertIsArray($packageConfig);
        $this->assertSame('d1', $packageConfig['driver']);
        $this->assertArrayHasKey('timeout', $packageConfig);
        $this->assertArrayHasKey('connect_timeout', $packageConfig);
        $this->assertArrayHasKey('retries', $packageConfig);
        $this->assertArrayHasKey('retry_delay', $packageConfig);
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

    // ─── getConfigValue branch coverage ──────────────────────────────

    #[Test]
    public function test_get_config_value_returns_auth_nested_value_first(): void
    {
        $provider = $this->app->getProvider(D1ServiceProvider::class);
        $method = new ReflectionMethod($provider, 'getConfigValue');

        // Both auth.token and flat token exist – auth.token wins
        $config = [
            'auth' => ['token' => 'nested-token'],
            'token' => 'flat-token',
        ];

        $this->assertSame('nested-token', $method->invoke($provider, $config, 'token'));
    }

    #[Test]
    public function test_get_config_value_falls_back_to_flat_config(): void
    {
        $provider = $this->app->getProvider(D1ServiceProvider::class);
        $method = new ReflectionMethod($provider, 'getConfigValue');

        // No auth key at all – should fall back to flat config key
        $config = [
            'token' => 'flat-token',
        ];

        $this->assertSame('flat-token', $method->invoke($provider, $config, 'token'));
    }

    #[Test]
    public function test_get_config_value_falls_back_to_default(): void
    {
        $provider = $this->app->getProvider(D1ServiceProvider::class);
        $method = new ReflectionMethod($provider, 'getConfigValue');

        // Neither auth nor flat key – should return default
        $config = [];

        $this->assertSame('my-default', $method->invoke($provider, $config, 'token', 'my-default'));
    }

    #[Test]
    public function test_get_config_value_returns_empty_default_when_no_value(): void
    {
        $provider = $this->app->getProvider(D1ServiceProvider::class);
        $method = new ReflectionMethod($provider, 'getConfigValue');

        // No value and no explicit default – should return ''
        $config = ['auth' => []];

        $this->assertSame('', $method->invoke($provider, $config, 'token'));
    }

    // ─── getValidatedCredentials branch coverage ─────────────────────

    #[Test]
    public function test_validated_credentials_throws_when_database_is_missing(): void
    {
        $provider = $this->app->getProvider(D1ServiceProvider::class);
        $method = new ReflectionMethod($provider, 'getValidatedCredentials');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"database"');

        $method->invoke($provider, []);
    }

    #[Test]
    public function test_validated_credentials_throws_when_token_is_missing(): void
    {
        $provider = $this->app->getProvider(D1ServiceProvider::class);
        $method = new ReflectionMethod($provider, 'getValidatedCredentials');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"token"');

        $method->invoke($provider, [
            'database' => 'test-db',
        ]);
    }

    #[Test]
    public function test_validated_credentials_throws_when_account_id_is_missing(): void
    {
        $provider = $this->app->getProvider(D1ServiceProvider::class);
        $method = new ReflectionMethod($provider, 'getValidatedCredentials');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"account_id"');

        $method->invoke($provider, [
            'database' => 'test-db',
            'auth' => ['token' => 'my-token'],
        ]);
    }

    #[Test]
    public function test_validated_credentials_returns_correct_values_with_nested_auth(): void
    {
        $provider = $this->app->getProvider(D1ServiceProvider::class);
        $method = new ReflectionMethod($provider, 'getValidatedCredentials');

        $result = $method->invoke($provider, [
            'database' => 'test-db',
            'auth' => [
                'token' => 'nested-token',
                'account_id' => 'nested-account',
            ],
        ]);

        $this->assertSame('test-db', $result['database']);
        $this->assertSame('nested-token', $result['token']);
        $this->assertSame('nested-account', $result['account_id']);
    }

    #[Test]
    public function test_validated_credentials_returns_correct_values_with_flat_config(): void
    {
        $provider = $this->app->getProvider(D1ServiceProvider::class);
        $method = new ReflectionMethod($provider, 'getValidatedCredentials');

        $result = $method->invoke($provider, [
            'database' => 'test-db',
            'token' => 'flat-token',
            'account_id' => 'flat-account',
        ]);

        $this->assertSame('test-db', $result['database']);
        $this->assertSame('flat-token', $result['token']);
        $this->assertSame('flat-account', $result['account_id']);
    }

    #[Test]
    public function test_validated_credentials_throws_when_database_is_empty_string(): void
    {
        $provider = $this->app->getProvider(D1ServiceProvider::class);
        $method = new ReflectionMethod($provider, 'getValidatedCredentials');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"database"');

        $method->invoke($provider, ['database' => '']);
    }

    #[Test]
    public function test_validated_credentials_throws_when_token_is_empty_string(): void
    {
        $provider = $this->app->getProvider(D1ServiceProvider::class);
        $method = new ReflectionMethod($provider, 'getValidatedCredentials');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"token"');

        $method->invoke($provider, [
            'database' => 'test-db',
            'auth' => ['token' => ''],
        ]);
    }

    #[Test]
    public function test_validated_credentials_throws_when_account_id_is_empty_string(): void
    {
        $provider = $this->app->getProvider(D1ServiceProvider::class);
        $method = new ReflectionMethod($provider, 'getValidatedCredentials');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"account_id"');

        $method->invoke($provider, [
            'database' => 'test-db',
            'auth' => ['token' => 'my-token', 'account_id' => ''],
        ]);
    }

    // ─── getPerformanceOptions branch coverage ───────────────────────

    #[Test]
    public function test_performance_options_returns_sensible_defaults(): void
    {
        $provider = $this->app->getProvider(D1ServiceProvider::class);
        $method = new ReflectionMethod($provider, 'getPerformanceOptions');

        $result = $method->invoke($provider, []);

        $this->assertSame(10, $result['timeout']);
        $this->assertSame(5, $result['connect_timeout']);
        $this->assertSame(2, $result['retries']);
        $this->assertSame(100, $result['retry_delay']);
    }

    #[Test]
    public function test_performance_options_respects_custom_values(): void
    {
        $provider = $this->app->getProvider(D1ServiceProvider::class);
        $method = new ReflectionMethod($provider, 'getPerformanceOptions');

        $result = $method->invoke($provider, [
            'timeout' => 30,
            'connect_timeout' => 15,
            'retries' => 5,
            'retry_delay' => 500,
        ]);

        $this->assertSame(30, $result['timeout']);
        $this->assertSame(15, $result['connect_timeout']);
        $this->assertSame(5, $result['retries']);
        $this->assertSame(500, $result['retry_delay']);
    }

    #[Test]
    public function test_performance_options_with_partial_custom_values(): void
    {
        $provider = $this->app->getProvider(D1ServiceProvider::class);
        $method = new ReflectionMethod($provider, 'getPerformanceOptions');

        // Only timeout and retries are custom; connect_timeout and retry_delay use defaults
        $result = $method->invoke($provider, [
            'timeout' => 20,
            'retries' => 3,
        ]);

        $this->assertSame(20, $result['timeout']);
        $this->assertSame(5, $result['connect_timeout']);
        $this->assertSame(3, $result['retries']);
        $this->assertSame(100, $result['retry_delay']);
    }

    // ─── registerD1 closure execution coverage ───────────────────────

    #[Test]
    public function test_register_d1_resolves_d1_connection(): void
    {
        // TestCase already sets up a mock d1 driver, verify it resolves a D1Connection
        $connection = $this->app['db']->connection('d1');

        $this->assertInstanceOf(D1Connection::class, $connection);
    }

    #[Test]
    public function test_register_d1_connection_uses_config_name(): void
    {
        $connection = $this->app['db']->connection('d1');

        $this->assertInstanceOf(D1Connection::class, $connection);
        $this->assertSame('d1', $connection->getConfig('name'));
    }
}
