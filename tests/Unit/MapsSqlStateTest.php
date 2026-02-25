<?php

declare(strict_types=1);

use Ntanduy\CFD1\Enums\SqlState;

/*
|--------------------------------------------------------------------------
| MapsSqlState – error-to-SQLSTATE mapping coverage
|--------------------------------------------------------------------------
|
| The trait is protected, so we use a thin anonymous wrapper class that
| exposes the method publicly.  Pest datasets keep the test body DRY.
|
*/

// Anonymous class that uses the trait so we can call the protected method
function mapsSqlStateInstance(): object
{
    return new class
    {
        use \Ntanduy\CFD1\D1\Pdo\Concerns\MapsSqlState {
            mapErrorToSqlState as public;
        }
    };
}

// ── Dataset: the 10 uncovered mappings ─────────────────────────────────

dataset('uncovered error mappings', [
    // SCHEMA ERRORS
    'ambiguous column name' => ['ambiguous column name: foo',          SqlState::AMBIGUOUS_COLUMN->value],

    // DATA ERRORS
    'datatype mismatch' => ['datatype mismatch on column x',       SqlState::DATA_EXCEPTION->value],
    'division by zero' => ['division by zero',                    SqlState::DIVISION_BY_ZERO->value],
    'string or blob too big' => ['string or blob too big',              SqlState::STRING_DATA_RIGHT_TRUNCATION->value],

    // SYSTEM ERRORS
    'disk I/O error' => ['disk I/O error',                                SqlState::INSUFFICIENT_RESOURCES->value],
    'database or disk is full' => ['database or disk is full',                      SqlState::INSUFFICIENT_RESOURCES->value],
    'attempt to write a readonly database' => ['attempt to write a readonly database',          SqlState::READ_ONLY_SQL_TRANSACTION->value],

    // D1 / CLOUDFLARE SPECIFIC
    'upstream service timeout' => ['upstream service timeout after 30s', SqlState::CONNECTION_FAILURE->value],
    'service unavailable' => ['service unavailable',                SqlState::CONNECTION_FAILURE->value],
    '502 Bad Gateway' => ['502 Bad Gateway',                    SqlState::CONNECTION_FAILURE->value],
]);

// ── Test ───────────────────────────────────────────────────────────────

it('maps error message to the correct SQLSTATE code', function (string $errorMessage, string $expectedSqlState) {
    $mapper = mapsSqlStateInstance();

    expect($mapper->mapErrorToSqlState($errorMessage))->toBe($expectedSqlState);
})->with('uncovered error mappings');
