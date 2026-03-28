<?php

declare(strict_types=1);

use Ntanduy\CFD1\CloudflareD1Connector;
use Ntanduy\CFD1\D1\Requests\Rest\D1QueryRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/*
|--------------------------------------------------------------------------
| CloudflareD1Connector – setQueryLogger & logging callback coverage
|--------------------------------------------------------------------------
*/

function makeLoggerConnector(array $options = []): CloudflareD1Connector
{
    return new CloudflareD1Connector(
        database: 'test-db-id',
        token: 'test-token',
        accountId: 'test-account-id',
        apiUrl: 'https://api.cloudflare.com/client/v4',
        options: array_merge([
            'retries' => 0,       // no retries – keep tests fast & deterministic
            'retry_delay' => 1,
            'timeout' => 5,
            'connect_timeout' => 2,
        ], $options),
    );
}

function successBody(): array
{
    return [
        'success' => true,
        'errors' => [],
        'messages' => [],
        'result' => [['results' => [['1' => 1]], 'success' => true]],
    ];
}

function failureBody(int|string $code = 1000, string $message = 'SQL error'): array
{
    return [
        'success' => false,
        'errors' => [['code' => $code, 'message' => $message]],
        'messages' => [],
        'result' => [],
    ];
}

// ── setQueryLogger ─────────────────────────────────────────────────────

it('setQueryLogger accepts a closure and returns the same connector instance', function () {
    $connector = makeLoggerConnector();

    $returned = $connector->setQueryLogger(function () {});

    expect($returned)->toBe($connector);
});

it('setQueryLogger accepts null to clear the logger', function () {
    $connector = makeLoggerConnector();

    $connector->setQueryLogger(function () {});
    $returned = $connector->setQueryLogger(null);

    expect($returned)->toBe($connector);
});

// ── Successful query logging ───────────────────────────────────────────

it('invokes the logger with success=true and error=null on a successful query', function () {
    $connector = makeLoggerConnector();

    $mockClient = new MockClient([
        D1QueryRequest::class => MockResponse::make(successBody(), 200),
    ]);
    $connector->withMockClient($mockClient);

    $logged = null;
    $connector->setQueryLogger(function (string $query, array $params, float $time, bool $success, ?array $error) use (&$logged) {
        $logged = compact('query', 'params', 'time', 'success', 'error');
    });

    $connector->databaseQuery('SELECT 1', ['param1']);

    expect($logged)->not->toBeNull()
        ->and($logged['query'])->toBe('SELECT 1')
        ->and($logged['params'])->toBe(['param1'])
        ->and($logged['time'])->toBeGreaterThan(0)
        ->and($logged['success'])->toBeTrue()
        ->and($logged['error'])->toBeNull();
});

// ── Failed query logging (JSON success=false) ──────────────────────────

it('invokes the logger with success=false and populated error on a failed API response', function () {
    $connector = makeLoggerConnector();

    $mockClient = new MockClient([
        D1QueryRequest::class => MockResponse::make(failureBody(1000, 'near "INVALID": syntax error'), 200),
    ]);
    $connector->withMockClient($mockClient);

    $logged = null;
    $connector->setQueryLogger(function (string $query, array $params, float $time, bool $success, ?array $error) use (&$logged) {
        $logged = compact('query', 'params', 'time', 'success', 'error');
    });

    $connector->databaseQuery('INVALID SQL', []);

    expect($logged)->not->toBeNull()
        ->and($logged['success'])->toBeFalse()
        ->and($logged['error'])->toBeArray()
        ->and($logged['error']['code'])->toBe(1000)
        ->and($logged['error']['message'])->toBe('near "INVALID": syntax error')
        ->and($logged['error']['status'])->toBe(200);
});

// ── Failed query logging (HTTP 500) ────────────────────────────────────

it('invokes the logger with success=false and error on HTTP 500 response', function () {
    $connector = makeLoggerConnector();

    $mockClient = new MockClient([
        D1QueryRequest::class => MockResponse::make(failureBody(7500, 'Internal Server Error'), 500),
    ]);
    $connector->withMockClient($mockClient);

    $logged = null;
    $connector->setQueryLogger(function (string $query, array $params, float $time, bool $success, ?array $error) use (&$logged) {
        $logged = compact('query', 'params', 'time', 'success', 'error');
    });

    $connector->databaseQuery('SELECT 1', [], false);

    expect($logged)->not->toBeNull()
        ->and($logged['success'])->toBeFalse()
        ->and($logged['error'])->toBeArray()
        ->and($logged['error']['code'])->toBe(7500)
        ->and($logged['error']['message'])->toBe('Internal Server Error')
        ->and($logged['error']['status'])->toBe(500);
});

// ── No logger set — no crash ───────────────────────────────────────────

it('does not crash when no query logger is set', function () {
    $connector = makeLoggerConnector();

    $mockClient = new MockClient([
        D1QueryRequest::class => MockResponse::make(successBody(), 200),
    ]);
    $connector->withMockClient($mockClient);

    // No setQueryLogger call — should run without errors
    $response = $connector->databaseQuery('SELECT 1', []);

    expect($response->status())->toBe(200);
});
