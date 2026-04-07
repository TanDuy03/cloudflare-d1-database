<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\D1\Exceptions;

/**
 * Thrown when the circuit breaker is OPEN and requests are rejected immediately.
 *
 * Indicates the remote service has experienced too many consecutive failures
 * and the circuit breaker is preventing further requests to avoid blocking.
 */
class CircuitBreakerOpenException extends D1Exception
{
    /**
     * Create an exception for an open circuit breaker.
     *
     * @param  int  $failureCount  Number of consecutive failures that triggered the circuit
     * @param  int  $retryAfter  Seconds until the circuit will allow a probe request
     */
    public static function create(int $failureCount, int $retryAfter): static
    {
        return static::fromApiError(
            "Circuit breaker is OPEN after {$failureCount} consecutive failures. Retry after {$retryAfter}s.",
            0,
            'HY000'
        );
    }
}
