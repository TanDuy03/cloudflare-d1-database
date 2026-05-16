<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
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

test('d1:import succeeds with full import flow', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    $tmpFile = tempnam(sys_get_temp_dir(), 'd1test_');
    file_put_contents($tmpFile, 'CREATE TABLE test (id INTEGER PRIMARY KEY);');

    $callCount = 0;
    MockClient::global([
        D1ImportRequest::class => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return MockResponse::make([
                    'success' => true,
                    'errors' => [],
                    'result' => [
                        'upload_url' => 'https://fake-r2.cloudflare.com/upload',
                        'filename' => 'import-abc123.sql',
                        'at_bookmark' => 'bkmk_init',
                    ],
                ], 200);
            }
            if ($callCount === 2) {
                return MockResponse::make([
                    'success' => true,
                    'errors' => [],
                    'result' => ['at_bookmark' => 'bkmk_ingest'],
                ], 200);
            }

            return MockResponse::make([
                'success' => true,
                'errors' => [],
                'result' => [
                    'status' => 'complete',
                    'at_bookmark' => 'bkmk_done',
                    'messages' => ['Processing complete'],
                    'result' => [
                        'num_queries' => 5,
                        'meta' => ['duration' => 42, 'size_after' => 8192],
                    ],
                ],
            ], 200);
        },
    ]);

    Http::fake([
        'fake-r2.cloudflare.com/*' => Http::response('', 200),
    ]);

    $this->artisan('d1:import', ['file' => $tmpFile])
        ->expectsOutputToContain('Import completed successfully')
        ->assertSuccessful();

    unlink($tmpFile);
    MockClient::destroyGlobal();
});

test('d1:import fails when upload fails', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    $tmpFile = tempnam(sys_get_temp_dir(), 'd1test_');
    file_put_contents($tmpFile, 'CREATE TABLE test (id INTEGER);');

    MockClient::global([
        D1ImportRequest::class => MockResponse::make([
            'success' => true,
            'errors' => [],
            'result' => [
                'upload_url' => 'https://fake-r2.cloudflare.com/upload',
                'filename' => 'import-abc123.sql',
                'at_bookmark' => 'bkmk_init',
            ],
        ], 200),
    ]);

    Http::fake([
        'fake-r2.cloudflare.com/*' => Http::response('', 500),
    ]);

    $this->artisan('d1:import', ['file' => $tmpFile])
        ->expectsOutputToContain('Upload failed')
        ->assertFailed();

    unlink($tmpFile);
    MockClient::destroyGlobal();
});

test('d1:import fails when ingest returns error', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    $tmpFile = tempnam(sys_get_temp_dir(), 'd1test_');
    file_put_contents($tmpFile, 'CREATE TABLE test (id INTEGER);');

    $callCount = 0;
    MockClient::global([
        D1ImportRequest::class => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return MockResponse::make([
                    'success' => true,
                    'errors' => [],
                    'result' => [
                        'upload_url' => 'https://fake-r2.cloudflare.com/upload',
                        'filename' => 'import-abc123.sql',
                        'at_bookmark' => 'bkmk_init',
                    ],
                ], 200);
            }

            return MockResponse::make([
                'success' => false,
                'errors' => [['code' => 7500, 'message' => 'Ingest rejected']],
            ], 200);
        },
    ]);

    Http::fake([
        'fake-r2.cloudflare.com/*' => Http::response('', 200),
    ]);

    $this->artisan('d1:import', ['file' => $tmpFile])
        ->expectsOutputToContain('Ingest failed')
        ->assertFailed();

    unlink($tmpFile);
    MockClient::destroyGlobal();
});

test('d1:import fails when poll returns error status', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    $tmpFile = tempnam(sys_get_temp_dir(), 'd1test_');
    file_put_contents($tmpFile, 'CREATE TABLE test (id INTEGER);');

    $callCount = 0;
    MockClient::global([
        D1ImportRequest::class => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return MockResponse::make([
                    'success' => true,
                    'errors' => [],
                    'result' => [
                        'upload_url' => 'https://fake-r2.cloudflare.com/upload',
                        'filename' => 'import-abc123.sql',
                        'at_bookmark' => 'bkmk_init',
                    ],
                ], 200);
            }
            if ($callCount === 2) {
                return MockResponse::make([
                    'success' => true,
                    'errors' => [],
                    'result' => ['at_bookmark' => 'bkmk_ingest'],
                ], 200);
            }

            return MockResponse::make([
                'success' => true,
                'errors' => [],
                'result' => [
                    'status' => 'error',
                    'error' => 'SQL syntax error at line 5',
                    'at_bookmark' => 'bkmk_err',
                ],
            ], 200);
        },
    ]);

    Http::fake([
        'fake-r2.cloudflare.com/*' => Http::response('', 200),
    ]);

    $this->artisan('d1:import', ['file' => $tmpFile])
        ->expectsOutputToContain('Import error')
        ->assertFailed();

    unlink($tmpFile);
    MockClient::destroyGlobal();
});

test('d1:import fails when poll API returns failure', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    $tmpFile = tempnam(sys_get_temp_dir(), 'd1test_');
    file_put_contents($tmpFile, 'CREATE TABLE test (id INTEGER);');

    $callCount = 0;
    MockClient::global([
        D1ImportRequest::class => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return MockResponse::make([
                    'success' => true,
                    'errors' => [],
                    'result' => [
                        'upload_url' => 'https://fake-r2.cloudflare.com/upload',
                        'filename' => 'import-abc123.sql',
                        'at_bookmark' => 'bkmk_init',
                    ],
                ], 200);
            }
            if ($callCount === 2) {
                return MockResponse::make([
                    'success' => true,
                    'errors' => [],
                    'result' => ['at_bookmark' => 'bkmk_ingest'],
                ], 200);
            }

            return MockResponse::make([
                'success' => false,
                'errors' => [['code' => 500, 'message' => 'Poll API error']],
            ], 200);
        },
    ]);

    Http::fake([
        'fake-r2.cloudflare.com/*' => Http::response('', 200),
    ]);

    $this->artisan('d1:import', ['file' => $tmpFile])
        ->expectsOutputToContain('Poll failed')
        ->assertFailed();

    unlink($tmpFile);
    MockClient::destroyGlobal();
});

test('d1:import handles exception during import', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    $tmpFile = tempnam(sys_get_temp_dir(), 'd1test_');
    file_put_contents($tmpFile, 'CREATE TABLE test (id INTEGER);');

    // Make the connector throw an exception
    MockClient::global([
        D1ImportRequest::class => MockResponse::make([], 500),
    ]);

    Artisan::call('d1:import', ['file' => $tmpFile]);
    $output = Artisan::output();

    // Should catch the exception
    expect($output)->toContain('failed');

    unlink($tmpFile);
    MockClient::destroyGlobal();
});
