<?php

declare(strict_types=1);

use Ntanduy\CFD1\Connectors\CloudflareD1Connector;
use Ntanduy\CFD1\D1\Requests\Rest\D1ImportRequest;

function createImportConnector(): CloudflareD1Connector
{
    return new CloudflareD1Connector(
        database: 'db-uuid-123',
        token: 'test-token',
        accountId: 'acc-456',
    );
}

// ─── Endpoint ────────────────────────────────────────────────────────

test('D1ImportRequest resolves correct endpoint', function () {
    $request = new D1ImportRequest(createImportConnector(), 'db-uuid-123');

    expect($request->resolveEndpoint())
        ->toBe('/accounts/acc-456/d1/database/db-uuid-123/import');
});

// ─── Init action ─────────────────────────────────────────────────────

test('D1ImportRequest init action includes etag', function () {
    $request = new D1ImportRequest(
        createImportConnector(),
        'db-uuid-123',
        action: 'init',
        etag: 'abc123md5hash',
    );

    $body = $request->body()->all();

    expect($body)->toHaveKey('action', 'init');
    expect($body)->toHaveKey('etag', 'abc123md5hash');
    expect($body)->not->toHaveKey('filename');
    expect($body)->not->toHaveKey('current_bookmark');
});

// ─── Ingest action ───────────────────────────────────────────────────

test('D1ImportRequest ingest action includes etag and filename', function () {
    $request = new D1ImportRequest(
        createImportConnector(),
        'db-uuid-123',
        action: 'ingest',
        etag: 'abc123md5hash',
        filename: 'import-file-001.sql',
    );

    $body = $request->body()->all();

    expect($body)->toHaveKey('action', 'ingest');
    expect($body)->toHaveKey('etag', 'abc123md5hash');
    expect($body)->toHaveKey('filename', 'import-file-001.sql');
    expect($body)->not->toHaveKey('current_bookmark');
});

// ─── Poll action ─────────────────────────────────────────────────────

test('D1ImportRequest poll action includes current_bookmark', function () {
    $request = new D1ImportRequest(
        createImportConnector(),
        'db-uuid-123',
        action: 'poll',
        currentBookmark: 'bk_abc123',
    );

    $body = $request->body()->all();

    expect($body)->toHaveKey('action', 'poll');
    expect($body)->toHaveKey('current_bookmark', 'bk_abc123');
    expect($body)->not->toHaveKey('etag');
    expect($body)->not->toHaveKey('filename');
});

// ─── Uses POST method ────────────────────────────────────────────────

test('D1ImportRequest uses POST method', function () {
    $request = new D1ImportRequest(createImportConnector(), 'db-uuid-123');

    $reflection = new ReflectionProperty($request, 'method');
    $reflection->setAccessible(true);

    expect($reflection->getValue($request)->value)->toBe('POST');
});
