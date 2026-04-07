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
 * Sends an array of statements to:
 * POST /accounts/{accountId}/d1/database/{databaseId}/query
 *
 * The batch endpoint uses the same path as single query but accepts
 * an array body instead of a single {sql, params} object.
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
            $this->connector->accountId,
            $this->database,
        );
    }

    /**
     * Body is an array of statement objects — D1 batch format.
     */
    protected function defaultBody(): array
    {
        return $this->statements;
    }
}
