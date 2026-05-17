<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\Connectors;

use Ntanduy\CFD1\CircuitBreaker;
use Ntanduy\CFD1\Contracts\D1ConnectorInterface;
use Ntanduy\CFD1\D1\Exceptions\CircuitBreakerOpenException;
use Ntanduy\CFD1\D1\Exceptions\D1Exception;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Http\Response;
use Throwable;

abstract class CloudflareConnector extends Connector implements D1ConnectorInterface
{
    protected ?CircuitBreaker $circuitBreaker = null;

    protected ?\Closure $queryLogger = null;

    public function __construct(
        #[\SensitiveParameter]
        protected readonly ?string $token = null,
        #[\SensitiveParameter]
        protected readonly ?string $accountId = null,
        protected readonly string $apiUrl = 'https://api.cloudflare.com/client/v4',
        array $options = [],
    ) {
        $this->retries = (int) ($options['retries'] ?? 2);
        $this->retryDelay = (int) ($options['retry_delay'] ?? 100);
        $this->timeout = (int) ($options['timeout'] ?? 10);
        $this->connectTimeout = (int) ($options['connect_timeout'] ?? 5);
    }

    public function getAccountId(): ?string
    {
        return $this->accountId;
    }

    protected readonly int $retries;

    protected readonly int $retryDelay;

    protected readonly int $timeout;

    protected readonly int $connectTimeout;

    /**
     * Attach a circuit breaker to this connector.
     * When set, sendWithRetry() will fail fast if the circuit is open.
     */
    public function setCircuitBreaker(CircuitBreaker $circuitBreaker): void
    {
        $this->circuitBreaker = $circuitBreaker;
    }

    protected function defaultAuth(): ?TokenAuthenticator
    {
        return new TokenAuthenticator($this->token);
    }

    public function resolveBaseUrl(): string
    {
        return $this->apiUrl;
    }

    protected function defaultHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    protected function defaultConfig(): array
    {
        return [
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
        ];
    }

    /**
     * Sleep with exponential backoff and jitter
     *
     * Implements exponential backoff strategy: delay * 2^(attempt-1)
     * Adds random jitter (0-100ms) to prevent thundering herd problem
     * when multiple clients retry simultaneously
     *
     * @param  int  $attempt  Current retry attempt number (1-based)
     */
    protected function sleepWithBackoff(int $attempt): void
    {
        // Calculate exponential delay: baseDelay * 2^(attempt-1)
        // Example with 100ms base: 100ms, 200ms, 400ms, 800ms...
        $exponentialDelay = $this->retryDelay * pow(2, $attempt - 1);

        // Add random jitter to avoid synchronized retries across clients
        $jitter = \random_int(0, 100);

        // Total delay in milliseconds
        $delay = $exponentialDelay + $jitter;

        // Blocking synchronous sleep — acceptable for this driver version
        // but prevents async/non-blocking usage. Convert ms to µs for usleep.
        usleep((int) ($delay * 1000));
    }

    /**
     * Send request with automatic retry on failure.
     * If a circuit breaker is attached, fails fast when the circuit is open.
     *
     * Note: Retries are HTTP-level only — triggered by 5xx server errors,
     * 429 rate-limiting, or network failures. D1 query errors (e.g. bad SQL)
     * returned as 200 with success=false are NOT retried.
     *
     * Circuit breaker behavior: a single logical request counts as **one**
     * failure regardless of how many internal retries occur. This prevents
     * a single failing query from tripping the breaker by itself.
     */
    public function sendWithRetry(mixed $request, ?int $retries = null): Response
    {
        // Circuit breaker: reject immediately if circuit is open
        if ($this->circuitBreaker && !$this->circuitBreaker->allowRequest()) {
            throw CircuitBreakerOpenException::create(
                $this->circuitBreaker->getFailureCount(),
                $this->circuitBreaker->getRemainingCooldown(),
            );
        }

        $retries = $retries ?? $this->retries;
        $attempt = 0;

        while (true) {
            try {
                $response = $this->send($request);

                // Retry on 5xx server errors or rate limiting (429)
                if ($response->status() >= 500 || $response->status() === 429) {
                    if ($attempt < $retries) {
                        $attempt++;
                        $this->sleepWithBackoff($attempt);

                        continue;
                    }

                    // Record a single failure per logical request, not per retry
                    $this->circuitBreaker?->recordFailure();

                    // All retries exhausted with server error — throw instead of returning bad response
                    throw D1Exception::fromApiError(
                        "Cloudflare API returned HTTP {$response->status()} after {$attempt} retries",
                        $response->status(),
                        'HY000'
                    );
                }

                // Only reset circuit breaker on actual 2xx success.
                // 4xx client errors should not reset the failure counter —
                // they don't prove the server is healthy.
                if ($response->successful()) {
                    $this->circuitBreaker?->recordSuccess();
                }

                return $response;
            } catch (CircuitBreakerOpenException|D1Exception $e) {
                // CircuitBreakerOpenException: don't retry, propagate immediately
                // D1Exception: failure already recorded above
                throw $e;
            } catch (Throwable $e) {
                if ($attempt >= $retries) {
                    // Record a single failure per logical request, not per retry
                    $this->circuitBreaker?->recordFailure();

                    throw D1Exception::fromApiError(
                        "Request failed after {$attempt} retries: {$e->getMessage()}",
                        0,
                        'HY000',
                        $e,
                    );
                }
                $attempt++;
                $this->sleepWithBackoff($attempt);
            }
        }
    }

    /**
     * Execute a database query through the connector.
     * Each subclass routes to its own request class (REST or Worker).
     */
    abstract public function databaseQuery(string $query, array $params, bool $retry = true): Response;

    /**
     * Execute a batch of SQL statements in a single request.
     * Each subclass routes to its own batch request class.
     *
     * @param  array<int, array{sql: string, params: array}>  $statements
     */
    abstract public function databaseBatch(array $statements, bool $retry = true): Response;

    /**
     * Set a query logger callback for this connector instance.
     * Useful for debugging and monitoring D1 queries.
     *
     * @param  \Closure|null  $callback  function(string $query, array $params, float $timeMs, bool $success, ?array $error): void
     *                                   $timeMs is the execution time in **milliseconds**.
     * @return $this
     */
    public function setQueryLogger(?\Closure $callback): static
    {
        $this->queryLogger = $callback;

        return $this;
    }

    /**
     * Log a query execution if a logger is set.
     * Shared by REST and Worker connectors.
     *
     * The callback receives execution time in **milliseconds** (matching the
     * documented `$timeMs` parameter name and Laravel's DB query logger).
     *
     * If the response body is malformed JSON, the logger is still invoked
     * with success=false and the JsonException message — it never leaks
     * an untyped exception to the caller.
     */
    protected function logQuery(string $query, array $params, float $startTime, Response $response): void
    {
        if (!$this->queryLogger) {
            return;
        }

        // Convert seconds → milliseconds to match the documented $timeMs name.
        $timeMs = (microtime(true) - $startTime) * 1000;

        try {
            $success = !$response->failed() && $response->json('success');

            $error = null;
            if (!$success) {
                $error = [
                    'code' => $response->json('errors.0.code'),
                    'message' => $response->json('errors.0.message', 'Unknown error'),
                    'status' => $response->status(),
                ];
            }
        } catch (\JsonException $e) {
            // Malformed JSON — log as failure without leaking the exception.
            $success = false;
            $error = [
                'code' => null,
                'message' => 'Malformed JSON response: '.$e->getMessage(),
                'status' => $response->status(),
            ];
        }

        ($this->queryLogger)($query, $params, $timeMs, $success, $error);
    }
}
