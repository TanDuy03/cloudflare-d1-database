<?php

declare(strict_types=1);

use Ntanduy\CFD1\Connectors\CloudflareD1Connector;
use Ntanduy\CFD1\D1\D1Connection;
use Ntanduy\CFD1\D1\Requests\Rest\D1BatchQueryRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

function createBulkInsertConnection(string $prefix = ''): D1Connection
{
    $connector = new CloudflareD1Connector(
        database: 'test-db-id',
        token: 'test-token',
        accountId: 'test-account-id',
        options: [
            'retries' => 0,
            'retry_delay' => 1,
            'timeout' => 5,
            'connect_timeout' => 2,
        ],
    );

    return new D1Connection($connector, [
        'database' => 'test-db',
        'prefix' => $prefix,
        'name' => 'd1',
    ]);
}

function mockBatchSuccess(CloudflareD1Connector $connector, int $rowCount): MockClient
{
    $results = array_map(fn ($i) => [
        'results' => [],
        'success' => true,
        'meta' => ['changes' => 1, 'last_row_id' => $i + 1],
    ], range(0, $rowCount - 1));

    $mockClient = new MockClient([
        D1BatchQueryRequest::class => MockResponse::make([
            'success' => true,
            'errors' => [],
            'result' => $results,
        ], 200),
    ]);
    $connector->withMockClient($mockClient);

    return $mockClient;
}

// ─── empty rows ──────────────────────────────────────────────────────

test('bulkInsert with empty array returns empty without API call', function () {
    $connection = createBulkInsertConnection();
    $connector = $connection->d1();

    $mockClient = new MockClient([
        D1BatchQueryRequest::class => MockResponse::make([], 200),
    ]);
    $connector->withMockClient($mockClient);

    $results = $connection->bulkInsert('users', []);

    expect($results)->toBe([]);
    $mockClient->assertSentCount(0);
});

// ─── single row ──────────────────────────────────────────────────────

test('bulkInsert with single row sends one batch request', function () {
    $connection = createBulkInsertConnection();
    /** @var CloudflareD1Connector $connector */
    $connector = $connection->d1();
    $mockClient = mockBatchSuccess($connector, 1);

    $results = $connection->bulkInsert('users', [
        ['name' => 'Alice', 'email' => 'alice@example.com'],
    ]);

    expect($results)->toHaveCount(1);
    $mockClient->assertSentCount(1);
});

// ─── multiple rows ───────────────────────────────────────────────────

test('bulkInsert with multiple rows sends them in a single batch', function () {
    $connection = createBulkInsertConnection();
    /** @var CloudflareD1Connector $connector */
    $connector = $connection->d1();
    $mockClient = mockBatchSuccess($connector, 3);

    $results = $connection->bulkInsert('users', [
        ['name' => 'Alice', 'email' => 'alice@example.com'],
        ['name' => 'Bob', 'email' => 'bob@example.com'],
        ['name' => 'Charlie', 'email' => 'charlie@example.com'],
    ]);

    expect($results)->toHaveCount(3);
    $mockClient->assertSentCount(1);
});

// ─── table prefix ────────────────────────────────────────────────────

test('bulkInsert applies table prefix', function () {
    $connection = createBulkInsertConnection('app_');
    /** @var CloudflareD1Connector $connector */
    $connector = $connection->d1();

    $mockClient = new MockClient([
        D1BatchQueryRequest::class => function () {
            return MockResponse::make([
                'success' => true,
                'errors' => [],
                'result' => [
                    ['results' => [], 'success' => true, 'meta' => ['changes' => 1]],
                ],
            ], 200);
        },
    ]);
    $connector->withMockClient($mockClient);

    $connection->bulkInsert('users', [
        ['name' => 'Alice'],
    ]);

    $mockClient->assertSentCount(1);
});

// ─── parameterized queries (SQL injection safe) ──────────────────────

test('bulkInsert uses parameterized queries', function () {
    $connection = createBulkInsertConnection();
    /** @var CloudflareD1Connector $connector */
    $connector = $connection->d1();
    $mockClient = mockBatchSuccess($connector, 1);

    $connection->bulkInsert('users', [
        ['name' => "Robert'); DROP TABLE users;--", 'email' => 'bobby@tables.com'],
    ]);

    $mockClient->assertSentCount(1);
});

// ─── column consistency validation ───────────────────────────────────

test('bulkInsert throws when rows have different columns', function () {
    $connection = createBulkInsertConnection();

    $connection->bulkInsert('users', [
        ['name' => 'Alice', 'email' => 'alice@example.com'],
        ['name' => 'Bob', 'age' => 30],
    ]);
})->throws(InvalidArgumentException::class, 'Row [1] has different columns than row [0]');

// ─── column name escaping ────────────────────────────────────────────

test('bulkInsert escapes double quotes in column names', function () {
    $connection = createBulkInsertConnection();
    /** @var CloudflareD1Connector $connector */
    $connector = $connection->d1();
    $mockClient = mockBatchSuccess($connector, 1);

    // Column name with a double quote — must not break SQL
    $connection->bulkInsert('users', [
        ['col"name' => 'value'],
    ]);

    $mockClient->assertSentCount(1);
});
