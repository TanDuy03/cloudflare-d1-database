<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\D1\Requests\Rest;

use Ntanduy\CFD1\CloudflareRequest;
use Ntanduy\CFD1\Connectors\CloudflareD1Connector;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Batch query request for the Cloudflare D1 REST API.
 *
 * Sends a batch of statements to:
 * POST /accounts/{accountId}/d1/database/{databaseId}/query
 *
 * Body format: {"batch": [{"sql": "...", "params": [...]}, ...]}
 */
class D1BatchQueryRequest extends CloudflareRequest implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /**
     * @param  array<int, array{sql: string, params: array}>  $statements
     */
    public function __construct(
        CloudflareD1Connector $connector,
        protected readonly string $database,
        protected readonly array $statements,
    ) {
        parent::__construct($connector);
    }

    public function resolveEndpoint(): string
    {
        return sprintf(
            '/accounts/%s/d1/database/%s/query',
            $this->connector->getAccountId(),
            $this->database,
        );
    }

    /**
     * Body is a JSON object with a "batch" key containing the statement array.
     *
     * D1 REST API expects: {"batch": [{"sql": "...", "params": [...]}, ...]}
     */
    protected function defaultBody(): array
    {
        return ['batch' => $this->statements];
    }
}
