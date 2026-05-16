<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\D1\Requests\Rest;

use Ntanduy\CFD1\CloudflareRequest;
use Ntanduy\CFD1\Connectors\CloudflareD1Connector;
use Saloon\Enums\Method;

/**
 * GET /accounts/{account_id}/d1/database/{database_id}
 *
 * Returns D1 database metadata: name, UUID, file_size, num_tables,
 * read_replication mode, created_at, version, and jurisdiction.
 *
 * @see https://developers.cloudflare.com/api/resources/d1/subresources/database/methods/get/
 */
class D1DatabaseInfoRequest extends CloudflareRequest
{
    protected Method $method = Method::GET;

    public function __construct(
        CloudflareD1Connector $connector,
        protected string $database,
    ) {
        parent::__construct($connector);
    }

    public function resolveEndpoint(): string
    {
        return sprintf(
            '/accounts/%s/d1/database/%s',
            $this->connector->getAccountId(),
            $this->database,
        );
    }
}
