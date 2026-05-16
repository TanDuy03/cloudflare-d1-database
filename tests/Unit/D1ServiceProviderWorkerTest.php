<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\Test\Unit;

use InvalidArgumentException;
use Ntanduy\CFD1\Connectors\CloudflareD1Connector;
use Ntanduy\CFD1\Connectors\CloudflareWorkerConnector;
use Ntanduy\CFD1\D1\D1Connection;
use Ntanduy\CFD1\D1ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;

class D1ServiceProviderWorkerTest extends Orchestra
{
    public static $latestResponse = null;

    protected function getPackageProviders($app): array
    {
        return [D1ServiceProvider::class];
    }

    // ─── Worker driver creates CloudflareWorkerConnector ──────────────

    #[Test]
    public function test_worker_driver_creates_worker_connector(): void
    {
        $this->app['config']->set('database.connections.d1', [
            'driver' => 'd1',
            'd1_driver' => 'worker',
            'database' => 'test-db',
            'prefix' => '',
            'worker_url' => 'https://test-worker.workers.dev',
            'worker_secret' => 'test-secret',
        ]);

        /** @var D1Connection $connection */
        $connection = $this->app['db']->connection('d1');

        $this->assertInstanceOf(D1Connection::class, $connection);
        $this->assertTrue($connection->isWorkerDriver());
        $this->assertSame('worker', $connection->getDriver());
        $this->assertInstanceOf(CloudflareWorkerConnector::class, $connection->d1());
    }

    #[Test]
    public function test_worker_connector_resolves_correct_base_url(): void
    {
        $this->app['config']->set('database.connections.d1', [
            'driver' => 'd1',
            'd1_driver' => 'worker',
            'database' => 'test-db',
            'prefix' => '',
            'worker_url' => 'https://my-custom-worker.example.dev',
            'worker_secret' => 'my-secret',
        ]);

        /** @var D1Connection $connection */
        $connection = $this->app['db']->connection('d1');
        /** @var CloudflareWorkerConnector $connector */
        $connector = $connection->d1();

        $this->assertSame('https://my-custom-worker.example.dev', $connector->resolveBaseUrl());
    }

    // ─── Validation errors ────────────────────────────────────────────

