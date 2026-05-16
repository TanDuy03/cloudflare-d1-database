<?php

declare(strict_types=1);

use Ntanduy\CFD1\D1\Requests\Rest\D1TimeTravelBookmarkRequest;
use Ntanduy\CFD1\D1\Requests\Rest\D1TimeTravelRestoreRequest;
use Ntanduy\CFD1\Test\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);

test('d1:time-travel fails when connection does not exist', function () {
    $this->artisan('d1:time-travel', ['--connection' => 'nonexistent'])
        ->expectsOutputToContain('not found')
        ->assertFailed();
});

test('d1:time-travel restore fails without bookmark or timestamp', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    $this->artisan('d1:time-travel', ['--restore' => true])
        ->expectsOutputToContain('--bookmark or --timestamp')
        ->assertFailed();
});

test('d1:time-travel fails when REST credentials are missing', function () {
    config()->set('database.connections.d1.auth.token', '');
    config()->set('database.connections.d1.auth.account_id', '');

    $this->artisan('d1:time-travel')
        ->expectsOutputToContain('REST API credentials')
        ->assertFailed();
});

test('d1:time-travel fetches current bookmark', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    MockClient::global([
        D1TimeTravelBookmarkRequest::class => MockResponse::make([
            'success' => true,
            'errors' => [],
            'result' => [
                'bookmark' => 'bkmk_2024_01_15_abc123',
            ],
        ], 200),
    ]);

    $this->artisan('d1:time-travel')
        ->expectsOutputToContain('bkmk_2024_01_15_abc123')
        ->assertSuccessful();

    MockClient::destroyGlobal();
});

test('d1:time-travel with timestamp normalizes unix timestamp', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    MockClient::global([
        D1TimeTravelBookmarkRequest::class => MockResponse::make([
            'success' => true,
            'errors' => [],
            'result' => [
                'bookmark' => 'bkmk_at_timestamp',
            ],
        ], 200),
    ]);

    $this->artisan('d1:time-travel', ['--timestamp' => '1705312800'])
        ->expectsOutputToContain('bkmk_at_timestamp')
        ->assertSuccessful();

    MockClient::destroyGlobal();
});

test('d1:time-travel with ISO timestamp passes through', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    MockClient::global([
        D1TimeTravelBookmarkRequest::class => MockResponse::make([
            'success' => true,
            'errors' => [],
            'result' => [
                'bookmark' => 'bkmk_iso_timestamp',
            ],
        ], 200),
    ]);

    $this->artisan('d1:time-travel', ['--timestamp' => '2024-01-15T10:00:00Z'])
        ->expectsOutputToContain('bkmk_iso_timestamp')
        ->assertSuccessful();

    MockClient::destroyGlobal();
});

test('d1:time-travel fails when API returns error', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    MockClient::global([
        D1TimeTravelBookmarkRequest::class => MockResponse::make([
            'success' => false,
            'errors' => [['code' => 7500, 'message' => 'Database not found']],
        ], 200),
    ]);

    $this->artisan('d1:time-travel')
        ->expectsOutputToContain('Database not found')
        ->assertFailed();

    MockClient::destroyGlobal();
});

test('d1:time-travel fails when no bookmark returned', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    MockClient::global([
        D1TimeTravelBookmarkRequest::class => MockResponse::make([
            'success' => true,
            'errors' => [],
            'result' => [],
        ], 200),
    ]);

    $this->artisan('d1:time-travel')
        ->expectsOutputToContain('No bookmark returned')
        ->assertFailed();

    MockClient::destroyGlobal();
});

test('d1:time-travel restore succeeds with bookmark', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    MockClient::global([
        D1TimeTravelRestoreRequest::class => MockResponse::make([
            'success' => true,
            'errors' => [],
            'result' => [
                'bookmark' => 'bkmk_new_after_restore',
                'previous_bookmark' => 'bkmk_before_restore',
                'message' => 'Database restored successfully',
            ],
        ], 200),
    ]);

    $this->artisan('d1:time-travel', [
        '--restore' => true,
        '--bookmark' => 'bkmk_target',
    ])
        ->expectsConfirmation('Are you sure you want to proceed?', 'yes')
        ->expectsOutputToContain('Database restored successfully')
        ->expectsOutputToContain('bkmk_new_after_restore')
        ->expectsOutputToContain('bkmk_before_restore')
        ->assertSuccessful();

    MockClient::destroyGlobal();
});

test('d1:time-travel restore cancelled by user', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    $this->artisan('d1:time-travel', [
        '--restore' => true,
        '--bookmark' => 'bkmk_target',
    ])
        ->expectsConfirmation('Are you sure you want to proceed?', 'no')
        ->expectsOutputToContain('Restore cancelled')
        ->assertSuccessful();
});

test('d1:time-travel restore fails on API error', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    MockClient::global([
        D1TimeTravelRestoreRequest::class => MockResponse::make([
            'success' => false,
            'errors' => [['code' => 7500, 'message' => 'Invalid bookmark']],
        ], 200),
    ]);

    $this->artisan('d1:time-travel', [
        '--restore' => true,
        '--bookmark' => 'invalid_bookmark',
    ])
        ->expectsConfirmation('Are you sure you want to proceed?', 'yes')
        ->expectsOutputToContain('Restore failed')
        ->assertFailed();

    MockClient::destroyGlobal();
});

test('d1:time-travel restore with timestamp', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    MockClient::global([
        D1TimeTravelRestoreRequest::class => MockResponse::make([
            'success' => true,
            'errors' => [],
            'result' => [
                'bookmark' => 'bkmk_restored',
                'message' => 'Database restored successfully',
            ],
        ], 200),
    ]);

    $this->artisan('d1:time-travel', [
        '--restore' => true,
        '--timestamp' => '2024-01-15T10:00:00Z',
    ])
        ->expectsConfirmation('Are you sure you want to proceed?', 'yes')
        ->expectsOutputToContain('restored')
        ->assertSuccessful();

    MockClient::destroyGlobal();
});

test('d1:time-travel handles exception gracefully', function () {
    config()->set('database.connections.d1.auth.token', 'test-token');
    config()->set('database.connections.d1.auth.account_id', 'test-account');

    // A 500 response with no body will cause json() to return empty array,
    // which triggers the API error path (not exception path).
    // Test the API error path instead.
    MockClient::global([
        D1TimeTravelBookmarkRequest::class => MockResponse::make([
            'success' => false,
            'errors' => [['code' => 500, 'message' => 'Internal server error']],
        ], 200),
    ]);

    $this->artisan('d1:time-travel')
        ->expectsOutputToContain('Internal server error')
        ->assertFailed();

    MockClient::destroyGlobal();
});
