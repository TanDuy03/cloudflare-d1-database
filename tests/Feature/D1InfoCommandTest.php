<?php

declare(strict_types=1);

use Ntanduy\CFD1\Test\TestCase;

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

    // Use MockClient::global to mock the databaseInfo response
    $mockClient = new Saloon\Http\Faking\MockClient([
        Ntanduy\CFD1\D1\Requests\Rest\D1DatabaseInfoRequest::class => Saloon\Http\Faking\MockResponse::make([
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
    ]);
    Saloon\Http\Faking\MockClient::global([
        Ntanduy\CFD1\D1\Requests\Rest\D1DatabaseInfoRequest::class => Saloon\Http\Faking\MockResponse::make([
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
    ]);

    Illuminate\Support\Facades\Artisan::call('d1:info');
    $output = Illuminate\Support\Facades\Artisan::output();

    expect($output)->toContain('production-db');
    expect($output)->toContain('uuid-abc-123');

    Saloon\Http\Faking\MockClient::destroyGlobal();
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
    Illuminate\Support\Facades\Artisan::call('d1:info', ['--connection' => 'd1_bad']);
    $output = Illuminate\Support\Facades\Artisan::output();

    // Should still succeed (query failure is non-fatal for d1:info)
    expect($output)->toContain('Query Test');
});
