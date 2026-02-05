<?php

use Ntanduy\CFD1\D1\Pdo\D1Pdo;
use Ntanduy\CFD1\CloudflareD1Connector;

test('quote handles null', function () {
    $pdo = new D1Pdo('dsn', Mockery::mock(CloudflareD1Connector::class));
    expect($pdo->quote(null))->toBe('NULL');
});

test('quote handles boolean', function () {
    $pdo = new D1Pdo('dsn', Mockery::mock(CloudflareD1Connector::class));
    expect($pdo->quote(true))->toBe('1');
    expect($pdo->quote(false))->toBe('0');
});

test('quote handles string', function () {
    $pdo = new D1Pdo('dsn', Mockery::mock(CloudflareD1Connector::class));
    expect($pdo->quote("test"))->toBe("'test'");
    expect($pdo->quote("it's"))->toBe("'it''s'");
});

test('getAttribute returns correct driver name', function () {
    $pdo = new D1Pdo('dsn', Mockery::mock(CloudflareD1Connector::class));
    expect($pdo->getAttribute(PDO::ATTR_DRIVER_NAME))->toBe('sqlite');
});

test('getAttribute returns correct server version', function () {
    $pdo = new D1Pdo('dsn', Mockery::mock(CloudflareD1Connector::class));
    expect($pdo->getAttribute(PDO::ATTR_SERVER_VERSION))->toBe('D1');
});

test('getAttribute returns correct client version', function () {
    if (!defined('PDO::ATTR_CLIENT_VERSION')) {
        $this->markTestSkipped('PDO::ATTR_CLIENT_VERSION not available on this PHP build');
    }

    $pdo = new D1Pdo('dsn', Mockery::mock(CloudflareD1Connector::class));
    expect($pdo->getAttribute(PDO::ATTR_CLIENT_VERSION))->toBe('D1');
});

test('transaction methods manage state', function () {
    $connector = Mockery::mock(CloudflareD1Connector::class);

    $pdo = new D1Pdo('dsn', $connector);

    expect($pdo->inTransaction())->toBeFalse();

    expect($pdo->beginTransaction())->toBeTrue();
    expect($pdo->inTransaction())->toBeTrue();

    // Should throw exception when trying to begin a transaction that's already active
    expect(fn() => $pdo->beginTransaction())
        ->toThrow(PDOException::class, 'There is already an active transaction');

    expect($pdo->commit())->toBeTrue();
    expect($pdo->inTransaction())->toBeFalse();

    // Should throw exception when trying to commit without active transaction
    expect(fn() => $pdo->commit())
        ->toThrow(PDOException::class, 'There is no active transaction');

    $pdo->beginTransaction();
    expect($pdo->rollBack())->toBeTrue();
    expect($pdo->inTransaction())->toBeFalse();

    // Should throw exception when trying to rollback without active transaction
    expect(fn() => $pdo->rollBack())
        ->toThrow(PDOException::class, 'There is no active transaction');
});

test('commit resets transaction state even if no queries were executed', function () {
    $connector = Mockery::mock(CloudflareD1Connector::class);

    $pdo = new D1Pdo('dsn', $connector);

    $pdo->beginTransaction();
    expect($pdo->inTransaction())->toBeTrue();

    $pdo->commit();
    expect($pdo->inTransaction())->toBeFalse();
});

test('exec returns affected rows', function () {
    $connector = Mockery::mock(CloudflareD1Connector::class);
    $pdo = new D1Pdo('dsn', $connector);

    $response = Mockery::mock(Saloon\Http\Response::class);
    $response->shouldReceive('failed')->andReturn(false);
    $response->shouldReceive('json')->with('success')->andReturn(true);
    $response->shouldReceive('json')->with('result.0')->andReturn(['meta' => ['changes' => 10]]);
    $response->shouldReceive('json')->with('errors.0.code')->andReturn(null);
    $response->shouldReceive('json')->with('errors.0.message')->andReturn(null);

    $connector->shouldReceive('databaseQuery')->once()->andReturn($response);

    expect($pdo->exec('DELETE FROM users'))->toBe(10);
});

test('lastInsertId manages state', function () {
    $pdo = new D1Pdo('dsn', Mockery::mock(CloudflareD1Connector::class));

    expect($pdo->lastInsertId())->toBeFalse();

    $pdo->setLastInsertId(null, '123');
    expect($pdo->lastInsertId())->toBe('123');

    $pdo->setLastInsertId('seq', '456');
    expect($pdo->lastInsertId('seq'))->toBe('456');
});
