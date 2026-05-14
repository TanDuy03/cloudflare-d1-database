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
