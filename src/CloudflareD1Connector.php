<?php

namespace Ntanduy\CFD1;

use Saloon\Http\Response;

class CloudflareD1Connector extends CloudflareConnector
{
    public function __construct(
        public ?string $database = null,
        #[\SensitiveParameter] protected ?string $token = null,
        #[\SensitiveParameter] public ?string $accountId = null,
        public string $apiUrl = 'https://api.cloudflare.com/client/v4',
    ) {
        parent::__construct($token, $accountId, $apiUrl);
    }

    public function databaseQuery(string $query, array $params, bool $retry = true): Response
    {
        $request = new D1\Requests\D1QueryRequest($this, $this->database, $query, $params);

        return $retry
            ? $this->sendWithRetry($request)
            : $this->send($request);
    }
}
