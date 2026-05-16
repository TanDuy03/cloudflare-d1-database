<?php

declare(strict_types=1);

use Ntanduy\CFD1\D1\Requests\Rest\D1ImportRequest;
use Ntanduy\CFD1\Test\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);

test('d1:import fails when file does not exist', function () {
    $this->artisan('d1:import', ['file' => '/nonexistent/file.sql'])
        ->expectsOutputToContain('File not found')
        ->assertFailed();
});

test('d1:import fails when connection does not exist', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'd1test_');
    file_put_contents($tmpFile, 'CREATE TABLE test (id INTEGER);');

    $this->artisan('d1:import', [
        'file' => $tmpFile,
        '--connection' => 'nonexistent',
    ])
        ->expectsOutputToContain('not found')
        ->assertFailed();

    unlink($tmpFile);
});

test('d1:import fails when file is empty', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'd1test_');
    file_put_contents($tmpFile, '');

    $this->artisan('d1:import', ['file' => $tmpFile])
        ->expectsOutputToContain('empty or unreadable')
        ->assertFailed();

    unlink($tmpFile);
});

test('d1:import fails when REST credentials are missing', function () {
    config()->set('database.connections.d1.auth.token', '');
    config()->set('database.connections.d1.auth.account_id', '');

    $tmpFile = tempnam(sys_get_temp_dir(), 'd1test_');
    file_put_contents($tmpFile, 'CREATE TABLE test (id INTEGER);');

    $this->artisan('d1:import', ['file' => $tmpFile])
        ->expectsOutputToContain('REST API credentials')
        ->assertFailed();

    unlink($tmpFile);
});

test('d1:import fails when init returns API error', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    $tmpFile = tempnam(sys_get_temp_dir(), 'd1test_');
    file_put_contents($tmpFile, 'CREATE TABLE test (id INTEGER);');

    MockClient::global([
        D1ImportRequest::class => MockResponse::make([
            'success' => false,
            'errors' => [['code' => 1000, 'message' => 'Authentication failed']],
        ], 200),
    ]);

    $this->artisan('d1:import', ['file' => $tmpFile])
        ->expectsOutputToContain('Init failed')
        ->assertFailed();

    unlink($tmpFile);
    MockClient::destroyGlobal();
});

test('d1:import fails when init returns no upload URL', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    $tmpFile = tempnam(sys_get_temp_dir(), 'd1test_');
    file_put_contents($tmpFile, 'CREATE TABLE test (id INTEGER);');

    MockClient::global([
        D1ImportRequest::class => MockResponse::make([
            'success' => true,
            'errors' => [],
            'result' => ['upload_url' => ''],
        ], 200),
    ]);

    $this->artisan('d1:import', ['file' => $tmpFile])
        ->expectsOutputToContain('no upload URL')
        ->assertFailed();

    unlink($tmpFile);
    MockClient::destroyGlobal();
});
