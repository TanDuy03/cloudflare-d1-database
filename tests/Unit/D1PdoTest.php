<?php

declare(strict_types=1);

use Ntanduy\CFD1\Connectors\CloudflareD1Connector;
use Ntanduy\CFD1\D1\Exceptions\D1QueryException;
use Ntanduy\CFD1\D1\Exceptions\D1TransactionException;
use Ntanduy\CFD1\D1\Pdo\D1Pdo;
use PHPUnit\Framework\Assert;
use Saloon\Http\Response;

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
    expect($pdo->quote('test'))->toBe("'test'");
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

test('beginTransaction throws D1TransactionException', function () {
    $pdo = new D1Pdo('dsn', Mockery::mock(CloudflareD1Connector::class));

    $pdo->beginTransaction();
})->throws(D1TransactionException::class, 'D1 does not support transactions over stateless HTTP.');

test('commit throws D1TransactionException', function () {
    $pdo = new D1Pdo('dsn', Mockery::mock(CloudflareD1Connector::class));

    $pdo->commit();
})->throws(D1TransactionException::class, 'D1 does not support transactions over stateless HTTP.');

test('rollBack throws D1TransactionException', function () {
    $pdo = new D1Pdo('dsn', Mockery::mock(CloudflareD1Connector::class));

    $pdo->rollBack();
})->throws(D1TransactionException::class, 'D1 does not support transactions over stateless HTTP.');

test('inTransaction always returns false', function () {
    $pdo = new D1Pdo('dsn', Mockery::mock(CloudflareD1Connector::class));

    expect($pdo->inTransaction())->toBeFalse();
});

