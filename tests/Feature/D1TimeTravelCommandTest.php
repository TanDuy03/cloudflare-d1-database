<?php

declare(strict_types=1);

use Ntanduy\CFD1\Test\TestCase;

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
