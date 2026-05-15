<?php

declare(strict_types=1);

use Ntanduy\CFD1\Test\TestCase;

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
