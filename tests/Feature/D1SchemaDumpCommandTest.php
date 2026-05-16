<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Ntanduy\CFD1\D1\Requests\Rest\D1ExportRequest;
use Ntanduy\CFD1\Test\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);

test('d1:schema-dump fails for non-existent connection', function () {
    $this->artisan('d1:schema-dump', ['--connection' => 'nonexistent'])
        ->expectsOutputToContain('not found')
        ->assertFailed();
});

test('d1:schema-dump fails when REST credentials are missing', function () {
    config()->set('database.connections.d1.auth.token', '');
    config()->set('database.connections.d1.auth.account_id', '');

    $this->artisan('d1:schema-dump')
        ->expectsOutputToContain('REST API credentials')
        ->assertFailed();
});

test('d1:schema-dump fails when token is missing', function () {
    config()->set('database.connections.d1.auth.token', '');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    $this->artisan('d1:schema-dump')
        ->expectsOutputToContain('REST API credentials')
        ->assertFailed();
});

test('d1:schema-dump fails when account_id is missing', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', '');

    $this->artisan('d1:schema-dump')
        ->expectsOutputToContain('REST API credentials')
        ->assertFailed();
});

test('d1:schema-dump fails when database is missing', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');
    config()->set('database.connections.d1.database', '');

    $this->artisan('d1:schema-dump')
        ->expectsOutputToContain('REST API credentials')
        ->assertFailed();
});

test('d1:schema-dump shows starting info', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    MockClient::global([
        D1ExportRequest::class => MockResponse::make([
            'success' => false,
            'errors' => [['code' => 500, 'message' => 'Export unavailable']],
        ], 200),
    ]);

    $this->artisan('d1:schema-dump')
        ->expectsOutputToContain('Starting D1 database export')
        ->expectsOutputToContain('schema + data')
        ->assertFailed();

    MockClient::destroyGlobal();
});

test('d1:schema-dump shows schema only mode', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    MockClient::global([
        D1ExportRequest::class => MockResponse::make([
            'success' => false,
            'errors' => [['code' => 500, 'message' => 'Export unavailable']],
        ], 200),
    ]);

    $this->artisan('d1:schema-dump', ['--no-data' => true])
        ->expectsOutputToContain('schema only')
        ->assertFailed();

    MockClient::destroyGlobal();
});

test('d1:schema-dump fails when export API returns error', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    MockClient::global([
        D1ExportRequest::class => MockResponse::make([
            'success' => false,
            'errors' => [['code' => 7500, 'message' => 'Database locked']],
        ], 200),
    ]);

    $this->artisan('d1:schema-dump')
        ->expectsOutputToContain('Database locked')
        ->assertFailed();

    MockClient::destroyGlobal();
});

test('d1:schema-dump fails when export status is error', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    MockClient::global([
        D1ExportRequest::class => MockResponse::make([
            'success' => true,
            'errors' => [],
            'result' => [
                'status' => 'error',
                'error' => 'Internal export failure',
                'at_bookmark' => 'bkmk_123',
            ],
        ], 200),
    ]);

    $this->artisan('d1:schema-dump')
        ->expectsOutputToContain('Internal export failure')
        ->assertFailed();

    MockClient::destroyGlobal();
});

test('d1:schema-dump fails when export completes without signed URL', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    MockClient::global([
        D1ExportRequest::class => MockResponse::make([
            'success' => true,
            'errors' => [],
            'result' => [
                'status' => 'complete',
                'at_bookmark' => 'bkmk_123',
                'result' => [
                    'signed_url' => '',
                ],
            ],
        ], 200),
    ]);

    $this->artisan('d1:schema-dump')
        ->expectsOutputToContain('no download URL')
        ->assertFailed();

    MockClient::destroyGlobal();
});

test('d1:schema-dump succeeds with complete export flow', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    $sqlDump = "CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT);\nINSERT INTO users VALUES (1, 'Test');";
    $outputPath = sys_get_temp_dir().'/d1-test-schema-dump.sql';

    // Mock the export request to return complete immediately
    MockClient::global([
        D1ExportRequest::class => MockResponse::make([
            'success' => true,
            'errors' => [],
            'result' => [
                'status' => 'complete',
                'at_bookmark' => 'bkmk_123',
                'messages' => ['Exporting tables...'],
                'result' => [
                    'signed_url' => 'https://fake-r2.cloudflare.com/export.sql',
                ],
            ],
        ], 200),
    ]);

    // Mock the HTTP download
    Http::fake([
        'fake-r2.cloudflare.com/*' => Http::response($sqlDump, 200),
    ]);

    $this->artisan('d1:schema-dump', ['--path' => $outputPath])
        ->expectsOutputToContain('Export complete')
        ->expectsOutputToContain('Schema dump saved to')
        ->assertSuccessful();

    // Verify file was written
    expect(file_exists($outputPath))->toBeTrue();
    expect(file_get_contents($outputPath))->toBe($sqlDump);

    // Cleanup
    unlink($outputPath);
    MockClient::destroyGlobal();
});

test('d1:schema-dump handles exception gracefully', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    // Test the API error path with a failed response body
    MockClient::global([
        D1ExportRequest::class => MockResponse::make([
            'success' => false,
            'errors' => [['code' => 500, 'message' => 'Service unavailable']],
        ], 200),
    ]);

    $this->artisan('d1:schema-dump')
        ->expectsOutputToContain('Service unavailable')
        ->assertFailed();

    MockClient::destroyGlobal();
});

