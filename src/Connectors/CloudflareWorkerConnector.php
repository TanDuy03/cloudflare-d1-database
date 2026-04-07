<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\Connectors;

use Ntanduy\CFD1\D1\Requests\Worker\WorkerBatchRequest;
use Ntanduy\CFD1\D1\Requests\Worker\WorkerQueryRequest;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Response;

class CloudflareWorkerConnector extends CloudflareConnector
{
    public function __construct(
        public readonly string $workerUrl = '',
        #[\SensitiveParameter]
        protected readonly string $workerSecret = '',
        array $options = [],
    ) {
        // Worker connector doesn't need Cloudflare API token/accountId.
        // Pass null for token and accountId, workerUrl as apiUrl.
        parent::__construct(null, null, $workerUrl, $options);
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

    public function databaseQuery(string $query, array $params, bool $retry = true): Response
    {
        $startTime = microtime(true);

        $request = new WorkerQueryRequest($this, $query, $params);

        $response = $retry
            ? $this->sendWithRetry($request)
            : $this->send($request);

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

        $request = new WorkerBatchRequest($this, $workerStatements);

        return $retry
            ? $this->sendWithRetry($request)
            : $this->send($request);
    }
}
