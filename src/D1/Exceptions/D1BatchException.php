<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\D1\Exceptions;

/**
 * Thrown when one or more statements in a batch query fail.
 *
 * Includes the index of the failing statement for easy debugging.
 */
class D1BatchException extends D1Exception
{
    /**
     * Create an exception indicating which statement in the batch failed.
     *
     * @param  int  $index  Zero-based index of the failing statement
     * @param  string  $message  Error message from the API
     * @param  int  $code  Error code from the API
     */
    public static function fromStatementError(int $index, string $message, int $code = 0): static
    {
        return static::fromApiError(
            "Batch statement [{$index}] failed: {$message}",
            $code,
            'HY000'
        );
    }
}
