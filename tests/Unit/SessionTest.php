<?php

declare(strict_types=1);

use Ntanduy\CFD1\Connectors\CloudflareD1Connector;
use Ntanduy\CFD1\Connectors\CloudflareWorkerConnector;
use Ntanduy\CFD1\D1\D1Connection;
use Ntanduy\CFD1\D1\Exceptions\D1UnsupportedFeatureException;
use Ntanduy\CFD1\D1\Requests\Worker\WorkerBatchRequest;
use Ntanduy\CFD1\D1\Requests\Worker\WorkerQueryRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

function createWorkerSessionConnection(): D1Connection
{
    $connector = new CloudflareWorkerConnector(
        workerUrl: 'https://d1-worker.example.com',
        workerSecret: 'test-secret',
        options: [
            'retries' => 0,
            'retry_delay' => 1,
            'timeout' => 5,
            'connect_timeout' => 2,
        ],
    );

    return new D1Connection($connector, [
        'database' => 'test-db',
        'prefix' => '',
        'name' => 'd1',
    ]);
}

function createRestSessionConnection(): D1Connection
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
        'prefix' => '',
        'name' => 'd1',
    ]);
}

// ─── Worker session enable/disable ───────────────────────────────────

test('withSession enables session on Worker connector', function () {
    $connection = createWorkerSessionConnection();
    $result = $connection->withSession('first-primary');

    expect($result)->toBe($connection);

    /** @var CloudflareWorkerConnector $connector */
    $connector = $connection->d1();
    expect($connector->hasActiveSession())->toBeTrue();
    expect($connector->getSessionParam())->toBe('first-primary');
});

test('withSession defaults to first-unconstrained', function () {
    $connection = createWorkerSessionConnection();
    $connection->withSession();

    /** @var CloudflareWorkerConnector $connector */
    $connector = $connection->d1();
    expect($connector->getSessionParam())->toBe('first-unconstrained');
});

test('endSession clears session state', function () {
    $connection = createWorkerSessionConnection();
    $connection->withSession('first-primary');
    $connection->endSession();

    /** @var CloudflareWorkerConnector $connector */
    $connector = $connection->d1();
    expect($connector->hasActiveSession())->toBeFalse();
    expect($connector->getSessionParam())->toBeNull();
    expect($connector->getBookmark())->toBeNull();
});

// ─── REST driver throws on session ───────────────────────────────────

test('withSession throws on REST driver', function () {
    $connection = createRestSessionConnection();
    $connection->withSession('first-primary');
})->throws(D1UnsupportedFeatureException::class, 'D1 Sessions are only available with the Worker driver');

// ─── getBookmark returns null without session ────────────────────────

test('getBookmark returns null on REST driver', function () {
    $connection = createRestSessionConnection();
    expect($connection->getBookmark())->toBeNull();
});

test('getBookmark returns null before any query', function () {
    $connection = createWorkerSessionConnection();
    $connection->withSession('first-primary');
    expect($connection->getBookmark())->toBeNull();
});

// ─── Bookmark tracking from responses ────────────────────────────────

test('Worker connector tracks bookmark from query response', function () {
    $connection = createWorkerSessionConnection();
    $connection->withSession('first-primary');

    /** @var CloudflareWorkerConnector $connector */
    $connector = $connection->d1();

    $mockClient = new MockClient([
        WorkerQueryRequest::class => MockResponse::make([
            'success' => true,
            'errors' => [],
            'messages' => [],
            'result' => [['results' => [['ok' => 1]], 'meta' => []]],
            'bookmark' => 'bk_abc123',
        ], 200),
    ]);
    $connector->withMockClient($mockClient);

    $connector->databaseQuery('SELECT 1 as ok', []);

    expect($connector->getBookmark())->toBe('bk_abc123');
    // Subsequent requests should use the bookmark instead of mode
    expect($connector->getSessionParam())->toBe('bk_abc123');
});

test('Worker connector tracks bookmark from batch response', function () {
    $connection = createWorkerSessionConnection();
    $connection->withSession('first-unconstrained');

    /** @var CloudflareWorkerConnector $connector */
    $connector = $connection->d1();

    $mockClient = new MockClient([
        WorkerBatchRequest::class => MockResponse::make([
            'success' => true,
            'errors' => [],
            'messages' => [],
            'result' => [['results' => [], 'success' => true]],
            'bookmark' => 'bk_def456',
        ], 200),
    ]);
    $connector->withMockClient($mockClient);

    $connector->databaseBatch([
        ['sql' => 'SELECT 1', 'params' => []],
    ]);

    expect($connector->getBookmark())->toBe('bk_def456');
});

// ─── Session param in request body ───────────────────────────────────

test('WorkerQueryRequest includes session in body when provided', function () {
    $connector = new CloudflareWorkerConnector(
        workerUrl: 'https://d1-worker.example.com',
        workerSecret: 'test-secret',
    );

    $request = new WorkerQueryRequest($connector, 'SELECT 1', [], 'first-primary');

    $body = $request->body()->all();

    expect($body)->toHaveKey('session', 'first-primary');
    expect($body)->toHaveKey('sql', 'SELECT 1');
});

test('WorkerQueryRequest omits session when null', function () {
    $connector = new CloudflareWorkerConnector(
        workerUrl: 'https://d1-worker.example.com',
        workerSecret: 'test-secret',
    );

    $request = new WorkerQueryRequest($connector, 'SELECT 1', []);

    $body = $request->body()->all();

    expect($body)->not->toHaveKey('session');
});

test('WorkerBatchRequest includes session in body when provided', function () {
    $connector = new CloudflareWorkerConnector(
        workerUrl: 'https://d1-worker.example.com',
        workerSecret: 'test-secret',
    );

    $request = new WorkerBatchRequest($connector, [
        ['sql' => 'SELECT 1', 'bindings' => []],
    ], 'bk_abc123');

    $body = $request->body()->all();

    expect($body)->toHaveKey('session', 'bk_abc123');
});

test('WorkerBatchRequest omits session when null', function () {
    $connector = new CloudflareWorkerConnector(
        workerUrl: 'https://d1-worker.example.com',
        workerSecret: 'test-secret',
    );

    $request = new WorkerBatchRequest($connector, [
        ['sql' => 'SELECT 1', 'bindings' => []],
    ]);

    $body = $request->body()->all();

    expect($body)->not->toHaveKey('session');
});

// ─── endSession on REST driver is a safe no-op ──────────────────────

test('endSession is safe no-op on REST driver', function () {
    $connection = createRestSessionConnection();
    $result = $connection->endSession();
    expect($result)->toBe($connection);
});
