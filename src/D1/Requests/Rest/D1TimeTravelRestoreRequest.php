<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\D1\Requests\Rest;

use Ntanduy\CFD1\CloudflareRequest;
use Ntanduy\CFD1\Connectors\CloudflareD1Connector;
use Saloon\Enums\Method;

/**
 * Restore a D1 database to a previous point in time via bookmark or timestamp.
 *
 * Warning: This is a destructive operation — it overwrites the database in place.
 *
 * @see https://developers.cloudflare.com/d1/reference/time-travel/
 */
class D1TimeTravelRestoreRequest extends CloudflareRequest
{
    protected Method $method = Method::POST;

    public function __construct(
        CloudflareD1Connector $connector,
        protected readonly string $database,
        protected readonly ?string $bookmark = null,
        protected readonly ?string $timestamp = null,
    ) {
        parent::__construct($connector);
    }

    public function resolveEndpoint(): string
    {
        return sprintf(
            '/accounts/%s/d1/database/%s/time_travel/restore',
            $this->connector->getAccountId(),
            $this->database,
        );
    }

    protected function defaultQuery(): array
    {
        $query = [];

        if ($this->bookmark !== null) {
            $query['bookmark'] = $this->bookmark;
        }

        if ($this->timestamp !== null) {
            $query['timestamp'] = $this->timestamp;
        }

        return $query;
    }
}
