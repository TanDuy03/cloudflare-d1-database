<?php

declare(strict_types=1);

use Ntanduy\CFD1\Connectors\CloudflareD1Connector;
use Ntanduy\CFD1\D1\Requests\Rest\D1BatchQueryRequest;
use Ntanduy\CFD1\D1\Requests\Rest\D1DatabaseInfoRequest;
use Ntanduy\CFD1\D1\Requests\Rest\D1ExportRequest;
use Ntanduy\CFD1\D1\Requests\Rest\D1ImportRequest;
use Ntanduy\CFD1\D1\Requests\Rest\D1QueryRequest;
use Ntanduy\CFD1\D1\Requests\Rest\D1TimeTravelBookmarkRequest;
use Ntanduy\CFD1\D1\Requests\Rest\D1TimeTravelRestoreRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/*
|--------------------------------------------------------------------------
| CloudflareD1Connector – Full coverage tests
|--------------------------------------------------------------------------
|
| Tests all public methods of CloudflareD1Connector to achieve ≥95% coverage.
| Uses Saloon MockClient to intercept all HTTP requests.
|
*/

function makeD1Connector(array $options = []): CloudflareD1Connector
{
    return new CloudflareD1Connector(
        database: 'test-db-uuid',
        token: 'test-api-token',
        accountId: 'test-account-id',
        apiUrl: 'https://api.cloudflare.com/client/v4',
        options: array_merge([
            'retries' => 0,
            'retry_delay' => 1,
            'timeout' => 5,
            'connect_timeout' => 2,
        ], $options),
    );
}

function apiSuccess(array $result = []): array
{
    return [
        'success' => true,
        'errors' => [],
        'messages' => [],
        'result' => $result,
    ];
}

function apiError(string $message = 'Error', int $code = 7500): array
{
    return [
        'success' => false,
        'errors' => [['code' => $code, 'message' => $message]],
        'messages' => [],
        'result' => [],
    ];
}

// ─── Constructor ─────────────────────────────────────────────────────────

test('constructor stores database, token, accountId, and apiUrl', function () {
    $connector = new CloudflareD1Connector(
        database: 'my-db',
        token: 'my-token',
        accountId: 'my-account',
        apiUrl: 'https://custom-api.example.com/v4',
    );

    expect($connector->database)->toBe('my-db');
    expect($connector->getAccountId())->toBe('my-account');
    expect($connector->resolveBaseUrl())->toBe('https://custom-api.example.com/v4');
});

test('constructor uses default apiUrl when not specified', function () {
    $connector = new CloudflareD1Connector(
        database: 'db',
        token: 'tok',
        accountId: 'acc',
    );

    expect($connector->resolveBaseUrl())->toBe('https://api.cloudflare.com/client/v4');
});

test('constructor accepts null database', function () {
    $connector = new CloudflareD1Connector(
        database: null,
        token: 'tok',
        accountId: 'acc',
    );

    expect($connector->database)->toBeNull();
});

test('constructor passes options to parent for retries and timeouts', function () {
    $connector = new CloudflareD1Connector(
        database: 'db',
        token: 'tok',
        accountId: 'acc',
        options: [
            'retries' => 5,
            'retry_delay' => 200,
            'timeout' => 30,
            'connect_timeout' => 10,
        ],
    );

    // Verify via reflection since these are protected readonly
    $ref = new ReflectionClass($connector);

    $retries = $ref->getProperty('retries');
    expect($retries->getValue($connector))->toBe(5);

    $retryDelay = $ref->getProperty('retryDelay');
    expect($retryDelay->getValue($connector))->toBe(200);

    $timeout = $ref->getProperty('timeout');
    expect($timeout->getValue($connector))->toBe(30);

    $connectTimeout = $ref->getProperty('connectTimeout');
    expect($connectTimeout->getValue($connector))->toBe(10);
});

// ─── databaseQuery ───────────────────────────────────────────────────────

test('databaseQuery sends D1QueryRequest and returns response', function () {
    $connector = makeD1Connector();

    $mockClient = new MockClient([
        D1QueryRequest::class => MockResponse::make(apiSuccess([
            ['results' => [['id' => 1]], 'meta' => ['changes' => 0]],
        ]), 200),
    ]);
    $connector->withMockClient($mockClient);

    $response = $connector->databaseQuery('SELECT * FROM users', ['param1']);

    expect($response->status())->toBe(200);
    expect($response->json('success'))->toBeTrue();

    $mockClient->assertSent(D1QueryRequest::class);
});

