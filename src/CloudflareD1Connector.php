<?php

declare(strict_types=1);

namespace Ntanduy\CFD1;

use Saloon\Http\Response;

class CloudflareD1Connector extends CloudflareConnector
{
    protected ?\Closure $queryLogger = null;

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

    public function databaseQuery(string $query, array $params, bool $retry = true): Response
    {
        $startTime = microtime(true);

        $request = new D1\Requests\D1QueryRequest($this, $this->database, $query, $params);

        $response = $retry
            ? $this->sendWithRetry($request)
            : $this->send($request);

        if ($this->queryLogger) {
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

        return $response;
    }
}
