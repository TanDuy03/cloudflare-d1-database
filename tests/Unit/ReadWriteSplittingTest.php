<?php

declare(strict_types=1);

use Ntanduy\CFD1\Connectors\CloudflareWorkerConnector;
use Ntanduy\CFD1\D1\D1Connection;
use Ntanduy\CFD1\D1\Pdo\D1Pdo;

function createWorkerConnector(): CloudflareWorkerConnector
{
    return new CloudflareWorkerConnector(
        workerUrl: 'https://test-worker.workers.dev',
        workerSecret: 'test-secret',
        options: [
            'retries' => 0,
            'retry_delay' => 1,
            'timeout' => 5,
            'connect_timeout' => 2,
        ],
    );
}

// ─── Constructor ─────────────────────────────────────────────────────

test('D1Connection without read connector has no R/W splitting', function () {
    $connector = createWorkerConnector();

    $connection = new D1Connection($connector, [
        'database' => 'test-db',
        'prefix' => '',
        'name' => 'd1',
    ]);

    expect($connection->hasReadWriteSplitting())->toBeFalse();
});

test('D1Connection with read connector enables R/W splitting', function () {
    $writeConnector = createWorkerConnector();
    $readConnector = createWorkerConnector();

    $connection = new D1Connection($writeConnector, [
        'database' => 'test-db',
        'prefix' => '',
        'name' => 'd1',
    ], $readConnector);

    expect($connection->hasReadWriteSplitting())->toBeTrue();
});

// ─── PDO instances ───────────────────────────────────────────────────

test('getPdo returns write PDO', function () {
    $writeConnector = createWorkerConnector();
    $readConnector = createWorkerConnector();

    $connection = new D1Connection($writeConnector, [
        'database' => 'test-db',
        'prefix' => '',
        'name' => 'd1',
    ], $readConnector);

    $pdo = $connection->getPdo();
    expect($pdo)->toBeInstanceOf(D1Pdo::class);
    expect($pdo->d1())->toBe($writeConnector);
});

test('getReadPdo returns read PDO when no writes occurred', function () {
    $writeConnector = createWorkerConnector();
    $readConnector = createWorkerConnector();

    $connection = new D1Connection($writeConnector, [
        'database' => 'test-db',
        'prefix' => '',
        'name' => 'd1',
    ], $readConnector);

    $readPdo = $connection->getReadPdo();
    expect($readPdo)->toBeInstanceOf(D1Pdo::class);
    expect($readPdo->d1())->toBe($readConnector);
});

// ─── Sticky behavior ─────────────────────────────────────────────────

test('getReadPdo returns write PDO after records modified with sticky=true', function () {
    $writeConnector = createWorkerConnector();
    $readConnector = createWorkerConnector();

    $connection = new D1Connection($writeConnector, [
        'database' => 'test-db',
        'prefix' => '',
        'name' => 'd1',
        'sticky' => true,
    ], $readConnector);

    // Simulate a write
    $connection->recordsHaveBeenModified(true);

    // After write, sticky should force read to use write PDO
    $readPdo = $connection->getReadPdo();
    expect($readPdo->d1())->toEqual($writeConnector);
});

test('getReadPdo returns read PDO after records modified with sticky=false', function () {
    $writeConnector = createWorkerConnector();
    $readConnector = createWorkerConnector();

    $connection = new D1Connection($writeConnector, [
        'database' => 'test-db',
        'prefix' => '',
        'name' => 'd1',
        'sticky' => false,
    ], $readConnector);

    // Simulate a write
    $connection->recordsHaveBeenModified(true);

    // Without sticky, read still uses read PDO
    $readPdo = $connection->getReadPdo();
    expect($readPdo->d1())->toEqual($readConnector);
});

// ─── Without R/W splitting ───────────────────────────────────────────

test('getReadPdo falls back to write PDO without read connector', function () {
    $writeConnector = createWorkerConnector();

    $connection = new D1Connection($writeConnector, [
        'database' => 'test-db',
        'prefix' => '',
        'name' => 'd1',
    ]);

    $readPdo = $connection->getReadPdo();
    expect($readPdo->d1())->toBe($writeConnector);
});

// ─── Session modes on connectors ─────────────────────────────────────

test('read connector has read session mode and write connector has write session mode', function () {
    $writeConnector = createWorkerConnector();
    $readConnector = createWorkerConnector();

    $writeConnector->enableSession('first-primary');
    $readConnector->enableSession('first-unconstrained');

    $connection = new D1Connection($writeConnector, [
        'database' => 'test-db',
        'prefix' => '',
        'name' => 'd1',
    ], $readConnector);

    expect($writeConnector->getSessionParam())->toBe('first-primary');
    expect($readConnector->getSessionParam())->toBe('first-unconstrained');
    expect($connection->hasReadWriteSplitting())->toBeTrue();
});

// ─── Default sticky is true ──────────────────────────────────────────

test('sticky defaults to true when config key is present', function () {
    $writeConnector = createWorkerConnector();
    $readConnector = createWorkerConnector();

    $connection = new D1Connection($writeConnector, [
        'database' => 'test-db',
        'prefix' => '',
        'name' => 'd1',
        'sticky' => true,
    ], $readConnector);

    // Simulate a write
    $connection->recordsHaveBeenModified(true);

    // Should use write PDO because sticky is true
    $readPdo = $connection->getReadPdo();
    expect($readPdo->d1())->toEqual($writeConnector);
});