test('databaseQuery with retry=true uses sendWithRetry', function () {
    $connector = makeD1Connector(['retries' => 1, 'retry_delay' => 1]);

    // First call returns 500, second returns 200 — proves retry happened
    $mockClient = new MockClient([
        MockResponse::make(apiError('Server error'), 500),
        MockResponse::make(apiSuccess([['results' => []]]), 200),
    ]);
    $connector->withMockClient($mockClient);

    $response = $connector->databaseQuery('SELECT 1', [], true);

    expect($response->status())->toBe(200);
    $mockClient->assertSentCount(2);
});

test('databaseQuery with retry=false uses send directly', function () {
    $connector = makeD1Connector();

    $mockClient = new MockClient([
        D1QueryRequest::class => MockResponse::make(apiError('Server error'), 500),
    ]);
    $connector->withMockClient($mockClient);

    // With retry=false, a 500 response is returned directly (no retry, no exception)
    $response = $connector->databaseQuery('SELECT 1', [], false);

    expect($response->status())->toBe(500);
    $mockClient->assertSentCount(1);
});

test('databaseQuery logs the query via query logger', function () {
    $connector = makeD1Connector();

    $mockClient = new MockClient([
        D1QueryRequest::class => MockResponse::make(apiSuccess([['results' => []]]), 200),
    ]);
    $connector->withMockClient($mockClient);

    $logged = null;
    $connector->setQueryLogger(function ($query, $params, $time, $success, $error) use (&$logged) {
        $logged = compact('query', 'params', 'success');
    });

    $connector->databaseQuery('SELECT ?', ['test']);

    expect($logged)->not->toBeNull();
    expect($logged['query'])->toBe('SELECT ?');
    expect($logged['params'])->toBe(['test']);
    expect($logged['success'])->toBeTrue();
});

// ─── databaseBatch ───────────────────────────────────────────────────────

test('databaseBatch sends D1BatchQueryRequest and returns response', function () {
    $connector = makeD1Connector();

    $mockClient = new MockClient([
        D1BatchQueryRequest::class => MockResponse::make(apiSuccess([
            ['results' => [], 'meta' => ['changes' => 1]],
            ['results' => [], 'meta' => ['changes' => 1]],
        ]), 200),
    ]);
    $connector->withMockClient($mockClient);

    $statements = [
        ['sql' => 'INSERT INTO users (name) VALUES (?)', 'params' => ['Alice']],
        ['sql' => 'INSERT INTO users (name) VALUES (?)', 'params' => ['Bob']],
    ];

    $response = $connector->databaseBatch($statements);

    expect($response->status())->toBe(200);
    expect($response->json('success'))->toBeTrue();

    $mockClient->assertSent(D1BatchQueryRequest::class);
});

test('databaseBatch with retry=true uses sendWithRetry', function () {
    $connector = makeD1Connector(['retries' => 1, 'retry_delay' => 1]);

    $mockClient = new MockClient([
        MockResponse::make(apiError('Server error'), 500),
        MockResponse::make(apiSuccess([['results' => []]]), 200),
    ]);
    $connector->withMockClient($mockClient);

    $response = $connector->databaseBatch([['sql' => 'SELECT 1', 'params' => []]], true);

    expect($response->status())->toBe(200);
    $mockClient->assertSentCount(2);
});

test('databaseBatch with retry=false uses send directly', function () {
    $connector = makeD1Connector();

    $mockClient = new MockClient([
        D1BatchQueryRequest::class => MockResponse::make(apiError('Server error'), 500),
    ]);
    $connector->withMockClient($mockClient);

    $response = $connector->databaseBatch([['sql' => 'SELECT 1', 'params' => []]], false);

    expect($response->status())->toBe(500);
    $mockClient->assertSentCount(1);
});

// ─── databaseInfo ────────────────────────────────────────────────────────

test('databaseInfo sends D1DatabaseInfoRequest and returns response', function () {
    $connector = makeD1Connector();

    $mockClient = new MockClient([
        D1DatabaseInfoRequest::class => MockResponse::make(apiSuccess([
            'name' => 'my-database',
            'uuid' => 'test-db-uuid',
            'file_size' => 4096,
            'num_tables' => 3,
        ]), 200),
    ]);
    $connector->withMockClient($mockClient);

    $response = $connector->databaseInfo();

    expect($response->status())->toBe(200);
    expect($response->json('result.name'))->toBe('my-database');

    $mockClient->assertSent(D1DatabaseInfoRequest::class);
});

test('databaseInfo returns error response on API failure', function () {
    $connector = makeD1Connector();

    $mockClient = new MockClient([
        D1DatabaseInfoRequest::class => MockResponse::make(apiError('Not found', 7501), 404),
    ]);
    $connector->withMockClient($mockClient);

    $response = $connector->databaseInfo();

    expect($response->status())->toBe(404);
    expect($response->json('success'))->toBeFalse();
});

// ─── databaseExport ──────────────────────────────────────────────────────

