<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
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

    Artisan::call('d1:info');
    $output = Artisan::output();

    expect($output)->toContain('Circuit Breaker');
    expect($output)->toContain('enabled (threshold: 3, cooldown: 60s)');
});

test('d1:info shows R/W splitting enabled when configured', function () {
    config()->set('database.connections.d1.read', ['session' => ['mode' => 'first-unconstrained']]);
    config()->set('database.connections.d1.write', ['session' => ['mode' => 'first-primary']]);
    config()->set('database.connections.d1.sticky', true);

    Artisan::call('d1:info');
    $output = Artisan::output();

    expect($output)->toContain('R/W Splitting');
    expect($output)->toContain('enabled (sticky)');
});

test('d1:info shows non-sticky R/W splitting', function () {
    config()->set('database.connections.d1.read', ['session' => ['mode' => 'first-unconstrained']]);
    config()->set('database.connections.d1.write', ['session' => ['mode' => 'first-primary']]);
    config()->set('database.connections.d1.sticky', false);

    Artisan::call('d1:info');
    $output = Artisan::output();

    expect($output)->toContain('R/W Splitting');
    expect($output)->toContain('non-sticky');
});

test('d1:info shows REST metadata when credentials are configured', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    // The mock connector will attempt databaseInfo() which will fail
    // because MockCloudflareD1Connector only mocks D1QueryRequest.
    // This tests the error handling path in fetchRestMetadata.
    Artisan::call('d1:info');
    $output = Artisan::output();

    // Should still show REST Metadata row (with failure detail)
    expect($output)->toContain('REST Metadata');
    // Query test should still pass via mock SQLite
    expect($output)->toContain('Query Test');
});

test('d1:info shows N/A when REST credentials are not configured', function () {
    config()->set('database.connections.d1.auth.token', '');
    config()->set('database.connections.d1.auth.account_id', '');

    Artisan::call('d1:info');
    $output = Artisan::output();

    expect($output)->toContain('REST Metadata');
    expect($output)->toContain('N/A');
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
    Artisan::call('d1:info');
    $output = Artisan::output();

    expect($output)->toContain('Query Test');
    expect($output)->toContain('SELECT 1');
});

test('d1:info handles REST metadata API failure gracefully', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    // MockCloudflareD1Connector only handles D1QueryRequest, so databaseInfo
    // will hit the mock client and get an unexpected response — testing the
    // error/exception handling path in fetchRestMetadata.
    Artisan::call('d1:info');
    $output = Artisan::output();

    // The command should still succeed (REST metadata failure is non-fatal)
    expect($output)->toContain('REST Metadata');
    expect($output)->toContain('Query Test');
});
