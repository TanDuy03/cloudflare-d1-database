<?php

declare(strict_types=1);

use Ntanduy\CFD1\Connectors\CloudflareD1Connector;
use Ntanduy\CFD1\Connectors\CloudflareWorkerConnector;
use Ntanduy\CFD1\D1\D1Connection;
use Ntanduy\CFD1\D1\Pdo\D1Pdo;

/*
|--------------------------------------------------------------------------
| D1Connection – Driver switching tests
|--------------------------------------------------------------------------
*/

// ─── getDriver() ──────────────────────────────────────────────────────

test('getDriver returns rest when using CloudflareD1Connector', function () {
    $connector = Mockery::mock(CloudflareD1Connector::class);
    $connection = new D1Connection($connector, [
        'database' => 'test-db',
        'prefix'   => '',
        'name'     => 'd1',
    ]);

    expect($connection->getDriver())->toBe('rest');
});

test('getDriver returns worker when using CloudflareWorkerConnector', function () {
    $connector = Mockery::mock(CloudflareWorkerConnector::class);
    $connection = new D1Connection($connector, [
        'database' => 'test-db',
        'prefix'   => '',
        'name'     => 'd1',
    ]);

    expect($connection->getDriver())->toBe('worker');
});

// ─── isWorkerDriver() ─────────────────────────────────────────────────

test('isWorkerDriver returns false for REST connector', function () {
    $connector = Mockery::mock(CloudflareD1Connector::class);
    $connection = new D1Connection($connector, [
        'database' => 'test-db',
        'prefix'   => '',
        'name'     => 'd1',
    ]);

    expect($connection->isWorkerDriver())->toBeFalse();
});

test('isWorkerDriver returns true for Worker connector', function () {
    $connector = Mockery::mock(CloudflareWorkerConnector::class);
    $connection = new D1Connection($connector, [
        'database' => 'test-db',
        'prefix'   => '',
        'name'     => 'd1',
    ]);

    expect($connection->isWorkerDriver())->toBeTrue();
});

// ─── d1() return type ─────────────────────────────────────────────────

test('d1 returns the connector instance for REST connector', function () {
    $connector = Mockery::mock(CloudflareD1Connector::class);
    $connection = new D1Connection($connector, [
        'database' => 'test-db',
        'prefix'   => '',
        'name'     => 'd1',
    ]);

    expect($connection->d1())->toBe($connector);
});

test('d1 returns the connector instance for Worker connector', function () {
    $connector = Mockery::mock(CloudflareWorkerConnector::class);
    $connection = new D1Connection($connector, [
        'database' => 'test-db',
        'prefix'   => '',
        'name'     => 'd1',
    ]);

    expect($connection->d1())->toBe($connector);
});

// ─── PDO creation with Worker connector ───────────────────────────────

test('getPdo creates D1Pdo with Worker connector', function () {
    $connector = Mockery::mock(CloudflareWorkerConnector::class);
    $connection = new D1Connection($connector, [
        'database' => 'test-db',
        'prefix'   => '',
        'name'     => 'd1',
    ]);

    $pdo = $connection->getPdo();

    expect($pdo)->toBeInstanceOf(D1Pdo::class);
    expect($pdo->d1())->toBe($connector);
});
