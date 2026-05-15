<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\D1\Requests\Rest;

use Ntanduy\CFD1\CloudflareRequest;
use Ntanduy\CFD1\Connectors\CloudflareD1Connector;
use Saloon\Enums\Method;

/**
 * Get the current D1 bookmark, or the nearest bookmark at or before a given timestamp.
 *
 * @see https://developers.cloudflare.com/d1/reference/time-travel/
 */
class D1TimeTravelBookmarkRequest extends CloudflareRequest
{
    protected Method $method = Method::GET;

    public function __construct(
        CloudflareD1Connector $connector,
        protected readonly string $database,
        protected readonly ?string $timestamp = null,
    ) {
        parent::__construct($connector);
    }

    public function resolveEndpoint(): string
    {
        return sprintf(
            '/accounts/%s/d1/database/%s/time_travel/bookmark',
            $this->connector->accountId,
            $this->database,
        );
    }

    protected function defaultQuery(): array
    {
        $query = [];

        if ($this->timestamp !== null) {
            $query['timestamp'] = $this->timestamp;
        }

        return $query;
    }
}
