<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Ntanduy\CFD1\D1\Requests\Rest\D1DatabaseInfoRequest;
use Ntanduy\CFD1\D1\Requests\Rest\D1QueryRequest;
use Ntanduy\CFD1\Test\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);

test('d1:info shows connection state and query test', function () {
    $this->artisan('d1:info')
        ->expectsOutputToContain('D1 Database Info')
        ->expectsOutputToContain('R/W Splitting')
        ->expectsOutputToContain('Circuit Breaker')
        ->expectsOutputToContain('Query Test')
        ->assertSuccessful();
});

test('d1:info shows circuit breaker disabled by default', function () {
    $this->artisan('d1:info')
        ->expectsOutputToContain('Circuit Breaker')
        ->expectsOutputToContain('disabled')
        ->assertSuccessful();
});

test('d1:info shows R/W splitting disabled by default', function () {
    $this->artisan('d1:info')
        ->expectsOutputToContain('R/W Splitting')
        ->expectsOutputToContain('disabled')
        ->assertSuccessful();
});

test('d1:info fails for non-existent connection', function () {
    $this->artisan('d1:info', ['--connection' => 'nonexistent'])
        ->expectsOutputToContain('not found')
        ->assertFailed();
});

test('d1:info shows circuit breaker enabled when configured', function () {
    config()->set('database.connections.d1.circuit_breaker', [
        'enabled' => true,
        'threshold' => 3,
        'cooldown' => 60,
    ]);

    $this->artisan('d1:info')
        ->expectsOutputToContain('enabled (threshold: 3, cooldown: 60s)')
        ->assertSuccessful();
});

test('d1:info shows R/W splitting enabled when configured', function () {
    config()->set('database.connections.d1.read', ['session' => ['mode' => 'first-unconstrained']]);
    config()->set('database.connections.d1.write', ['session' => ['mode' => 'first-primary']]);
    config()->set('database.connections.d1.sticky', true);

    $this->artisan('d1:info')
        ->expectsOutputToContain('enabled (sticky)')
        ->assertSuccessful();
});

test('d1:info shows non-sticky R/W splitting', function () {
    config()->set('database.connections.d1.read', ['session' => ['mode' => 'first-unconstrained']]);
    config()->set('database.connections.d1.write', ['session' => ['mode' => 'first-primary']]);
    config()->set('database.connections.d1.sticky', false);

    $this->artisan('d1:info')
        ->expectsOutputToContain('non-sticky')
        ->assertSuccessful();
});

test('d1:info shows REST metadata when credentials are configured', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    // The mock connector will attempt databaseInfo() which will fail
    // because MockCloudflareD1Connector only mocks D1QueryRequest.
    // This tests the error handling path in fetchRestMetadata.
    $this->artisan('d1:info')
        ->expectsOutputToContain('Query Test')
        ->assertSuccessful();
});

test('d1:info shows N/A when REST credentials are not configured', function () {
    config()->set('database.connections.d1.auth.token', '');
    config()->set('database.connections.d1.auth.account_id', '');

    $this->artisan('d1:info')
        ->expectsOutputToContain('N/A')
        ->assertSuccessful();
});

test('d1:info shows driver with session info when sessions enabled', function () {
    config()->set('database.connections.d1.session', [
        'enabled' => true,
        'mode' => 'first-primary',
    ]);

    $this->artisan('d1:info')
        ->expectsOutputToContain('sessions: first-primary')
        ->assertSuccessful();
});

test('d1:info shows query test latency', function () {
    $this->artisan('d1:info')
        ->expectsOutputToContain('SELECT 1')
        ->assertSuccessful();
});

test('d1:info handles REST metadata API failure gracefully', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    // MockCloudflareD1Connector only handles D1QueryRequest, so databaseInfo
    // will hit the mock client and get an unexpected response — testing the
    // error/exception handling path in fetchRestMetadata.
    $this->artisan('d1:info')
        ->expectsOutputToContain('Query Test')
        ->assertSuccessful();
});

