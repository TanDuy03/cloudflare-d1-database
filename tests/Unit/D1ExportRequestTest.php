<?php

declare(strict_types=1);

use Ntanduy\CFD1\Connectors\CloudflareD1Connector;
use Ntanduy\CFD1\D1\Requests\Rest\D1ExportRequest;

// ─── endpoint resolution ─────────────────────────────────────────────

test('D1ExportRequest resolves correct endpoint', function () {
    $connector = new CloudflareD1Connector(
        database: 'db-uuid-123',
        token: 'test-token',
        accountId: 'acc-456',
    );

    $request = new D1ExportRequest($connector, 'db-uuid-123');

    expect($request->resolveEndpoint())->toBe('/accounts/acc-456/d1/database/db-uuid-123/export');
});

// ─── default body (initial poll) ─────────────────────────────────────

test('D1ExportRequest default body contains polling format', function () {
    $connector = new CloudflareD1Connector(
        database: 'db-uuid-123',
        token: 'test-token',
        accountId: 'acc-456',
    );

    $request = new D1ExportRequest($connector, 'db-uuid-123');
    $body = $request->body()->all();

    expect($body)->toBe(['output_format' => 'polling']);
});

// ─── body with bookmark (subsequent poll) ────────────────────────────

test('D1ExportRequest includes current_bookmark when polling', function () {
    $connector = new CloudflareD1Connector(
        database: 'db-uuid-123',
        token: 'test-token',
        accountId: 'acc-456',
    );

    $request = new D1ExportRequest($connector, 'db-uuid-123', currentBookmark: 'bk_abc');
    $body = $request->body()->all();

    expect($body)->toHaveKey('output_format', 'polling');
    expect($body)->toHaveKey('current_bookmark', 'bk_abc');
});

// ─── no-data option ──────────────────────────────────────────────────

test('D1ExportRequest includes no_data flag in dump_options', function () {
    $connector = new CloudflareD1Connector(
        database: 'db-uuid-123',
        token: 'test-token',
        accountId: 'acc-456',
    );

    $request = new D1ExportRequest($connector, 'db-uuid-123', noData: true);
    $body = $request->body()->all();

    expect($body)->toHaveKey('dump_options');
    expect($body['dump_options'])->toHaveKey('no_data', true);
});

// ─── tables filter ───────────────────────────────────────────────────

test('D1ExportRequest includes no_schema flag in dump_options', function () {
    $connector = new CloudflareD1Connector(
        database: 'db-uuid-123',
        token: 'test-token',
        accountId: 'acc-456',
    );

    $request = new D1ExportRequest($connector, 'db-uuid-123', noSchema: true);
    $body = $request->body()->all();

    expect($body)->toHaveKey('dump_options');
    expect($body['dump_options'])->toHaveKey('no_schema', true);
});

test('D1ExportRequest includes tables filter in dump_options', function () {
    $connector = new CloudflareD1Connector(
        database: 'db-uuid-123',
        token: 'test-token',
        accountId: 'acc-456',
    );

    $request = new D1ExportRequest($connector, 'db-uuid-123', tables: ['users', 'posts']);
    $body = $request->body()->all();

    expect($body)->toHaveKey('dump_options');
    expect($body['dump_options'])->toHaveKey('tables', ['users', 'posts']);
});

// ─── no optional fields when not set ─────────────────────────────────

test('D1ExportRequest omits optional fields when not set', function () {
    $connector = new CloudflareD1Connector(
        database: 'db-uuid-123',
        token: 'test-token',
        accountId: 'acc-456',
    );

    $request = new D1ExportRequest($connector, 'db-uuid-123');
    $body = $request->body()->all();

    expect($body)->not->toHaveKey('current_bookmark');
    expect($body)->not->toHaveKey('dump_options');
});
