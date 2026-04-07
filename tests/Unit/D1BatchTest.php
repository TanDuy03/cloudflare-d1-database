<?php

declare(strict_types=1);

use Ntanduy\CFD1\Connectors\CloudflareD1Connector;
use Ntanduy\CFD1\D1\D1Connection;
use Ntanduy\CFD1\D1\Exceptions\D1BatchException;
use Ntanduy\CFD1\D1\Requests\Rest\D1BatchQueryRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

function createBatchConnection(array $connectorOptions = []): D1Connection
{
    $connector = new CloudflareD1Connector(
        database: 'test-db-id',
        token: 'test-token',
        accountId: 'test-account-id',
        options: array_merge([
            'retries' => 0,
            'retry_delay' => 1,
            'timeout' => 5,
            'connect_timeout' => 2,
        ], $connectorOptions),
    );

    return new D1Connection($connector, [
        'database' => 'test-db',
        'prefix' => '',
        'name' => 'd1',
    ]);
}

// ─── successful batch ────────────────────────────────────────────────

test('batch returns array of result sets on success', function () {
    $connection = createBatchConnection();
    $connector = $connection->d1();

    $mockClient = new MockClient([
        D1BatchQueryRequest::class => MockResponse::make([
            'success' => true,
            'errors' => [],
            'result' => [
                ['results' => [['id' => 1, 'name' => 'Alice']], 'success' => true],
                ['results' => [], 'success' => true, 'meta' => ['changes' => 1]],
            ],
        ], 200),
    ]);
    $connector->withMockClient($mockClient);

    $results = $connection->batch([
        ['sql' => 'SELECT * FROM users WHERE id = ?', 'params' => [1]],
        ['sql' => 'UPDATE stats SET views = views + 1 WHERE id = ?', 'params' => [5]],
    ]);

    expect($results)->toHaveCount(2);
    expect($results[0]['results'][0]['name'])->toBe('Alice');
    expect($results[1]['meta']['changes'])->toBe(1);

    $mockClient->assertSentCount(1);
});

// ─── empty batch ─────────────────────────────────────────────────────

test('batch with empty array returns empty array without API call', function () {
    $connection = createBatchConnection();
    $connector = $connection->d1();

    $mockClient = new MockClient([
        D1BatchQueryRequest::class => MockResponse::make([], 200),
    ]);
    $connector->withMockClient($mockClient);

    $results = $connection->batch([]);

    expect($results)->toBe([]);
    $mockClient->assertSentCount(0);
});

// ─── API-level failure ───────────────────────────────────────────────

test('batch throws D1BatchException on API-level failure', function () {
    $connection = createBatchConnection();
    $connector = $connection->d1();

    $mockClient = new MockClient([
        D1BatchQueryRequest::class => MockResponse::make([
            'success' => false,
            'errors' => [['message' => 'Authentication required', 'code' => 10000]],
        ], 200),
    ]);
    $connector->withMockClient($mockClient);

    $connection->batch([
        ['sql' => 'SELECT 1'],
    ]);
})->throws(D1BatchException::class, 'Batch statement [0] failed: Authentication required');

// ─── individual statement failure ────────────────────────────────────

test('batch throws D1BatchException with correct index when a statement fails', function () {
    $connection = createBatchConnection();
    $connector = $connection->d1();

    $mockClient = new MockClient([
        D1BatchQueryRequest::class => MockResponse::make([
            'success' => true,
            'errors' => [],
            'result' => [
                ['results' => [['id' => 1]], 'success' => true],
                ['success' => false, 'error' => 'no such table: missing_table'],
            ],
        ], 200),
    ]);
    $connector->withMockClient($mockClient);

    $connection->batch([
        ['sql' => 'SELECT * FROM users', 'params' => []],
        ['sql' => 'SELECT * FROM missing_table', 'params' => []],
    ]);
})->throws(D1BatchException::class, 'Batch statement [1] failed: no such table: missing_table');

// ─── params normalization ────────────────────────────────────────────

test('batch normalizes statements without params key', function () {
    $connection = createBatchConnection();
    $connector = $connection->d1();

    $mockClient = new MockClient([
        D1BatchQueryRequest::class => MockResponse::make([
            'success' => true,
            'errors' => [],
            'result' => [
                ['results' => [['1' => 1]], 'success' => true],
            ],
        ], 200),
    ]);
    $connector->withMockClient($mockClient);

    // No 'params' key — should be normalized to empty array
    $results = $connection->batch([
        ['sql' => 'SELECT 1'],
    ]);

    expect($results)->toHaveCount(1);
    $mockClient->assertSentCount(1);
});