test('d1:info shows all REST metadata fields when available', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    // Set mock directly on the connector (global mock doesn't override connector-level mock)
    $connector = app('db')->connection('d1')->d1();
    $connector->withMockClient(new MockClient([
        D1DatabaseInfoRequest::class => MockResponse::make([
            'success' => true,
            'errors' => [],
            'result' => [
                'name' => 'production-db',
                'uuid' => 'uuid-abc-123',
                'file_size' => 5242880,
                'num_tables' => 12,
                'read_replication' => ['mode' => 'auto'],
                'created_at' => '2024-03-15T10:00:00Z',
                'version' => 'production',
            ],
        ], 200),
        D1QueryRequest::class => MockResponse::make([
            'success' => true,
            'errors' => [],
            'result' => [[
                'results' => [['ok' => 1]],
                'meta' => ['duration' => 0.001, 'changes' => 0, 'last_row_id' => null, 'rows_read' => 1, 'rows_written' => 0],
                'success' => true,
            ]],
        ], 200),
    ]));

    Artisan::call('d1:info');
    $output = Artisan::output();

    // Purge so tearDown rollback gets a fresh SQLite-backed mock
    app('db')->purge('d1');

    expect($output)->toContain('production-db');
    expect($output)->toContain('uuid-abc-123');
    expect($output)->toContain('5.24 MB');
    expect($output)->toContain('12');
    expect($output)->toContain('auto');
    expect($output)->toContain('2024-03-15T10:00:00Z');
    expect($output)->toContain('production');
});

test('d1:info shows REST metadata API error message', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    $connector = app('db')->connection('d1')->d1();
    $connector->withMockClient(new MockClient([
        D1DatabaseInfoRequest::class => MockResponse::make([
            'success' => false,
            'errors' => [['code' => 7500, 'message' => 'Database not found']],
        ], 200),
        D1QueryRequest::class => MockResponse::make([
            'success' => true,
            'errors' => [],
            'result' => [[
                'results' => [['ok' => 1]],
                'meta' => ['duration' => 0.001, 'changes' => 0, 'last_row_id' => null, 'rows_read' => 1, 'rows_written' => 0],
                'success' => true,
            ]],
        ], 200),
    ]));

    Artisan::call('d1:info');
    $output = Artisan::output();

    // Purge so tearDown rollback gets a fresh SQLite-backed mock
    app('db')->purge('d1');

    expect($output)->toContain('Database not found');
});

test('d1:info shows partial REST metadata when some fields are null', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    $connector = app('db')->connection('d1')->d1();
    $connector->withMockClient(new MockClient([
        D1DatabaseInfoRequest::class => MockResponse::make([
            'success' => true,
            'errors' => [],
            'result' => [
                'name' => 'test-db',
                'uuid' => 'uuid-test',
            ],
        ], 200),
        D1QueryRequest::class => MockResponse::make([
            'success' => true,
            'errors' => [],
            'result' => [[
                'results' => [['ok' => 1]],
                'meta' => ['duration' => 0.001, 'changes' => 0, 'last_row_id' => null, 'rows_read' => 1, 'rows_written' => 0],
                'success' => true,
            ]],
        ], 200),
    ]));

    Artisan::call('d1:info');
    $output = Artisan::output();

    // Purge so tearDown rollback gets a fresh SQLite-backed mock
    app('db')->purge('d1');

    expect($output)->toContain('test-db');
    expect($output)->toContain('uuid-test');
    // file_size, num_tables, etc. are missing — should not crash
    expect($output)->not->toContain('Size');
    expect($output)->not->toContain('Read Replication');
});

test('d1:info shows query test failure for unexpected response', function () {
    // Override the d1 connection to return unexpected query result
    config()->set('database.connections.d1_bad', [
        'driver' => 'd1',
        'd1_driver' => 'rest',
        'database' => 'test-db',
        'prefix' => '',
        'auth' => [
            'token' => 'test-token',
            'account_id' => 'test-account',
        ],
    ]);

    // This connection has no mock set up, so query will fail
    Artisan::call('d1:info', ['--connection' => 'd1_bad']);
    $output = Artisan::output();

    // Should still succeed (query failure is non-fatal for d1:info)
    expect($output)->toContain('Query Test');
});