    #[Test]
    public function test_worker_driver_throws_when_worker_url_missing(): void
    {
        $this->app['config']->set('database.connections.d1', [
            'driver' => 'd1',
            'd1_driver' => 'worker',
            'database' => 'test-db',
            'prefix' => '',
            'worker_url' => '',
            'worker_secret' => 'test-secret',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('worker_url');

        $this->app['db']->connection('d1');
    }

    #[Test]
    public function test_worker_driver_throws_when_worker_secret_missing(): void
    {
        $this->app['config']->set('database.connections.d1', [
            'driver' => 'd1',
            'd1_driver' => 'worker',
            'database' => 'test-db',
            'prefix' => '',
            'worker_url' => 'https://test.workers.dev',
            'worker_secret' => '',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('worker_secret');

        $this->app['db']->connection('d1');
    }

    // ─── REST backward compatibility ──────────────────────────────────

    #[Test]
    public function test_rest_driver_still_works(): void
    {
        $this->app['config']->set('database.connections.d1', [
            'driver' => 'd1',
            'd1_driver' => 'rest',
            'database' => 'test-db',
            'prefix' => '',
            'auth' => [
                'token' => 'test-token',
                'account_id' => 'test-account',
            ],
        ]);

        /** @var D1Connection $connection */
        $connection = $this->app['db']->connection('d1');

        $this->assertInstanceOf(D1Connection::class, $connection);
        $this->assertFalse($connection->isWorkerDriver());
        $this->assertSame('rest', $connection->getDriver());
        $this->assertInstanceOf(CloudflareD1Connector::class, $connection->d1());
    }

    #[Test]
    public function test_default_d1_driver_is_rest(): void
    {
        $this->app['config']->set('database.connections.d1', [
            'driver' => 'd1',
            'database' => 'test-db',
            'prefix' => '',
            'auth' => [
                'token' => 'test-token',
                'account_id' => 'test-account',
            ],
        ]);

        /** @var D1Connection $connection */
        $connection = $this->app['db']->connection('d1');

        $this->assertFalse($connection->isWorkerDriver());
    }

    // ─── Performance options passthrough ──────────────────────────────

    #[Test]
    public function test_performance_options_passed_to_worker(): void
    {
        $this->app['config']->set('database.connections.d1', [
            'driver' => 'd1',
            'd1_driver' => 'worker',
            'database' => 'test-db',
            'prefix' => '',
            'worker_url' => 'https://test.workers.dev',
            'worker_secret' => 'test-secret',
            'timeout' => 30,
            'connect_timeout' => 10,
            'retries' => 5,
            'retry_delay' => 200,
        ]);

        /** @var D1Connection $connection */
        $connection = $this->app['db']->connection('d1');

        $this->assertInstanceOf(D1Connection::class, $connection);
        $this->assertTrue($connection->isWorkerDriver());
    }

    // ─── Read/Write splitting with Worker driver ─────────────────────

    #[Test]
    public function test_worker_driver_with_read_write_splitting(): void
    {
        $this->app['config']->set('database.connections.d1', [
            'driver' => 'd1',
            'd1_driver' => 'worker',
            'database' => 'test-db',
            'prefix' => '',
            'worker_url' => 'https://test-worker.workers.dev',
            'worker_secret' => 'test-secret',
            'read' => [
                'session' => ['mode' => 'first-unconstrained'],
            ],
            'write' => [
                'session' => ['mode' => 'first-primary'],
            ],
            'sticky' => true,
        ]);

        /** @var D1Connection $connection */
        $connection = $this->app['db']->connection('d1');

        $this->assertInstanceOf(D1Connection::class, $connection);
        $this->assertTrue($connection->isWorkerDriver());
    }

    #[Test]
    public function test_worker_driver_rw_splitting_with_circuit_breaker(): void
    {
        $this->app['config']->set('database.connections.d1', [
            'driver' => 'd1',
            'd1_driver' => 'worker',
            'database' => 'test-db',
            'prefix' => '',
            'worker_url' => 'https://test-worker.workers.dev',
            'worker_secret' => 'test-secret',
            'read' => [
                'session' => ['mode' => 'first-unconstrained'],
            ],
            'write' => [
                'session' => ['mode' => 'first-primary'],
            ],
            'circuit_breaker' => [
                'enabled' => true,
                'threshold' => 3,
                'cooldown' => 60,
                'cache_driver' => 'array',
            ],
        ]);

        /** @var D1Connection $connection */
        $connection = $this->app['db']->connection('d1');

        $this->assertInstanceOf(D1Connection::class, $connection);
        $this->assertTrue($connection->isWorkerDriver());
    }

    #[Test]
    public function test_worker_driver_rw_splitting_uses_default_session_modes(): void
    {
        $this->app['config']->set('database.connections.d1', [
            'driver' => 'd1',
            'd1_driver' => 'worker',
            'database' => 'test-db',
            'prefix' => '',
            'worker_url' => 'https://test-worker.workers.dev',
            'worker_secret' => 'test-secret',
            'read' => [],
            'write' => [],
        ]);

        /** @var D1Connection $connection */
        $connection = $this->app['db']->connection('d1');

        $this->assertInstanceOf(D1Connection::class, $connection);
        $this->assertTrue($connection->isWorkerDriver());
    }

    #[Test]
    public function test_worker_driver_session_enabled_without_rw_splitting(): void
    {
        $this->app['config']->set('database.connections.d1', [
            'driver' => 'd1',
            'd1_driver' => 'worker',
            'database' => 'test-db',
            'prefix' => '',
            'worker_url' => 'https://test-worker.workers.dev',
            'worker_secret' => 'test-secret',
            'session' => [
                'enabled' => true,
                'mode' => 'first-primary',
            ],
        ]);

        /** @var D1Connection $connection */
        $connection = $this->app['db']->connection('d1');

        $this->assertInstanceOf(D1Connection::class, $connection);
        $this->assertTrue($connection->isWorkerDriver());
    }

    #[Test]
    public function test_worker_driver_circuit_breaker_without_rw_splitting(): void
    {
        $this->app['config']->set('database.connections.d1', [
            'driver' => 'd1',
            'd1_driver' => 'worker',
            'database' => 'test-db',
            'prefix' => '',
            'worker_url' => 'https://test-worker.workers.dev',
            'worker_secret' => 'test-secret',
            'circuit_breaker' => [
                'enabled' => true,
                'threshold' => 5,
                'cooldown' => 30,
                'cache_driver' => 'array',
            ],
        ]);

        /** @var D1Connection $connection */
        $connection = $this->app['db']->connection('d1');

        $this->assertInstanceOf(D1Connection::class, $connection);
        $this->assertTrue($connection->isWorkerDriver());
    }

    #[Test]
    public function test_rest_driver_ignores_read_write_splitting(): void
    {
        // R/W splitting is Worker-only; REST driver should ignore 'read' config
        $this->app['config']->set('database.connections.d1', [
            'driver' => 'd1',
            'd1_driver' => 'rest',
            'database' => 'test-db',
            'prefix' => '',
            'auth' => [
                'token' => 'test-token',
                'account_id' => 'test-account',
            ],
            'read' => [
                'session' => ['mode' => 'first-unconstrained'],
            ],
            'write' => [
                'session' => ['mode' => 'first-primary'],
            ],
        ]);

        /** @var D1Connection $connection */
        $connection = $this->app['db']->connection('d1');

        $this->assertInstanceOf(D1Connection::class, $connection);
        $this->assertFalse($connection->isWorkerDriver());
    }

    #[Test]
    public function test_rest_driver_ignores_session_config(): void
    {
        // Session is Worker-only; REST driver should ignore it
        $this->app['config']->set('database.connections.d1', [
            'driver' => 'd1',
            'd1_driver' => 'rest',
            'database' => 'test-db',
            'prefix' => '',
            'auth' => [
                'token' => 'test-token',
                'account_id' => 'test-account',
            ],
            'session' => [
                'enabled' => true,
                'mode' => 'first-primary',
            ],
        ]);

        /** @var D1Connection $connection */
        $connection = $this->app['db']->connection('d1');

        $this->assertInstanceOf(D1Connection::class, $connection);
        $this->assertFalse($connection->isWorkerDriver());
    }
}
