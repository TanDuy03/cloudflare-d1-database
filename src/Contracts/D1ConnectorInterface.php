<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\Contracts;

use Ntanduy\CFD1\CircuitBreaker;
use Saloon\Http\Response;

/**
 * Contract for D1 database connectors.
 *
 * Defines the public API that both REST and Worker connectors must implement.
 * Use this interface for type-hinting to decouple from the abstract Saloon connector.
 */
interface D1ConnectorInterface
{
    /**
     * Execute a single SQL query with optional parameter bindings.
     */
    public function databaseQuery(string $query, array $params, bool $retry = true): Response;

    /**
     * Execute a batch of SQL statements in a single request.
     *
     * @param  array<int, array{sql: string, params: array}>  $statements
     */
    public function databaseBatch(array $statements, bool $retry = true): Response;

    /**
     * Attach a circuit breaker to this connector.
     */
    public function setCircuitBreaker(CircuitBreaker $circuitBreaker): void;

    /**
     * Set a query logger callback for this connector instance.
     *
     * @param  \Closure|null  $callback  function(string $query, array $params, float $time, bool $success, ?array $error): void
     * @return $this
     */
    public function setQueryLogger(?\Closure $callback): static;

    /**
     * Get the Cloudflare account ID.
     */
    public function getAccountId(): ?string;
}
