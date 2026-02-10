<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\D1\Pdo\Concerns;

use Ntanduy\CFD1\Enums\SqlState;

trait MapsSqlState
{
    /**
     * Map D1/SQLite error messages to standard SQLSTATE codes.
     */
    protected function mapErrorToSqlState(string $errorMessage): string
    {
        return match (true) {
            // --- INTEGRITY CONSTRAINTS (23xxx) ---
            str_contains($errorMessage, 'UNIQUE constraint failed') => SqlState::INTEGRITY_CONSTRAINT_VIOLATION->value,
            str_contains($errorMessage, 'FOREIGN KEY constraint failed') => SqlState::INTEGRITY_CONSTRAINT_VIOLATION->value,
            str_contains($errorMessage, 'NOT NULL constraint failed') => SqlState::INTEGRITY_CONSTRAINT_VIOLATION->value,
            str_contains($errorMessage, 'CHECK constraint failed') => SqlState::INTEGRITY_CONSTRAINT_VIOLATION->value,

            // --- LOCKING & DEADLOCK (40xxx) ---
            str_contains($errorMessage, 'database is locked') => SqlState::SERIALIZATION_FAILURE->value,
            str_contains($errorMessage, 'busy_recovery') => SqlState::SERIALIZATION_FAILURE->value,
            str_contains($errorMessage, 'cannot commit') => SqlState::SERIALIZATION_FAILURE->value,

            // --- SCHEMA ERRORS (42xxx) ---
            str_contains($errorMessage, 'no such table') => SqlState::TABLE_NOT_FOUND->value,
            str_contains($errorMessage, 'no such column') => SqlState::COLUMN_NOT_FOUND->value,
            str_contains($errorMessage, 'already exists') => SqlState::TABLE_ALREADY_EXISTS->value,
            str_contains($errorMessage, 'syntax error') => SqlState::SYNTAX_ERROR_OR_ACCESS_VIOLATION->value,
            str_contains($errorMessage, 'ambiguous column name') => SqlState::AMBIGUOUS_COLUMN->value,

            // --- DATA ERRORS (22xxx) ---
            str_contains($errorMessage, 'datatype mismatch') => SqlState::DATA_EXCEPTION->value,
            str_contains($errorMessage, 'division by zero') => SqlState::DIVISION_BY_ZERO->value,
            str_contains($errorMessage, 'string or blob too big') => SqlState::STRING_DATA_RIGHT_TRUNCATION->value,

            // --- SYSTEM ERRORS (53xxx/25xxx) ---
            str_contains($errorMessage, 'disk I/O error') => SqlState::INSUFFICIENT_RESOURCES->value,
            str_contains($errorMessage, 'database or disk is full') => SqlState::INSUFFICIENT_RESOURCES->value,
            str_contains($errorMessage, 'attempt to write a readonly database') => SqlState::READ_ONLY_SQL_TRANSACTION->value,

            // --- D1/CLOUDFLARE SPECIFIC ---
            str_contains($errorMessage, 'upstream service timeout') => SqlState::CONNECTION_FAILURE->value,
            str_contains($errorMessage, 'service unavailable') => SqlState::CONNECTION_FAILURE->value,
            str_contains($errorMessage, '502 Bad Gateway') => SqlState::CONNECTION_FAILURE->value,

            // Default
            default => SqlState::GENERAL_ERROR->value,
        };
    }
}
