<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\Connectors;

use Ntanduy\CFD1\D1\Requests\Worker\WorkerBatchRequest;
use Ntanduy\CFD1\D1\Requests\Worker\WorkerExecRequest;
use Ntanduy\CFD1\D1\Requests\Worker\WorkerQueryRequest;
use Ntanduy\CFD1\D1\Requests\Worker\WorkerRawRequest;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;

class CloudflareWorkerConnector extends CloudflareConnector
{
    protected ?string $sessionMode = null;

    protected ?string $sessionBookmark = null;

    public function __construct(
        public readonly string $workerUrl = '',
        #[\SensitiveParameter]
        protected readonly string $workerSecret = '',
        array $options = [],
        protected readonly bool $hmac = false,
    ) {
        // Worker connector doesn't need Cloudflare API token/accountId.
        // Pass null for token and accountId, workerUrl as apiUrl.
        parent::__construct(null, null, $workerUrl, $options);
    }

    /**
     * Register HMAC signing middleware when enabled.
     *
     * Adds X-D1-Timestamp, X-D1-Nonce, and X-D1-Signature headers to every request.
     * The signature is HMAC-SHA256(timestamp.nonce.body, workerSecret).
     * The nonce ensures two identical requests within the same second produce
     * different signatures, preventing false replay-detection rejections.
     */
    public function boot(PendingRequest $pendingRequest): void
    {
        if (!$this->hmac) {
            return;
        }

        $pendingRequest->middleware()->onRequest(function (PendingRequest $request): void {
            $timestamp = (string) time();
            $nonce = bin2hex(random_bytes(16));
            $body = (string) $request->body();
            $signature = hash_hmac('sha256', "{$timestamp}.{$nonce}.{$body}", $this->workerSecret);

            $request->headers()->add('X-D1-Timestamp', $timestamp);
            $request->headers()->add('X-D1-Nonce', $nonce);
            $request->headers()->add('X-D1-Signature', $signature);
        });
    }

    public function resolveBaseUrl(): string
    {
        return $this->workerUrl;
    }

    /**
     * Worker uses a shared secret for authentication instead of Cloudflare API token.
     * Override defaultAuth to disable TokenAuthenticator (which would fail with null token).
     */
    protected function defaultAuth(): ?TokenAuthenticator
    {
        return null;
    }

    protected function defaultHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$this->workerSecret,
        ];
    }

    /**
     * Enable D1 session for read replication with sequential consistency.
     *
     * @param  string  $mode  'first-primary', 'first-unconstrained', or a bookmark string
     */
    public function enableSession(string $mode = 'first-unconstrained'): void
    {
        $this->sessionMode = $mode;
        $this->sessionBookmark = null;
    }

    /**
     * Disable the current D1 session and clear bookmark state.
     */
    public function endSession(): void
    {
        $this->sessionMode = null;
        $this->sessionBookmark = null;
    }

    /**
     * Reset session state between requests in long-lived runtimes.
     *
     * Call this in Octane/Swoole/RoadRunner request lifecycle hooks
     * to prevent bookmark leaking from one request to the next.
     * Unlike endSession(), this preserves the configured session mode
     * while clearing only the per-request bookmark.
     */
    public function resetSessionState(): void
    {
        $this->sessionBookmark = null;
    }

    /**
     * Get the session parameter to send with the next request.
     * Returns the latest bookmark if available, otherwise the configured mode.
     */
    public function getSessionParam(): ?string
    {
        if ($this->sessionBookmark !== null) {
            return $this->sessionBookmark;
        }

        return $this->sessionMode;
    }

    /**
     * Get the current session bookmark.
     */
    public function getBookmark(): ?string
    {
        return $this->sessionBookmark;
    }

    /**
     * Update the stored bookmark from a D1 response.
     */
    public function updateBookmark(?string $bookmark): void
    {
        if ($bookmark !== null) {
            $this->sessionBookmark = $bookmark;
        }
    }

    /**
     * Check if a D1 session is currently active.
     */
    public function hasActiveSession(): bool
    {
        return $this->sessionMode !== null;
    }

    public function databaseQuery(string $query, array $params, bool $retry = true): Response
    {
        $startTime = microtime(true);

        $request = new WorkerQueryRequest($this, $query, $params, $this->getSessionParam());

        $response = $retry
            ? $this->sendWithRetry($request)
            : $this->send($request);

        $this->extractBookmark($response);
        $this->logQuery($query, $params, $startTime, $response);

        return $response;
    }

    /**
     * Execute a batch of SQL statements via the Worker /batch endpoint.
     * The Worker router natively supports D1Database.batch() for atomic execution.
     *
     * @param  array<int, array{sql: string, params: array}>  $statements
     */
    public function databaseBatch(array $statements, bool $retry = true): Response
    {
        // Map 'params' key to 'bindings' to match Worker endpoint format
        $workerStatements = array_map(fn (array $stmt) => [
            'sql' => $stmt['sql'],
            'bindings' => $stmt['params'],
        ], $statements);

        $request = new WorkerBatchRequest($this, $workerStatements, $this->getSessionParam());

        $response = $retry
            ? $this->sendWithRetry($request)
            : $this->send($request);

        $this->extractBookmark($response);

        return $response;
    }

    /**
     * Execute raw DDL/migration SQL via the Worker /exec endpoint.
     * Unlike databaseQuery(), this does not use parameterized bindings.
     */
    public function databaseExec(string $sql, bool $retry = true): Response
    {
        $request = new WorkerExecRequest($this, $sql);

        return $retry
            ? $this->sendWithRetry($request)
            : $this->send($request);
    }

    /**
     * Execute a query and return raw array-of-arrays via the Worker /raw endpoint.
     */
    public function databaseRaw(string $query, array $params = [], bool $retry = true): Response
    {
        $request = new WorkerRawRequest($this, $query, $params);

        return $retry
            ? $this->sendWithRetry($request)
            : $this->send($request);
    }

    /**
     * Safely extract and store the session bookmark from a response.
     * Only attempts JSON parsing when a session is active.
     */
    private function extractBookmark(Response $response): void
    {
        if (!$this->hasActiveSession()) {
            return;
        }

        try {
            $this->updateBookmark($response->json('bookmark'));
        } catch (\JsonException) {
            // Invalid JSON body — skip bookmark extraction
        }
    }
}