test('exec returns affected rows', function () {
    $connector = Mockery::mock(CloudflareD1Connector::class);
    $pdo = new D1Pdo('dsn', $connector);

    $response = Mockery::mock(Response::class);
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

// ── exec error handling ────────────────────────────────────────────────

test('exec throws D1QueryException on failed response when ERRMODE_EXCEPTION', function () {
    $connector = Mockery::mock(CloudflareD1Connector::class);
    $pdo = new D1Pdo('dsn', $connector);

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('failed')->andReturn(true);
    $response->shouldReceive('json')->with('errors.0.code')->andReturn(7500);
    $response->shouldReceive('json')->with('errors.0.message', 'Unknown error')->andReturn('syntax error near "INVALID"');

    $connector->shouldReceive('databaseQuery')->once()->andReturn($response);

    // ERRMODE_EXCEPTION is the default
    $pdo->exec('INVALID SQL');
})->throws(D1QueryException::class, 'syntax error near "INVALID"');

test('exec returns false on failed response when ERRMODE_SILENT', function () {
    $connector = Mockery::mock(CloudflareD1Connector::class);
    $pdo = new D1Pdo('dsn', $connector);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('failed')->andReturn(false);
    $response->shouldReceive('json')->with('success')->andReturn(false);
    $response->shouldReceive('json')->with('errors.0.code')->andReturn(1000);
    $response->shouldReceive('json')->with('errors.0.message', 'Unknown error')->andReturn('some error');

    $connector->shouldReceive('databaseQuery')->once()->andReturn($response);

    $result = $pdo->exec('BAD QUERY');

    expect($result)->toBeFalse();
});

test('exec populates errorInfo on failure', function () {
    $connector = Mockery::mock(CloudflareD1Connector::class);
    $pdo = new D1Pdo('dsn', $connector);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('failed')->andReturn(false);
    $response->shouldReceive('json')->with('success')->andReturn(false);
    $response->shouldReceive('json')->with('errors.0.code')->andReturn(1000);
    $response->shouldReceive('json')->with('errors.0.message', 'Unknown error')->andReturn('no such table: users');

    $connector->shouldReceive('databaseQuery')->once()->andReturn($response);

    $pdo->exec('SELECT * FROM users');

    expect($pdo->errorCode())->toBe('42S02')
        ->and($pdo->errorInfo())->toBe(['42S02', 1000, 'no such table: users']);
});

test('exec D1QueryException has errorInfo property set', function () {
    $connector = Mockery::mock(CloudflareD1Connector::class);
    $pdo = new D1Pdo('dsn', $connector);

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('failed')->andReturn(true);
    $response->shouldReceive('json')->with('errors.0.code')->andReturn(1000);
    $response->shouldReceive('json')->with('errors.0.message', 'Unknown error')->andReturn('no such table: foo');

    $connector->shouldReceive('databaseQuery')->once()->andReturn($response);

    try {
        $pdo->exec('SELECT * FROM foo');
        Assert::fail('Expected D1QueryException');
    } catch (D1QueryException $e) {
        expect($e->errorInfo)->toBe(['42S02', 1000, 'no such table: foo'])
            ->and($e->getCode())->toBe(1000)
            ->and($e->getMessage())->toBe('no such table: foo')
            ->and($e)->toBeInstanceOf(PDOException::class);
    }
});

// ── query method ───────────────────────────────────────────────────────

test('query executes and returns a PDOStatement', function () {
    $connector = Mockery::mock(CloudflareD1Connector::class);
    $pdo = new D1Pdo('dsn', $connector);

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('failed')->andReturn(false);
    $response->shouldReceive('json')->with('success')->andReturn(true);
    $response->shouldReceive('json')->with('result')->andReturn([
        ['results' => [['id' => 1, 'name' => 'Alice']], 'meta' => ['changes' => 0]],
    ]);

    $connector->shouldReceive('databaseQuery')->once()->andReturn($response);

    $stmt = $pdo->query('SELECT * FROM users');

    expect($stmt)->toBeInstanceOf(PDOStatement::class);
});

test('query applies fetch mode when provided', function () {
    $connector = Mockery::mock(CloudflareD1Connector::class);
    $pdo = new D1Pdo('dsn', $connector);

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('failed')->andReturn(false);
    $response->shouldReceive('json')->with('success')->andReturn(true);
    $response->shouldReceive('json')->with('result')->andReturn([
        ['results' => [['id' => 1]], 'meta' => ['changes' => 0]],
    ]);

    $connector->shouldReceive('databaseQuery')->once()->andReturn($response);

    $stmt = $pdo->query('SELECT id FROM users', PDO::FETCH_NUM);
    $row = $stmt->fetch();

    expect($row)->toBe([1]);
});

// ── errorCode / errorInfo defaults ─────────────────────────────────────

test('errorCode returns 00000 by default', function () {
    $pdo = new D1Pdo('dsn', Mockery::mock(CloudflareD1Connector::class));
    expect($pdo->errorCode())->toBe('00000');
});

test('errorInfo returns clean state by default', function () {
    $pdo = new D1Pdo('dsn', Mockery::mock(CloudflareD1Connector::class));
    expect($pdo->errorInfo())->toBe(['00000', null, null]);
});

// ── setAttribute ───────────────────────────────────────────────────────

test('setAttribute stores and getAttribute retrieves the value', function () {
    $pdo = new D1Pdo('dsn', Mockery::mock(CloudflareD1Connector::class));

    $result = $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

    expect($result)->toBeTrue()
        ->and($pdo->getAttribute(PDO::ATTR_ERRMODE))->toBe(PDO::ERRMODE_SILENT);
});

// ── setRetry / shouldRetry ─────────────────────────────────────────────

test('setRetry returns self and shouldRetry reflects the value', function () {
    $pdo = new D1Pdo('dsn', Mockery::mock(CloudflareD1Connector::class));

    expect($pdo->shouldRetry())->toBeTrue();

    $returned = $pdo->setRetry(false);

    expect($returned)->toBe($pdo)
        ->and($pdo->shouldRetry())->toBeFalse();

    $pdo->setRetry(true);
    expect($pdo->shouldRetry())->toBeTrue();
});

// ── shouldRetryFor ─────────────────────────────────────────────────────

test('shouldRetryFor returns false when retry is globally disabled', function () {
    $pdo = new D1Pdo('dsn', Mockery::mock(CloudflareD1Connector::class));
    $pdo->setRetry(false);

    expect($pdo->shouldRetryFor('SELECT 1'))->toBeFalse()
        ->and($pdo->shouldRetryFor('INSERT INTO users VALUES (1)'))->toBeFalse();
});

test('shouldRetryFor returns true only for SELECT/WITH when retry is enabled', function () {
    $pdo = new D1Pdo('dsn', Mockery::mock(CloudflareD1Connector::class));

    expect($pdo->shouldRetryFor('SELECT * FROM users'))->toBeTrue()
        ->and($pdo->shouldRetryFor('  select 1'))->toBeTrue()
        ->and($pdo->shouldRetryFor('WITH cte AS (SELECT 1) SELECT * FROM cte'))->toBeTrue()
        ->and($pdo->shouldRetryFor('INSERT INTO users VALUES (1)'))->toBeFalse()
        ->and($pdo->shouldRetryFor('UPDATE users SET name = "x"'))->toBeFalse()
        ->and($pdo->shouldRetryFor('DELETE FROM users'))->toBeFalse();
});
