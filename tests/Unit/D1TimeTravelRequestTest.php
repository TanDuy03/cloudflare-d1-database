<?php

declare(strict_types=1);

use Ntanduy\CFD1\Connectors\CloudflareD1Connector;
use Ntanduy\CFD1\D1\Requests\Rest\D1TimeTravelBookmarkRequest;
use Ntanduy\CFD1\D1\Requests\Rest\D1TimeTravelRestoreRequest;

function createTimeTravelConnector(): CloudflareD1Connector
{
    return new CloudflareD1Connector(
        database: 'db-uuid-123',
        token: 'test-token',
        accountId: 'acc-456',
    );
}

// ─── Bookmark request ────────────────────────────────────────────────

test('D1TimeTravelBookmarkRequest resolves correct endpoint', function () {
    $request = new D1TimeTravelBookmarkRequest(createTimeTravelConnector(), 'db-uuid-123');

    expect($request->resolveEndpoint())
        ->toBe('/accounts/acc-456/d1/database/db-uuid-123/time_travel/bookmark');
});

test('D1TimeTravelBookmarkRequest uses GET method', function () {
    $request = new D1TimeTravelBookmarkRequest(createTimeTravelConnector(), 'db-uuid-123');

    $reflection = new ReflectionProperty($request, 'method');
    $reflection->setAccessible(true);

    expect($reflection->getValue($request)->value)->toBe('GET');
});

test('D1TimeTravelBookmarkRequest has no query when no timestamp', function () {
    $request = new D1TimeTravelBookmarkRequest(createTimeTravelConnector(), 'db-uuid-123');

    expect($request->query()->all())->toBe([]);
});

test('D1TimeTravelBookmarkRequest includes timestamp query param', function () {
    $request = new D1TimeTravelBookmarkRequest(
        createTimeTravelConnector(),
        'db-uuid-123',
        timestamp: '2024-01-15T10:30:00+00:00',
    );

    expect($request->query()->all())
        ->toHaveKey('timestamp', '2024-01-15T10:30:00+00:00');
});

// ─── Restore request ─────────────────────────────────────────────────

test('D1TimeTravelRestoreRequest resolves correct endpoint', function () {
    $request = new D1TimeTravelRestoreRequest(createTimeTravelConnector(), 'db-uuid-123');

    expect($request->resolveEndpoint())
        ->toBe('/accounts/acc-456/d1/database/db-uuid-123/time_travel/restore');
});

test('D1TimeTravelRestoreRequest uses POST method', function () {
    $request = new D1TimeTravelRestoreRequest(createTimeTravelConnector(), 'db-uuid-123');

    $reflection = new ReflectionProperty($request, 'method');
    $reflection->setAccessible(true);

    expect($reflection->getValue($request)->value)->toBe('POST');
});

test('D1TimeTravelRestoreRequest includes bookmark query param', function () {
    $request = new D1TimeTravelRestoreRequest(
        createTimeTravelConnector(),
        'db-uuid-123',
        bookmark: '00000085-0000024c-00004c6d-abc123',
    );

    $query = $request->query()->all();

    expect($query)->toHaveKey('bookmark', '00000085-0000024c-00004c6d-abc123');
    expect($query)->not->toHaveKey('timestamp');
});

test('D1TimeTravelRestoreRequest includes timestamp query param', function () {
    $request = new D1TimeTravelRestoreRequest(
        createTimeTravelConnector(),
        'db-uuid-123',
        timestamp: '2024-01-15T10:30:00+00:00',
    );

    $query = $request->query()->all();

    expect($query)->toHaveKey('timestamp', '2024-01-15T10:30:00+00:00');
    expect($query)->not->toHaveKey('bookmark');
});
