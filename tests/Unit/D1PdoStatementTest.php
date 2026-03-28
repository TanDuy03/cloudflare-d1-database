<?php

declare(strict_types=1);

use Ntanduy\CFD1\Connectors\CloudflareD1Connector;
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

// ── bindValue type branches ────────────────────────────────────────────

test('bindValue casts PARAM_BOOL to boolean', function () {
    $connector = Mockery::mock(CloudflareD1Connector::class);
    $pdo = new D1Pdo('dsn', $connector);

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('failed')->andReturn(false);
    $response->shouldReceive('json')->with('success')->andReturn(true);
    $response->shouldReceive('json')->with('result')->andReturn([
        ['results' => [], 'meta' => ['changes' => 0, 'last_row_id' => null]],
    ]);

    $connector->shouldReceive('databaseQuery')
        ->once()
        ->with('INSERT INTO t (active) VALUES (?)', [true], false)
        ->andReturn($response);

    $stmt = new D1PdoStatement($pdo, 'INSERT INTO t (active) VALUES (?)');
    $stmt->bindValue(1, 1, PDO::PARAM_BOOL);
    $stmt->execute();

    expect(true)->toBeTrue();
});

test('bindValue casts PARAM_INT to integer', function () {
    $connector = Mockery::mock(CloudflareD1Connector::class);
    $pdo = new D1Pdo('dsn', $connector);

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('failed')->andReturn(false);
    $response->shouldReceive('json')->with('success')->andReturn(true);
    $response->shouldReceive('json')->with('result')->andReturn([
        ['results' => [], 'meta' => ['changes' => 0, 'last_row_id' => null]],
    ]);

    $connector->shouldReceive('databaseQuery')
        ->once()
        ->with('INSERT INTO t (age) VALUES (?)', [42], false)
        ->andReturn($response);

    $stmt = new D1PdoStatement($pdo, 'INSERT INTO t (age) VALUES (?)');
    $stmt->bindValue(1, '42', PDO::PARAM_INT);
    $stmt->execute();

    expect(true)->toBeTrue();
});

test('bindValue casts PARAM_NULL to null', function () {
    $connector = Mockery::mock(CloudflareD1Connector::class);
    $pdo = new D1Pdo('dsn', $connector);

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('failed')->andReturn(false);
    $response->shouldReceive('json')->with('success')->andReturn(true);
    $response->shouldReceive('json')->with('result')->andReturn([
        ['results' => [], 'meta' => ['changes' => 0, 'last_row_id' => null]],
    ]);

    $connector->shouldReceive('databaseQuery')
        ->once()
        ->with('INSERT INTO t (col) VALUES (?)', [null], false)
        ->andReturn($response);

    $stmt = new D1PdoStatement($pdo, 'INSERT INTO t (col) VALUES (?)');
    $stmt->bindValue(1, 'anything', PDO::PARAM_NULL);
    $stmt->execute();

    expect(true)->toBeTrue();
});

test('bindValue passes through unknown type as-is', function () {
    $connector = Mockery::mock(CloudflareD1Connector::class);
    $pdo = new D1Pdo('dsn', $connector);

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('failed')->andReturn(false);
    $response->shouldReceive('json')->with('success')->andReturn(true);
    $response->shouldReceive('json')->with('result')->andReturn([
        ['results' => [], 'meta' => ['changes' => 0, 'last_row_id' => null]],
    ]);

    $connector->shouldReceive('databaseQuery')
        ->once()
        ->with('INSERT INTO t (col) VALUES (?)', ['raw'], false)
        ->andReturn($response);

    $stmt = new D1PdoStatement($pdo, 'INSERT INTO t (col) VALUES (?)');
    $stmt->bindValue(1, 'raw', 999);
    $stmt->execute();

    expect(true)->toBeTrue();
});

// ── convertLOBToString (via PARAM_LOB) ─────────────────────────────────

