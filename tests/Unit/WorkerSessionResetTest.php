<?php

declare(strict_types=1);

use Ntanduy\CFD1\Connectors\CloudflareWorkerConnector;

/*
|--------------------------------------------------------------------------
| Issue #10: Worker session bookmark leak prevention
|--------------------------------------------------------------------------
|
| In long-lived runtimes (Octane, Swoole, RoadRunner, queue workers) the
| connector instance can be reused across requests. resetSessionState() must
| clear per-request bookmark state without dropping the configured mode.
|
*/

function makeWorkerConnector(): CloudflareWorkerConnector
{
    return new CloudflareWorkerConnector(
        workerUrl: 'https://test.workers.dev',
        workerSecret: 'test-secret',
    );
}

test('resetSessionState clears bookmark but preserves session mode', function () {
    $connector = makeWorkerConnector();
    $connector->enableSession('first-primary');

    // Simulate a previous request having stored a bookmark.
    $connector->updateBookmark('bkmk_from_request_a');
    expect($connector->getBookmark())->toBe('bkmk_from_request_a');
    expect($connector->getSessionParam())->toBe('bkmk_from_request_a');

    // Between requests, runtime hooks call resetSessionState().
    $connector->resetSessionState();

    // Bookmark is gone — session is still active using the configured mode.
    expect($connector->getBookmark())->toBeNull();
    expect($connector->hasActiveSession())->toBeTrue();
    expect($connector->getSessionParam())->toBe('first-primary');
});

test('resetSessionState is safe when no session is active', function () {
    $connector = makeWorkerConnector();

    // No enableSession() call — session is not active.
    $connector->resetSessionState();

    expect($connector->hasActiveSession())->toBeFalse();
    expect($connector->getBookmark())->toBeNull();
});

test('endSession clears both mode and bookmark', function () {
    $connector = makeWorkerConnector();
    $connector->enableSession('first-primary');
    $connector->updateBookmark('bkmk_x');

    $connector->endSession();

    expect($connector->hasActiveSession())->toBeFalse();
    expect($connector->getBookmark())->toBeNull();
    expect($connector->getSessionParam())->toBeNull();
});

test('enableSession resets bookmark from a previous session', function () {
    $connector = makeWorkerConnector();
    $connector->enableSession('first-primary');
    $connector->updateBookmark('bkmk_old');

    // Re-enabling a session must clear stale bookmark from a previous one.
    $connector->enableSession('first-unconstrained');

    expect($connector->getBookmark())->toBeNull();
    expect($connector->getSessionParam())->toBe('first-unconstrained');
});

test('updateBookmark with null does not clobber existing bookmark', function () {
    $connector = makeWorkerConnector();
    $connector->enableSession('first-primary');
    $connector->updateBookmark('bkmk_keep');

    // Real D1 responses without a bookmark field shouldn't clear our state.
    $connector->updateBookmark(null);

    expect($connector->getBookmark())->toBe('bkmk_keep');
});