test('databaseExport sends D1ExportRequest without bookmark on first call', function () {
    $connector = makeD1Connector();

    $mockClient = new MockClient([
        D1ExportRequest::class => MockResponse::make(apiSuccess([
            'status' => 'active',
            'at_bookmark' => 'bkmk_123',
        ]), 200),
    ]);
    $connector->withMockClient($mockClient);

    $response = $connector->databaseExport();

    expect($response->status())->toBe(200);
    expect($response->json('result.status'))->toBe('active');

    $mockClient->assertSent(D1ExportRequest::class);
});

test('databaseExport sends D1ExportRequest with bookmark for polling', function () {
    $connector = makeD1Connector();

    $mockClient = new MockClient([
        D1ExportRequest::class => MockResponse::make(apiSuccess([
            'status' => 'complete',
            'at_bookmark' => 'bkmk_456',
            'result' => ['signed_url' => 'https://r2.example.com/dump.sql'],
        ]), 200),
    ]);
    $connector->withMockClient($mockClient);

    $response = $connector->databaseExport(currentBookmark: 'bkmk_123');

    expect($response->status())->toBe(200);
    expect($response->json('result.status'))->toBe('complete');
});

test('databaseExport passes noData option', function () {
    $connector = makeD1Connector();

    $mockClient = new MockClient([
        D1ExportRequest::class => MockResponse::make(apiSuccess([
            'status' => 'active',
        ]), 200),
    ]);
    $connector->withMockClient($mockClient);

    $response = $connector->databaseExport(noData: true);

    expect($response->status())->toBe(200);
    $mockClient->assertSent(D1ExportRequest::class);
});

test('databaseExport passes noSchema option', function () {
    $connector = makeD1Connector();

    $mockClient = new MockClient([
        D1ExportRequest::class => MockResponse::make(apiSuccess([
            'status' => 'active',
        ]), 200),
    ]);
    $connector->withMockClient($mockClient);

    $response = $connector->databaseExport(noSchema: true);

    expect($response->status())->toBe(200);
    $mockClient->assertSent(D1ExportRequest::class);
});

test('databaseExport passes tables filter', function () {
    $connector = makeD1Connector();

    $mockClient = new MockClient([
        D1ExportRequest::class => MockResponse::make(apiSuccess([
            'status' => 'active',
        ]), 200),
    ]);
    $connector->withMockClient($mockClient);

    $response = $connector->databaseExport(tables: ['users', 'posts']);

    expect($response->status())->toBe(200);
    $mockClient->assertSent(D1ExportRequest::class);
});

test('databaseExport does not retry (stateful operation)', function () {
    $connector = makeD1Connector(['retries' => 3]);

    // A 500 response should NOT be retried for export
    $mockClient = new MockClient([
        D1ExportRequest::class => MockResponse::make(apiError('Server error'), 500),
    ]);
    $connector->withMockClient($mockClient);

    $response = $connector->databaseExport();

    // Should return the 500 directly without retrying
    expect($response->status())->toBe(500);
    $mockClient->assertSentCount(1);
});

// ─── databaseImport ──────────────────────────────────────────────────────

test('databaseImport sends init action', function () {
    $connector = makeD1Connector();

    $mockClient = new MockClient([
        D1ImportRequest::class => MockResponse::make(apiSuccess([
            'upload_url' => 'https://r2.example.com/upload',
            'filename' => 'import-abc.sql',
            'at_bookmark' => 'bkmk_init',
        ]), 200),
    ]);
    $connector->withMockClient($mockClient);

    $response = $connector->databaseImport(action: 'init', etag: 'abc123');

    expect($response->status())->toBe(200);
    expect($response->json('result.upload_url'))->toBe('https://r2.example.com/upload');

    $mockClient->assertSent(D1ImportRequest::class);
});

test('databaseImport sends ingest action with filename', function () {
    $connector = makeD1Connector();

    $mockClient = new MockClient([
        D1ImportRequest::class => MockResponse::make(apiSuccess([
            'at_bookmark' => 'bkmk_ingest',
        ]), 200),
    ]);
    $connector->withMockClient($mockClient);

    $response = $connector->databaseImport(
        action: 'ingest',
        etag: 'abc123',
        filename: 'import-abc.sql',
    );

    expect($response->status())->toBe(200);
    $mockClient->assertSent(D1ImportRequest::class);
});

test('databaseImport sends poll action with bookmark', function () {
    $connector = makeD1Connector();

    $mockClient = new MockClient([
        D1ImportRequest::class => MockResponse::make(apiSuccess([
            'status' => 'complete',
            'at_bookmark' => 'bkmk_done',
            'result' => ['num_queries' => 10],
        ]), 200),
    ]);
    $connector->withMockClient($mockClient);

    $response = $connector->databaseImport(
        action: 'poll',
        currentBookmark: 'bkmk_ingest',
    );

    expect($response->status())->toBe(200);
    expect($response->json('result.status'))->toBe('complete');
});

