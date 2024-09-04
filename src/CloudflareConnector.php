<?php

namespace Ntanduy\CFD1;

use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;

abstract class CloudflareConnector extends Connector
{
    public function __construct(
        protected ?string $token = null,
        public ?string $accountId = null,
        public string $apiUrl = 'https://api.cloudflare.com/client/v4',
    ) {
        //
    }

    protected function defaultAuth(): TokenAuthenticator
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
            'Accept'       => 'application/json',
        ];
    }
}
