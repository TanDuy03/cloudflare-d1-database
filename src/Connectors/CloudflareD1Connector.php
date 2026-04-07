<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\Connectors;

use Ntanduy\CFD1\D1\Requests\Rest\D1BatchQueryRequest;
use Ntanduy\CFD1\D1\Requests\Rest\D1QueryRequest;
use Saloon\Http\Response;

class CloudflareD1Connector extends CloudflareConnector
{
    public function __construct(
        public readonly ?string $database = null,
        #[\SensitiveParameter]
        ?string $token = null,
        #[\SensitiveParameter]
        ?string $accountId = null,
        string $apiUrl = 'https://api.cloudflare.com/client/v4',
        array $options = [],
    ) {
        parent::__construct($token, $accountId, $apiUrl, $options);
    }

    public function databaseQuery(string $query, array $params, bool $retry = true): Response
    {
        $startTime = microtime(true);

        $request = new D1QueryRequest($this, $this->database, $query, $params);

        $response = $retry
            ? $this->sendWithRetry($request)
            : $this->send($request);

        $this->logQuery($query, $params, $startTime, $response);

        return $response;
    }

    /**
     * Execute a batch of SQL statements via the D1 REST API.
     *
     * @param  array<int, array{sql: string, params: array}>  $statements
     */
    public function databaseBatch(array $statements, bool $retry = true): Response
    {
        $request = new D1BatchQueryRequest($this, $this->database, $statements);

        return $retry
            ? $this->sendWithRetry($request)
            : $this->send($request);
    }
}
