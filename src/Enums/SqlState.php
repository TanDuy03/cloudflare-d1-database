<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\Enums;

/**
 * Standard SQLSTATE codes used by the D1 driver.
 *
 * Maps SQLite/D1 error conditions to their SQLSTATE equivalents.
 *
 * @see https://en.wikipedia.org/wiki/SQLSTATE
 */
enum SqlState: string
{
    // --- General ---
    case GENERAL_ERROR = 'HY000';

    // --- Integrity Constraints (23xxx) ---
    case INTEGRITY_CONSTRAINT_VIOLATION = '23000';

    // --- Data Errors (22xxx) ---
    case DATA_EXCEPTION = '22000';
    case DIVISION_BY_ZERO = '22012';
    case STRING_DATA_RIGHT_TRUNCATION = '22001';

    // --- Schema Errors (42xxx) ---
    case SYNTAX_ERROR_OR_ACCESS_VIOLATION = '42000';
    case TABLE_NOT_FOUND = '42S02';
    case COLUMN_NOT_FOUND = '42S22';
    case TABLE_ALREADY_EXISTS = '42S01';
    case AMBIGUOUS_COLUMN = '42702';

    // --- Locking & Deadlock (40xxx) ---
    case SERIALIZATION_FAILURE = '40001';

    // --- System Errors (53xxx/25xxx) ---
    case INSUFFICIENT_RESOURCES = '53100';
    case READ_ONLY_SQL_TRANSACTION = '25006';

    // --- Connection Errors (08xxx) ---
    case CONNECTION_FAILURE = '08006';
}
