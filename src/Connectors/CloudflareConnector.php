<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\Connectors;

use Ntanduy\CFD1\D1\Exceptions\D1Exception;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Http\Response;
use Throwable;

abstract class CloudflareConnector extends Connector
{
    protected ?\Closure $queryLogger = null;

    public function __construct(
        #[\SensitiveParameter]
        protected readonly ?string $token = null,
        #[\SensitiveParameter]
        public readonly ?string $accountId = null,
        public readonly string $apiUrl = 'https://api.cloudflare.com/client/v4',
        array $options = [],
    ) {
        $this->retries = (int) ($options['retries'] ?? 2);
        $this->retryDelay = (int) ($options['retry_delay'] ?? 100);
        $this->timeout = (int) ($options['timeout'] ?? 10);
        $this->connectTimeout = (int) ($options['connect_timeout'] ?? 5);
    }

    protected readonly int $retries;

    protected readonly int $retryDelay;

    protected readonly int $timeout;

    protected readonly int $connectTimeout;

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
     * Send request with automatic retry on failure
     */
    public function sendWithRetry(mixed $request, ?int $retries = null): Response
    {
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

                    // All retries exhausted with server error — throw instead of returning bad response
                    throw D1Exception::fromApiError(
                        "Cloudflare API returned HTTP {$response->status()} after {$attempt} retries",
                        $response->status(),
                        'HY000'
                    );
                }

                return $response;
            } catch (Throwable $e) {
                if ($attempt >= $retries) {
                    throw $e;
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
     * @param  \Closure|null  $callback  function(string $query, array $params, float $time, bool $success, ?array $error): void
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
     */
    protected function logQuery(string $query, array $params, float $startTime, Response $response): void
    {
        if (!$this->queryLogger) {
            return;
        }

        $time = microtime(true) - $startTime;
        $success = !$response->failed() && $response->json('success');

        $error = null;
        if (!$success) {
            $error = [
                'code' => $response->json('errors.0.code'),
                'message' => $response->json('errors.0.message', 'Unknown error'),
                'status' => $response->status(),
            ];
        }

        ($this->queryLogger)($query, $params, $time, $success, $error);
    }
}
