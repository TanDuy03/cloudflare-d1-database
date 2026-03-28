<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\TransactionBeginning;
use Mockery\MockInterface;
use Ntanduy\CFD1\Connectors\CloudflareD1Connector;
use Ntanduy\CFD1\D1\D1Connection;
use Ntanduy\CFD1\D1\Pdo\D1Pdo;

function createD1Connection(array $config = []): D1Connection
{
    $connector = Mockery::mock(CloudflareD1Connector::class);

    return new D1Connection($connector, array_merge([
        'database' => 'test-db',
        'prefix'   => '',
        'name'     => 'd1',
    ], $config));
}

// ─── d1() method ─────────────────────────────────────────────────────

test('d1 returns the connector instance', function () {
    $connector = Mockery::mock(CloudflareD1Connector::class);
    $connection = new D1Connection($connector, [
        'database' => 'test-db',
        'prefix'   => '',
        'name'     => 'd1',
    ]);

    expect($connection->d1())->toBe($connector);
});

// ─── getReadPdo() branches ───────────────────────────────────────────

test('getReadPdo resolves closure and returns the result', function () {
    $connection = createD1Connection();
    $mockPdo = Mockery::mock(D1Pdo::class);

    // Assign a Closure to readPdo via reflection
    $ref = new ReflectionProperty($connection, 'readPdo');
    $ref->setValue($connection, fn () => $mockPdo);

    $result = $connection->getReadPdo();

    expect($result)->toBe($mockPdo);
});

test('getReadPdo returns instance directly when already resolved', function () {
    $connection = createD1Connection();
    $mockPdo = Mockery::mock(D1Pdo::class);

    // Assign a D1Pdo instance directly (not a Closure)
    $ref = new ReflectionProperty($connection, 'readPdo');
    $ref->setValue($connection, $mockPdo);

    $result = $connection->getReadPdo();

    expect($result)->toBe($mockPdo);
});

test('getReadPdo falls back to getPdo when readPdo is null', function () {
    $connection = createD1Connection();

    // readPdo is null by default, so getReadPdo should delegate to getPdo
    $ref = new ReflectionProperty($connection, 'readPdo');
    $ref->setValue($connection, null);

    $result = $connection->getReadPdo();

    expect($result)->toBeInstanceOf(D1Pdo::class);
    // Should be the same as getPdo
    expect($result)->toBe($connection->getPdo());
});

// ─── beginTransaction fires connection event ─────────────────────────

test('beginTransaction increments transaction count and fires event', function () {
    $connection = createD1Connection();

    // Mock the PDO to not throw on beginTransaction
    $mockPdo = Mockery::mock(D1Pdo::class);
    $mockPdo->shouldReceive('beginTransaction')->once()->andReturn(true);

    $ref = new ReflectionProperty($connection, 'pdo');
    $ref->setValue($connection, $mockPdo);

    // Set up event dispatcher to capture the event
    $firedEvents = [];
    /** @var Dispatcher&MockInterface $dispatcher */
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once()->withArgs(function ($event) use (&$firedEvents) {
        $firedEvents[] = $event;

        return true;
    });

    $connection->setEventDispatcher($dispatcher);

    $connection->beginTransaction();

    expect($firedEvents)->toHaveCount(1);
    expect($firedEvents[0])->toBeInstanceOf(TransactionBeginning::class);
});

test('beginTransaction does not call pdo beginTransaction on nested transactions', function () {
    $connection = createD1Connection();

    $mockPdo = Mockery::mock(D1Pdo::class);
    // Only called once for the first transaction
    $mockPdo->shouldReceive('beginTransaction')->once()->andReturn(true);

    $ref = new ReflectionProperty($connection, 'pdo');
    $ref->setValue($connection, $mockPdo);

    /** @var Dispatcher&MockInterface $dispatcher */
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch');

    $connection->setEventDispatcher($dispatcher);

    // First call: transactions becomes 1, calls pdo->beginTransaction()
    $connection->beginTransaction();
    // Second call: transactions becomes 2, skips pdo->beginTransaction()
    $connection->beginTransaction();

    // Mockery verifies beginTransaction was called only once (from ->once() above)
});
