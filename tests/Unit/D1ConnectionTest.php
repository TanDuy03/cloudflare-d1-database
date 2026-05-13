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
        'prefix' => '',
        'name' => 'd1',
    ], $config));
}

// ─── d1() method ─────────────────────────────────────────────────────

test('d1 returns the connector instance', function () {
    $connector = Mockery::mock(CloudflareD1Connector::class);
    $connection = new D1Connection($connector, [
        'database' => 'test-db',
        'prefix' => '',
        'name' => 'd1',
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

// ─── Transactions are no-ops (D1 is stateless) ──────────────────────

test('beginTransaction increments transaction count and fires event', function () {
    $connection = createD1Connection();

    // Set up event dispatcher to capture the event
    $firedEvents = [];
    /** @var Dispatcher&MockInterface $dispatcher */
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->withArgs(function ($event) use (&$firedEvents) {
        $firedEvents[] = $event;

        return true;
    });

    $connection->setEventDispatcher($dispatcher);

    $connection->beginTransaction();

    expect($connection->transactionLevel())->toBe(1);
    expect($firedEvents[0])->toBeInstanceOf(TransactionBeginning::class);
});

test('nested beginTransaction increments counter', function () {
    $connection = createD1Connection();

    /** @var Dispatcher&MockInterface $dispatcher */
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch');

    $connection->setEventDispatcher($dispatcher);

    $connection->beginTransaction();
    $connection->beginTransaction();

    expect($connection->transactionLevel())->toBe(2);
});

test('commit decrements transaction count', function () {
    $connection = createD1Connection();

    /** @var Dispatcher&MockInterface $dispatcher */
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch');

    $connection->setEventDispatcher($dispatcher);

    $connection->beginTransaction();
    expect($connection->transactionLevel())->toBe(1);

    $connection->commit();
    expect($connection->transactionLevel())->toBe(0);
});

test('rollBack decrements transaction count', function () {
    $connection = createD1Connection();

    /** @var Dispatcher&MockInterface $dispatcher */
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch');

    $connection->setEventDispatcher($dispatcher);

    $connection->beginTransaction();
    expect($connection->transactionLevel())->toBe(1);

    $connection->rollBack();
    expect($connection->transactionLevel())->toBe(0);
});

test('DB::transaction closure executes without throwing', function () {
    $connection = createD1Connection();

    /** @var Dispatcher&MockInterface $dispatcher */
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch');

    $connection->setEventDispatcher($dispatcher);

    $result = $connection->transaction(function () {
        return 'success';
    });

    expect($result)->toBe('success');
    expect($connection->transactionLevel())->toBe(0);
});
