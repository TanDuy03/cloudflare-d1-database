<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\D1\Exceptions;

use PDOException;

/**
 * Base exception for all Cloudflare D1 driver errors.
 *
 * Extends PDOException so existing catch blocks remain compatible.
 *
 * @phpstan-consistent-constructor
 */
class D1Exception extends PDOException
{
    /**
     * Create an exception from a Cloudflare API error response.
     *
     * @param  string  $message  Error message from the API
     * @param  int|null  $code  Error code from the API
     * @param  string  $sqlState  Mapped SQLSTATE code
     */
    public static function fromApiError(string $message, ?int $code, string $sqlState): static
    {
        $exception = new static($message, $code ?? 0);
        $exception->errorInfo = [$sqlState, $code, $message];

        return $exception;
    }
}
