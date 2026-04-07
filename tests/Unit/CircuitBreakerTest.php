<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Ntanduy\CFD1\CircuitBreaker;
use Ntanduy\CFD1\Connectors\CloudflareD1Connector;
use Ntanduy\CFD1\D1\Exceptions\CircuitBreakerOpenException;
use Ntanduy\CFD1\D1\Exceptions\D1Exception;
use Ntanduy\CFD1\D1\Requests\Rest\D1QueryRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

function makeArrayCache(): Repository
{
    return new Repository(new ArrayStore);
}

function createCBConnector(array $options = []): CloudflareD1Connector
{
    return new CloudflareD1Connector(
        database: 'test-db-id',
        token: 'test-token',
        accountId: 'test-account-id',
        options: array_merge([
            'retries' => 0,   // No retries — isolate circuit breaker behavior
            'retry_delay' => 1,
            'timeout' => 5,
            'connect_timeout' => 2,
        ], $options),
    );
}

function createCBRequest(CloudflareD1Connector $connector): D1QueryRequest
{
    return new D1QueryRequest($connector, 'test-db-id', 'SELECT 1', []);
}

// ─── 1. Opens after threshold consecutive failures ───────────────────

test('circuit opens after threshold consecutive failures', function () {
    $cb = new CircuitBreaker('test', threshold: 3, cooldown: 60, cache: makeArrayCache());

    expect($cb->getState())->toBe('closed');

    $cb->recordFailure();
    $cb->recordFailure();
    expect($cb->getState())->toBe('closed'); // Still below threshold

    $cb->recordFailure();
    expect($cb->getState())->toBe('open'); // Threshold reached
    expect($cb->getFailureCount())->toBe(3);
});

// ─── 2. Rejects immediately when OPEN (no HTTP call) ────────────────

test('rejects request immediately when circuit is open', function () {
    $connector = createCBConnector();
    $cb = new CircuitBreaker('test', threshold: 2, cooldown: 60, cache: makeArrayCache());

    // Trip the circuit
    $cb->recordFailure();
    $cb->recordFailure();

    $connector->setCircuitBreaker($cb);

    $request = createCBRequest($connector);

    // No MockClient needed — request should never reach HTTP layer
    $connector->sendWithRetry($request);
})->throws(CircuitBreakerOpenException::class, 'Circuit breaker is OPEN after 2 consecutive failures');

// ─── 3. Transitions to HALF_OPEN after cooldown ─────────────────────

test('transitions to half_open after cooldown elapses', function () {
    $cb = new CircuitBreaker('test', threshold: 2, cooldown: 1, cache: makeArrayCache());

    // Trip the circuit
    $cb->recordFailure();
    $cb->recordFailure();
    expect($cb->getState())->toBe('open');

    // Wait for cooldown to elapse
    sleep(2);

    expect($cb->getState())->toBe('half_open');
    expect($cb->allowRequest())->toBeTrue(); // Should allow probe request
});

// ─── 4. Closes again after successful probe ─────────────────────────

test('closes circuit after successful probe in half_open state', function () {
    $cb = new CircuitBreaker('test', threshold: 2, cooldown: 1, cache: makeArrayCache());

    // Trip the circuit
    $cb->recordFailure();
    $cb->recordFailure();
    expect($cb->getState())->toBe('open');

    // Wait for cooldown
    sleep(2);
    expect($cb->getState())->toBe('half_open');

    // Successful probe resets the circuit
    $cb->recordSuccess();
    expect($cb->getState())->toBe('closed');
    expect($cb->getFailureCount())->toBe(0);
});

// ─── 5. Does NOT trigger on 4xx errors ──────────────────────────────

test('does not record failure on 4xx client errors', function () {
    $connector = createCBConnector();
    $cb = new CircuitBreaker('test', threshold: 2, cooldown: 60, cache: makeArrayCache());
    $connector->setCircuitBreaker($cb);

    $request = createCBRequest($connector);

    // Mock 400 response — should NOT trigger circuit breaker
    $mockClient = new MockClient([
        D1QueryRequest::class => MockResponse::make(['success' => false], 400),
    ]);
    $connector->withMockClient($mockClient);

    $response = $connector->sendWithRetry($request);

    expect($response->status())->toBe(400);
    expect($cb->getFailureCount())->toBe(0); // No failure recorded
    expect($cb->getState())->toBe('closed');
});

// ─── 6. Resets failure count on success before threshold ────────────

test('resets failure count on success before reaching threshold', function () {
    $connector = createCBConnector();
    $cb = new CircuitBreaker('test', threshold: 3, cooldown: 60, cache: makeArrayCache());
    $connector->setCircuitBreaker($cb);

    $request = createCBRequest($connector);

    // First: a 500 error
    $attempt = 0;
    $mockClient = new MockClient([
        D1QueryRequest::class => function () use (&$attempt): MockResponse {
            $attempt++;

            return $attempt === 1
                ? MockResponse::make(['success' => false], 500)
                : MockResponse::make(['success' => true], 200);
        },
    ]);
    $connector->withMockClient($mockClient);

    // First call — 500, records failure (but no retries, so throws)
    try {
        $connector->sendWithRetry($request);
    } catch (D1Exception) {
        // Expected
    }

    expect($cb->getFailureCount())->toBe(1);

    // Second call — 200, resets failure count
    $response = $connector->sendWithRetry($request);
    expect($response->status())->toBe(200);
    expect($cb->getFailureCount())->toBe(0);
    expect($cb->getState())->toBe('closed');
});

// ─── 7. Re-opens from HALF_OPEN on probe failure ────────────────────

test('re-opens circuit from half_open when probe fails', function () {
    $cb = new CircuitBreaker('test', threshold: 2, cooldown: 1, cache: makeArrayCache());

    // Trip the circuit
    $cb->recordFailure();
    $cb->recordFailure();
    expect($cb->getState())->toBe('open');

    // Wait for cooldown
    sleep(2);
    expect($cb->getState())->toBe('half_open');

    // Probe fails — circuit re-opens
    $cb->recordFailure();
    expect($cb->getState())->toBe('open');
    expect($cb->getFailureCount())->toBe(3);
});

// ─── 8. Only one concurrent probe in HALF_OPEN (atomic gate) ────────

test('only one concurrent probe is allowed in half_open state', function () {
    $cache = makeArrayCache();
    $cb = new CircuitBreaker('test', threshold: 2, cooldown: 1, cache: $cache);

    // Trip the circuit
    $cb->recordFailure();
    $cb->recordFailure();
    expect($cb->getState())->toBe('open');

    // Wait for cooldown → HALF_OPEN
    sleep(2);
    expect($cb->getState())->toBe('half_open');

    // First allowRequest() → acquires the probe lock via cache->add()
    $first = $cb->allowRequest();
    expect($first)->toBeTrue();

    // Second allowRequest() → probe key already exists, rejected
    $second = $cb->allowRequest();
    expect($second)->toBeFalse();

    // Verify probe key is set in cache
    expect($cache->has('d1:cb:test:probing'))->toBeTrue();
});
