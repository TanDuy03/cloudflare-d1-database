<?php

declare(strict_types=1);

namespace Ntanduy\CFD1;

use Illuminate\Cache\Repository as CacheStore;

/**
 * Circuit Breaker for Cloudflare D1 connections.
 *
 * Prevents cascading failures by failing fast when the remote service
 * is experiencing sustained errors (cold starts, outages).
 *
 * States: CLOSED → OPEN (after threshold) → HALF_OPEN (after cooldown) → CLOSED (on success)
 */
class CircuitBreaker
{
    private const STATE_CLOSED = 'closed';

    private const STATE_OPEN = 'open';

    private const STATE_HALF_OPEN = 'half_open';

    public function __construct(
        private readonly string $connectionName,
        private readonly int $threshold,
        private readonly int $cooldown,
        private readonly CacheStore $cache,
    ) {}

    /**
     * Check whether a request is allowed through the circuit.
     *
     * CLOSED    → always allow
     * HALF_OPEN → allow exactly 1 probe request (atomic via cache->add())
     * OPEN      → reject
     */
    public function allowRequest(): bool
    {
        $state = $this->getState();

        if ($state === self::STATE_CLOSED) {
            return true;
        }

        if ($state === self::STATE_HALF_OPEN) {
            // Atomic: add() only sets the key if it does not exist.
            // Returns true for exactly 1 request, false for all others.
            return $this->cache->add($this->probeKey(), true, $this->cooldown);
        }

        // OPEN → reject
        return false;
    }

    /**
     * Record a successful request — reset failures and close the circuit.
     */
    public function recordSuccess(): void
    {
        $this->cache->forget($this->failureCountKey());
        $this->cache->forget($this->lastFailureKey());
        $this->cache->forget($this->probeKey());
    }

    /**
     * Record a failed request — increment failures and open circuit if threshold reached.
     */
    public function recordFailure(): void
    {
        $failures = (int) $this->cache->get($this->failureCountKey(), 0);
        $failures++;

        // Store failure count with TTL = cooldown * 10 to auto-cleanup stale state
        $ttl = $this->cooldown * 10;
        $this->cache->put($this->failureCountKey(), $failures, $ttl);
        $this->cache->put($this->lastFailureKey(), time(), $ttl);
    }

    /**
     * Get the current circuit state.
     */
    public function getState(): string
    {
        $failures = (int) $this->cache->get($this->failureCountKey(), 0);

        // Not enough failures → circuit is closed
        if ($failures < $this->threshold) {
            return self::STATE_CLOSED;
        }

        // Threshold reached — check if cooldown has elapsed
        $lastFailure = (int) $this->cache->get($this->lastFailureKey(), 0);
        $elapsed = time() - $lastFailure;

        if ($elapsed >= $this->cooldown) {
            // Cooldown elapsed → allow one probe request
            return self::STATE_HALF_OPEN;
        }

        // Still within cooldown → circuit is open
        return self::STATE_OPEN;
    }

    /**
     * Get the number of consecutive failures recorded.
     */
    public function getFailureCount(): int
    {
        return (int) $this->cache->get($this->failureCountKey(), 0);
    }

    /**
     * Get remaining seconds until the circuit transitions from OPEN to HALF_OPEN.
     * Returns 0 if the circuit is not OPEN.
     */
    public function getRemainingCooldown(): int
    {
        if ($this->getState() !== self::STATE_OPEN) {
            return 0;
        }

        $lastFailure = (int) $this->cache->get($this->lastFailureKey(), 0);
        $remaining = $this->cooldown - (time() - $lastFailure);

        return max(0, $remaining);
    }

    private function failureCountKey(): string
    {
        return "d1:cb:{$this->connectionName}:failures";
    }

    private function lastFailureKey(): string
    {
        return "d1:cb:{$this->connectionName}:last_failure";
    }

    private function probeKey(): string
    {
        return "d1:cb:{$this->connectionName}:probing";
    }
}
