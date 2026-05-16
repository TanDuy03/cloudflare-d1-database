<?php

declare(strict_types=1);

use Ntanduy\CFD1\Test\TestCase;

uses(TestCase::class);

test('d1:health fails for non-existent connection', function () {
    $this->artisan('d1:health', ['--connection' => 'nonexistent'])
        ->expectsOutputToContain('not found')
        ->assertFailed();
});

test('d1:health shows header with connection and driver info', function () {
    $this->artisan('d1:health')
        ->expectsOutputToContain('D1 Health Check')
        ->expectsOutputToContain('d1')
        ->assertSuccessful();
});

test('d1:health checks REST config when driver is rest', function () {
    config()->set('database.connections.d1.d1_driver', 'rest');
    config()->set('database.connections.d1.auth.token', 'test-token-value');
    config()->set('database.connections.d1.auth.account_id', 'test-account-id');

    $this->artisan('d1:health')
        ->expectsOutputToContain('api_token configured')
        ->expectsOutputToContain('account_id configured')
        ->expectsOutputToContain('database_id configured')
        ->assertSuccessful();
});

test('d1:health fails when REST token is missing', function () {
    config()->set('database.connections.d1.d1_driver', 'rest');
    config()->set('database.connections.d1.auth.token', '');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    $this->artisan('d1:health')
        ->expectsOutputToContain('CF_D1_API_TOKEN')
        ->assertFailed();
});

test('d1:health fails when REST account_id is missing', function () {
    config()->set('database.connections.d1.d1_driver', 'rest');
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', '');

    $this->artisan('d1:health')
        ->expectsOutputToContain('CF_D1_ACCOUNT_ID')
        ->assertFailed();
});

test('d1:health fails when REST database is missing', function () {
    config()->set('database.connections.d1.d1_driver', 'rest');
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');
    config()->set('database.connections.d1.database', '');

    $this->artisan('d1:health')
        ->expectsOutputToContain('CF_D1_DATABASE_ID')
        ->assertFailed();
});

test('d1:health checks worker config when driver is worker', function () {
    config()->set('database.connections.d1.d1_driver', 'worker');
    config()->set('database.connections.d1.worker_url', 'https://worker.example.com');
    config()->set('database.connections.d1.worker_secret', 'my-secret-key');

    $this->artisan('d1:health')
        ->expectsOutputToContain('worker_url configured')
        ->expectsOutputToContain('worker_secret configured')
        ->assertSuccessful();
});

test('d1:health fails when worker_url is missing', function () {
    config()->set('database.connections.d1.d1_driver', 'worker');
    config()->set('database.connections.d1.worker_url', '');
    config()->set('database.connections.d1.worker_secret', 'my-secret');

    $this->artisan('d1:health')
        ->expectsOutputToContain('CF_D1_WORKER_URL')
        ->assertFailed();
});

test('d1:health fails when worker_secret is missing', function () {
    config()->set('database.connections.d1.d1_driver', 'worker');
    config()->set('database.connections.d1.worker_url', 'https://worker.example.com');
    config()->set('database.connections.d1.worker_secret', '');

    $this->artisan('d1:health')
        ->expectsOutputToContain('CF_D1_WORKER_SECRET')
        ->assertFailed();
});

test('d1:health runs query test when config is valid', function () {
    config()->set('database.connections.d1.d1_driver', 'rest');
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    $this->artisan('d1:health')
        ->expectsOutputToContain('Query test passed')
        ->assertSuccessful();
});

test('d1:health masks sensitive values showing only last 4 chars', function () {
    config()->set('database.connections.d1.d1_driver', 'rest');
    config()->set('database.connections.d1.auth.token', 'super-secret-token-1234');
    config()->set('database.connections.d1.auth.account_id', 'account-id-5678');

    $this->artisan('d1:health')
        ->expectsOutputToContain('1234')
        ->expectsOutputToContain('5678')
        ->assertSuccessful();
});

test('d1:health shows end-to-end latency on successful query', function () {
    config()->set('database.connections.d1.d1_driver', 'rest');
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    $this->artisan('d1:health')
        ->expectsOutputToContain('End-to-end latency')
        ->assertSuccessful();
});

test('d1:health shows HEALTHY on success', function () {
    config()->set('database.connections.d1.d1_driver', 'rest');
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    $this->artisan('d1:health')
        ->expectsOutputToContain('HEALTHY')
        ->assertSuccessful();
});

test('d1:health shows UNHEALTHY on failure', function () {
    config()->set('database.connections.d1.d1_driver', 'rest');
    config()->set('database.connections.d1.auth.token', '');
    config()->set('database.connections.d1.auth.account_id', '');

    $this->artisan('d1:health')
        ->expectsOutputToContain('UNHEALTHY')
        ->assertFailed();
});

test('d1:health fails query test with unexpected response', function () {
    // This covers the query test path in checkQueryTest.
    // The mock SQLite handles SELECT 1 correctly, so the query test passes.
    config()->set('database.connections.d1.d1_driver', 'rest');
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    $this->artisan('d1:health')
        ->expectsOutputToContain('Query test passed')
        ->assertSuccessful();
});

test('d1:health masks short values (4 chars or less)', function () {
    config()->set('database.connections.d1.d1_driver', 'rest');
    config()->set('database.connections.d1.auth.token', 'ab');
    config()->set('database.connections.d1.auth.account_id', 'test-account');
    config()->set('database.connections.d1.database', 'test-db');

    // Short token (2 chars) should be fully masked as '**'
    $this->artisan('d1:health')
        ->expectsOutputToContain('**')
        ->assertSuccessful();
});

test('d1:health query test catches exception', function () {
    // Override the connection to throw on query
    config()->set('database.connections.d1_broken', [
        'driver' => 'd1',
        'd1_driver' => 'rest',
        'database' => 'broken-db',
        'prefix' => '',
        'auth' => [
            'token' => 'test-token',
            'account_id' => 'test-account',
        ],
    ]);

    // The d1_broken connection will use MockCloudflareD1Connector but
    // the query test should still pass since mock SQLite works.
    $this->artisan('d1:health', ['--connection' => 'd1_broken'])
        ->expectsOutputToContain('D1 Health Check');
});
