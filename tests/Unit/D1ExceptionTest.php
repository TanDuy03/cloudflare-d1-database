<?php

declare(strict_types=1);

use Ntanduy\CFD1\D1\Exceptions\D1Exception;
use Ntanduy\CFD1\D1\Exceptions\D1QueryException;
use Ntanduy\CFD1\D1\Exceptions\D1StreamException;
use Ntanduy\CFD1\D1\Exceptions\D1TransactionException;
use Ntanduy\CFD1\D1\Exceptions\D1UnsupportedFeatureException;

// ── Hierarchy ──────────────────────────────────────────────────────────

test('D1Exception extends PDOException', function () {
    expect(new D1Exception('test'))->toBeInstanceOf(PDOException::class);
});

test('all custom exceptions extend D1Exception', function () {
    expect(new D1QueryException('q'))->toBeInstanceOf(D1Exception::class)
        ->and(new D1TransactionException('t'))->toBeInstanceOf(D1Exception::class)
        ->and(new D1UnsupportedFeatureException('u'))->toBeInstanceOf(D1Exception::class)
        ->and(new D1StreamException('s'))->toBeInstanceOf(D1Exception::class);
});

// ── fromApiError factory ───────────────────────────────────────────────

test('fromApiError sets message, code and errorInfo', function () {
    $e = D1QueryException::fromApiError('no such table: foo', 1000, '42S02');

    expect($e)->toBeInstanceOf(D1QueryException::class)
        ->and($e)->toBeInstanceOf(PDOException::class)
        ->and($e->getMessage())->toBe('no such table: foo')
        ->and($e->getCode())->toBe(1000)
        ->and($e->errorInfo)->toBe(['42S02', 1000, 'no such table: foo']);
});

test('fromApiError handles null code gracefully', function () {
    $e = D1Exception::fromApiError('Unknown error', null, 'HY000');

    expect($e->getCode())->toBe(0)
        ->and($e->errorInfo)->toBe(['HY000', null, 'Unknown error']);
});
