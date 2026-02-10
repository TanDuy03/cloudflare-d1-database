<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\D1\Pdo\Concerns;

trait MapsSqlState
{
    /**
     * Map D1/SQLite error messages to standard SQLSTATE codes.
     */
    protected function mapErrorToSqlState(string $errorMessage): string
    {
        return match (true) {
            // --- INTEGRITY CONSTRAINTS (23xxx) ---
            str_contains($errorMessage, 'UNIQUE constraint failed') => '23000',
            str_contains($errorMessage, 'FOREIGN KEY constraint failed') => '23000',
            str_contains($errorMessage, 'NOT NULL constraint failed') => '23000',
            str_contains($errorMessage, 'CHECK constraint failed') => '23000',

            // --- LOCKING & DEADLOCK (40xxx) ---
            str_contains($errorMessage, 'database is locked') => '40001',  // Changed!
            str_contains($errorMessage, 'busy_recovery') => '40001',
            str_contains($errorMessage, 'cannot commit') => '40001',  // Serialization

            // --- SCHEMA ERRORS (42xxx) ---
            str_contains($errorMessage, 'no such table') => '42S02',
            str_contains($errorMessage, 'no such column') => '42S22',
            str_contains($errorMessage, 'already exists') => '42S01',
            str_contains($errorMessage, 'syntax error') => '42000',
            str_contains($errorMessage, 'ambiguous column name') => '42702',

            // --- DATA ERRORS (22xxx) ---
            str_contains($errorMessage, 'datatype mismatch') => '22000',
            str_contains($errorMessage, 'division by zero') => '22012',
            str_contains($errorMessage, 'string or blob too big') => '22001',

            // --- SYSTEM ERRORS (53xxx/25xxx) ---
            str_contains($errorMessage, 'disk I/O error') => '53100',
            str_contains($errorMessage, 'database or disk is full') => '53100',
            str_contains($errorMessage, 'attempt to write a readonly database') => '25006',

            // --- D1/CLOUDFLARE SPECIFIC ---
            str_contains($errorMessage, 'upstream service timeout') => '08006',  // Connection failure
            str_contains($errorMessage, 'service unavailable') => '08006',
            str_contains($errorMessage, '502 Bad Gateway') => '08006',

            // Default
            default => 'HY000',
        };
    }
}