test('bindValue PARAM_LOB converts resource stream to string', function () {
    $connector = Mockery::mock(CloudflareD1Connector::class);
    $pdo = new D1Pdo('dsn', $connector);

    $stream = fopen('php://memory', 'r+');
    fwrite($stream, 'binary-data');
    rewind($stream);

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('failed')->andReturn(false);
    $response->shouldReceive('json')->with('success')->andReturn(true);
    $response->shouldReceive('json')->with('result')->andReturn([
        ['results' => [], 'meta' => ['changes' => 0, 'last_row_id' => null]],
    ]);

    $connector->shouldReceive('databaseQuery')
        ->once()
        ->with('INSERT INTO t (data) VALUES (?)', ['binary-data'], false)
        ->andReturn($response);

    $stmt = new D1PdoStatement($pdo, 'INSERT INTO t (data) VALUES (?)');
    $stmt->bindValue(1, $stream, PDO::PARAM_LOB);
    $stmt->execute();

    fclose($stream);

    expect(true)->toBeTrue();
});

test('bindValue PARAM_LOB casts non-resource to string', function () {
    $connector = Mockery::mock(CloudflareD1Connector::class);
    $pdo = new D1Pdo('dsn', $connector);

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('failed')->andReturn(false);
    $response->shouldReceive('json')->with('success')->andReturn(true);
    $response->shouldReceive('json')->with('result')->andReturn([
        ['results' => [], 'meta' => ['changes' => 0, 'last_row_id' => null]],
    ]);

    $connector->shouldReceive('databaseQuery')
        ->once()
        ->with('INSERT INTO t (data) VALUES (?)', ['12345'], false)
        ->andReturn($response);

    $stmt = new D1PdoStatement($pdo, 'INSERT INTO t (data) VALUES (?)');
    $stmt->bindValue(1, 12345, PDO::PARAM_LOB);
    $stmt->execute();

    expect(true)->toBeTrue();
});

// ── execute returns false in ERRMODE_SILENT ────────────────────────────

test('execute returns false on failure when ERRMODE_SILENT', function () {
    $connector = Mockery::mock(CloudflareD1Connector::class);
    $pdo = new D1Pdo('dsn', $connector);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('failed')->andReturn(true);
    $response->shouldReceive('json')->with('errors.0.code')->andReturn(1000);
    $response->shouldReceive('json')->with('errors.0.message', 'Unknown error')->andReturn('some error');

    $connector->shouldReceive('databaseQuery')->once()->andReturn($response);

    $stmt = new D1PdoStatement($pdo, 'SELECT * FROM users');
    $result = $stmt->execute();

    expect($result)->toBeFalse();
});

// ── fetch cursor orientation branches ──────────────────────────────────

test('fetch with FETCH_ORI_ABS sets cursor to absolute offset', function () {
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

    // Skip to index 2 (third row) using absolute offset
    $row = $stmt->fetch(PDO::FETCH_ASSOC, PDO::FETCH_ORI_ABS, 2);
    expect($row)->toBe(['id' => 3, 'name' => 'C']);
});

test('fetch with FETCH_ORI_REL adjusts cursor by relative offset', function () {
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

    // Fetch first row normally (cursor moves to 1)
    $stmt->fetch();

    // Now use FETCH_ORI_REL +1 → cursor becomes 1+1=2, fetches row at index 2
    $row = $stmt->fetch(PDO::FETCH_ASSOC, PDO::FETCH_ORI_REL, 1);
    expect($row)->toBe(['id' => 3, 'name' => 'C']);
});

// ── formatRow FETCH_BOTH branch ────────────────────────────────────────

test('fetch with FETCH_BOTH returns merged associative and numeric arrays', function () {
    $connector = Mockery::mock(CloudflareD1Connector::class);
    $pdo = new D1Pdo('dsn', $connector);

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('failed')->andReturn(false);
    $response->shouldReceive('json')->with('success')->andReturn(true);
    $response->shouldReceive('json')->with('result')->andReturn([
        [
            'results' => [
                ['id' => 1, 'name' => 'Alice'],
            ],
            'meta' => ['changes' => 0],
        ],
    ]);

    $connector->shouldReceive('databaseQuery')->once()->andReturn($response);

    $stmt = new D1PdoStatement($pdo, 'SELECT * FROM users');
    $stmt->execute();

    $row = $stmt->fetch(PDO::FETCH_BOTH);

    expect($row)->toBe(['id' => 1, 'name' => 'Alice', 0 => 1, 1 => 'Alice']);
});