test('d1:schema-dump download failure returns error', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    MockClient::global([
        D1ExportRequest::class => MockResponse::make([
            'success' => true,
            'errors' => [],
            'result' => [
                'status' => 'complete',
                'at_bookmark' => 'bkmk_123',
                'result' => [
                    'signed_url' => 'https://fake-r2.cloudflare.com/dump.sql',
                ],
            ],
        ], 200),
    ]);

    // Mock the HTTP download to fail — retry(3, 2000) will throw after retries
    Http::fake([
        'fake-r2.cloudflare.com/*' => Http::response('', 500),
    ]);

    $this->artisan('d1:schema-dump')
        ->expectsOutputToContain('Failed to download dump')
        ->assertFailed();

    MockClient::destroyGlobal();
});

test('d1:schema-dump uses default output path when no --path given', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    $sqlDump = "CREATE TABLE users (id INTEGER PRIMARY KEY);\n";

    MockClient::global([
        D1ExportRequest::class => MockResponse::make([
            'success' => true,
            'errors' => [],
            'result' => [
                'status' => 'complete',
                'at_bookmark' => 'bkmk_123',
                'result' => [
                    'signed_url' => 'https://fake-r2.cloudflare.com/dump.sql',
                ],
            ],
        ], 200),
    ]);

    Http::fake([
        'fake-r2.cloudflare.com/*' => Http::response($sqlDump, 200),
    ]);

    $this->artisan('d1:schema-dump')
        ->expectsOutputToContain('Schema dump saved to')
        ->assertSuccessful();

    // Check the default path
    $defaultPath = database_path('schema/d1-schema.sql');
    expect(file_exists($defaultPath))->toBeTrue();
    expect(file_get_contents($defaultPath))->toBe($sqlDump);

    // Cleanup
    @unlink($defaultPath);
    @rmdir(database_path('schema'));

    MockClient::destroyGlobal();
});

test('d1:schema-dump with --prune deletes migration files', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    $sqlDump = "CREATE TABLE users (id INTEGER PRIMARY KEY);\n";
    $outputPath = sys_get_temp_dir().'/d1-schema-test.sql';

    // Create a fake migration file
    $migrationPath = database_path('migrations');
    if (!is_dir($migrationPath)) {
        mkdir($migrationPath, 0755, true);
    }
    $fakeMigration = $migrationPath.'/2024_01_01_000000_create_test_table.php';
    file_put_contents($fakeMigration, '<?php // test migration');

    MockClient::global([
        D1ExportRequest::class => MockResponse::make([
            'success' => true,
            'errors' => [],
            'result' => [
                'status' => 'complete',
                'at_bookmark' => 'bkmk_123',
                'result' => [
                    'signed_url' => 'https://fake-r2.cloudflare.com/dump.sql',
                ],
            ],
        ], 200),
    ]);

    Http::fake([
        'fake-r2.cloudflare.com/*' => Http::response($sqlDump, 200),
    ]);

    $this->artisan('d1:schema-dump', [
        '--path' => $outputPath,
        '--prune' => true,
    ])
        ->expectsOutputToContain('Pruned')
        ->assertSuccessful();

    expect(file_exists($fakeMigration))->toBeFalse();

    // Cleanup
    @unlink($outputPath);

    MockClient::destroyGlobal();
});

test('d1:schema-dump with --prune and no migrations shows message', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    $sqlDump = "CREATE TABLE users (id INTEGER PRIMARY KEY);\n";
    $outputPath = sys_get_temp_dir().'/d1-schema-test2.sql';

    // Ensure migrations directory exists but is empty
    $migrationPath = database_path('migrations');
    if (!is_dir($migrationPath)) {
        mkdir($migrationPath, 0755, true);
    }
    // Remove any .php files
    foreach (glob($migrationPath.'/*.php') as $f) {
        unlink($f);
    }

    MockClient::global([
        D1ExportRequest::class => MockResponse::make([
            'success' => true,
            'errors' => [],
            'result' => [
                'status' => 'complete',
                'at_bookmark' => 'bkmk_123',
                'result' => [
                    'signed_url' => 'https://fake-r2.cloudflare.com/dump.sql',
                ],
            ],
        ], 200),
    ]);

    Http::fake([
        'fake-r2.cloudflare.com/*' => Http::response($sqlDump, 200),
    ]);

    $this->artisan('d1:schema-dump', [
        '--path' => $outputPath,
        '--prune' => true,
    ])
        ->expectsOutputToContain('No migration files to prune')
        ->assertSuccessful();

    // Cleanup
    @unlink($outputPath);

    MockClient::destroyGlobal();
});

test('d1:schema-dump polling shows progress messages', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    $sqlDump = "CREATE TABLE users (id INTEGER PRIMARY KEY);\n";
    $outputPath = sys_get_temp_dir().'/d1-schema-poll-test.sql';

    $callCount = 0;
    MockClient::global([
        D1ExportRequest::class => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return MockResponse::make([
                    'success' => true,
                    'errors' => [],
                    'result' => [
                        'status' => 'active',
                        'at_bookmark' => 'bkmk_1',
                        'messages' => ['Exporting tables...'],
                    ],
                ], 200);
            }

            return MockResponse::make([
                'success' => true,
                'errors' => [],
                'result' => [
                    'status' => 'complete',
                    'at_bookmark' => 'bkmk_2',
                    'result' => [
                        'signed_url' => 'https://fake-r2.cloudflare.com/dump.sql',
                    ],
                ],
            ], 200);
        },
    ]);

    Http::fake([
        'fake-r2.cloudflare.com/*' => Http::response($sqlDump, 200),
    ]);

    $this->artisan('d1:schema-dump', ['--path' => $outputPath])
        ->expectsOutputToContain('Polling')
        ->expectsOutputToContain('Schema dump saved to')
        ->assertSuccessful();

    @unlink($outputPath);
    MockClient::destroyGlobal();
});
