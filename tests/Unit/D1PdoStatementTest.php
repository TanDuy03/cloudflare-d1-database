<?php

declare(strict_types=1);

use Ntanduy\CFD1\CloudflareD1Connector;
use Ntanduy\CFD1\D1\Pdo\D1Pdo;
use Ntanduy\CFD1\D1\Pdo\D1PdoStatement;
use Saloon\Http\Response;

test('rowCount returns 0 for SELECT queries', function () {
    $connector = Mockery::mock(CloudflareD1Connector::class);
    $pdo = new D1Pdo('dsn', $connector);

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('failed')->andReturn(false);
    $response->shouldReceive('json')->with('success')->andReturn(true);
    $response->shouldReceive('json')->with('result')->andReturn([
        [
            'results' => [['id' => 1]],
            'meta' => ['changes' => 0, 'last_row_id' => null],
        ],
    ]);

    $connector->shouldReceive('databaseQuery')->once()->andReturn($response);

    $stmt = new D1PdoStatement($pdo, 'SELECT * FROM users');
    $stmt->execute();

    expect($stmt->rowCount())->toBe(0);
});

test('rowCount returns 0 for WITH queries', function () {
    $connector = Mockery::mock(CloudflareD1Connector::class);
    $pdo = new D1Pdo('dsn', $connector);

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('failed')->andReturn(false);
    $response->shouldReceive('json')->with('success')->andReturn(true);
    $response->shouldReceive('json')->with('result')->andReturn([
        [
            'results' => [['id' => 1]],
            'meta' => ['changes' => 0, 'last_row_id' => null],
        ],
    ]);

    $connector->shouldReceive('databaseQuery')->once()->andReturn($response);

    $stmt = new D1PdoStatement($pdo, 'WITH cte AS (SELECT 1) SELECT * FROM cte');
    $stmt->execute();

    expect($stmt->rowCount())->toBe(0);
});

test('rowCount returns affected rows for INSERT queries', function () {
    $connector = Mockery::mock(CloudflareD1Connector::class);
    $pdo = new D1Pdo('dsn', $connector);

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('failed')->andReturn(false);
    $response->shouldReceive('json')->with('success')->andReturn(true);
    $response->shouldReceive('json')->with('result')->andReturn([
        [
            'results' => [],
            'meta' => ['changes' => 5, 'last_row_id' => 10],
        ],
    ]);

    $connector->shouldReceive('databaseQuery')->once()->andReturn($response);

    $stmt = new D1PdoStatement($pdo, 'INSERT INTO users VALUES (...)');
    $stmt->execute();

    expect($stmt->rowCount())->toBe(5);
});

test('execute does not reorder bindings', function () {
    $connector = Mockery::mock(CloudflareD1Connector::class);
    $pdo = new D1Pdo('dsn', $connector);

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('failed')->andReturn(false);
    $response->shouldReceive('json')->with('success')->andReturn(true);
    $response->shouldReceive('json')->with('result')->andReturn([
        [
            'results' => [],
            'meta' => ['changes' => 0, 'last_row_id' => null],
        ],
    ]);

    $connector->shouldReceive('databaseQuery')
        ->once()
        ->with('INSERT INTO table (col1, col2) VALUES (?, ?)', ['value1', 'value2'], false)
        ->andReturn($response);

    $stmt = new D1PdoStatement($pdo, 'INSERT INTO table (col1, col2) VALUES (?, ?)');
    $stmt->execute(['value1', 'value2']);

    expect(true)->toBeTrue();
});

test('fetch returns correct row and advances cursor', function () {
    $connector = Mockery::mock(CloudflareD1Connector::class);
    $pdo = new D1Pdo('dsn', $connector);

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('failed')->andReturn(false);
    $response->shouldReceive('json')->with('success')->andReturn(true);
    $response->shouldReceive('json')->with('result')->andReturn([
        [
            'results' => [
                ['id' => 1, 'name' => 'A'],
                ['id' => 2, 'name' => 'B'],
            ],
            'meta' => ['changes' => 0],
        ],
    ]);

    $connector->shouldReceive('databaseQuery')->once()->andReturn($response);

    $stmt = new D1PdoStatement($pdo, 'SELECT * FROM users');
    $stmt->execute();

    expect($stmt->fetch())->toBe(['id' => 1, 'name' => 'A']);
    expect($stmt->fetch())->toBe(['id' => 2, 'name' => 'B']);
    expect($stmt->fetch())->toBeFalse();
});

test('fetchAll returns all remaining rows', function () {
    $connector = Mockery::mock(CloudflareD1Connector::class);
    $pdo = new D1Pdo('dsn', $connector);

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('failed')->andReturn(false);
    $response->shouldReceive('json')->with('success')->andReturn(true);
    $response->shouldReceive('json')->with('result')->andReturn([
        [
            'results' => [
                ['id' => 1, 'name' => 'A'],
                ['id' => 2, 'name' => 'B'],
                ['id' => 3, 'name' => 'C'],
            ],
            'meta' => ['changes' => 0],
        ],
    ]);

    $connector->shouldReceive('databaseQuery')->once()->andReturn($response);

    $stmt = new D1PdoStatement($pdo, 'SELECT * FROM users');
    $stmt->execute();

    // fetch 1 row
    $stmt->fetch();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    expect($rows)->toHaveCount(2);
    expect($rows[0])->toBe(['id' => 2, 'name' => 'B']);
    expect($rows[1])->toBe(['id' => 3, 'name' => 'C']);

    expect($stmt->fetch())->toBeFalse();
});

test('fetchColumn returns single column value', function () {
    $connector = Mockery::mock(CloudflareD1Connector::class);
    $pdo = new D1Pdo('dsn', $connector);

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('failed')->andReturn(false);
    $response->shouldReceive('json')->with('success')->andReturn(true);
    $response->shouldReceive('json')->with('result')->andReturn([
        [
            'results' => [
                ['count' => 100],
            ],
            'meta' => ['changes' => 0],
        ],
    ]);

    $connector->shouldReceive('databaseQuery')->once()->andReturn($response);

    $stmt = new D1PdoStatement($pdo, 'SELECT count(*) FROM users');
    $stmt->execute();

    expect($stmt->fetchColumn(0))->toBe(100);
    expect($stmt->fetchColumn(0))->toBeFalse(); // No more rows
});