test('databaseImport does not retry (stateful operation)', function () {
    $connector = makeD1Connector(['retries' => 3]);

    $mockClient = new MockClient([
        D1ImportRequest::class => MockResponse::make(apiError('Server error'), 500),
    ]);
    $connector->withMockClient($mockClient);

    $response = $connector->databaseImport(action: 'init', etag: 'abc');

    expect($response->status())->toBe(500);
    $mockClient->assertSentCount(1);
});

// ─── timeTravelBookmark ──────────────────────────────────────────────────

test('timeTravelBookmark sends request without timestamp', function () {
    $connector = makeD1Connector();

    $mockClient = new MockClient([
        D1TimeTravelBookmarkRequest::class => MockResponse::make(apiSuccess([
            'bookmark' => 'bkmk_current_abc',
        ]), 200),
    ]);
    $connector->withMockClient($mockClient);

    $response = $connector->timeTravelBookmark();

    expect($response->status())->toBe(200);
    expect($response->json('result.bookmark'))->toBe('bkmk_current_abc');

    $mockClient->assertSent(D1TimeTravelBookmarkRequest::class);
});

test('timeTravelBookmark sends request with timestamp', function () {
    $connector = makeD1Connector();

    $mockClient = new MockClient([
        D1TimeTravelBookmarkRequest::class => MockResponse::make(apiSuccess([
            'bookmark' => 'bkmk_at_timestamp',
        ]), 200),
    ]);
    $connector->withMockClient($mockClient);

    $response = $connector->timeTravelBookmark('2024-01-15T10:00:00Z');

    expect($response->status())->toBe(200);
    expect($response->json('result.bookmark'))->toBe('bkmk_at_timestamp');
});

test('timeTravelBookmark returns error on API failure', function () {
    $connector = makeD1Connector();

    $mockClient = new MockClient([
        D1TimeTravelBookmarkRequest::class => MockResponse::make(apiError('Database not found'), 404),
    ]);
    $connector->withMockClient($mockClient);

    $response = $connector->timeTravelBookmark();

    expect($response->status())->toBe(404);
    expect($response->json('success'))->toBeFalse();
});

// ─── timeTravelRestore ───────────────────────────────────────────────────

test('timeTravelRestore sends request with bookmark', function () {
    $connector = makeD1Connector();

    $mockClient = new MockClient([
        D1TimeTravelRestoreRequest::class => MockResponse::make(apiSuccess([
            'bookmark' => 'bkmk_new',
            'previous_bookmark' => 'bkmk_old',
            'message' => 'Database restored',
        ]), 200),
    ]);
    $connector->withMockClient($mockClient);

    $response = $connector->timeTravelRestore(bookmark: 'bkmk_target');

    expect($response->status())->toBe(200);
    expect($response->json('result.bookmark'))->toBe('bkmk_new');

    $mockClient->assertSent(D1TimeTravelRestoreRequest::class);
});

test('timeTravelRestore sends request with timestamp', function () {
    $connector = makeD1Connector();

    $mockClient = new MockClient([
        D1TimeTravelRestoreRequest::class => MockResponse::make(apiSuccess([
            'bookmark' => 'bkmk_restored',
            'message' => 'Database restored',
        ]), 200),
    ]);
    $connector->withMockClient($mockClient);

    $response = $connector->timeTravelRestore(timestamp: '2024-01-15T10:00:00Z');

    expect($response->status())->toBe(200);
    expect($response->json('result.bookmark'))->toBe('bkmk_restored');
});

test('timeTravelRestore sends request with both bookmark and timestamp', function () {
    $connector = makeD1Connector();

    $mockClient = new MockClient([
        D1TimeTravelRestoreRequest::class => MockResponse::make(apiSuccess([
            'bookmark' => 'bkmk_result',
        ]), 200),
    ]);
    $connector->withMockClient($mockClient);

    $response = $connector->timeTravelRestore(
        bookmark: 'bkmk_target',
        timestamp: '2024-01-15T10:00:00Z',
    );

    expect($response->status())->toBe(200);
});

test('timeTravelRestore returns error on API failure', function () {
    $connector = makeD1Connector();

    $mockClient = new MockClient([
        D1TimeTravelRestoreRequest::class => MockResponse::make(apiError('Invalid bookmark'), 400),
    ]);
    $connector->withMockClient($mockClient);

    $response = $connector->timeTravelRestore(bookmark: 'invalid');

    expect($response->status())->toBe(400);
    expect($response->json('success'))->toBeFalse();
});
