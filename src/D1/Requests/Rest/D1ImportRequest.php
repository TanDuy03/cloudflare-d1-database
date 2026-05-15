<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\D1\Requests\Rest;

use Ntanduy\CFD1\CloudflareRequest;
use Ntanduy\CFD1\Connectors\CloudflareD1Connector;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Import SQL into a D1 database via the Cloudflare REST API.
 *
 * The import process has three phases:
 * 1. init    — get a presigned upload URL
 * 2. ingest  — tell D1 to start consuming the uploaded file
 * 3. poll    — check import status until complete
 *
 * @see https://developers.cloudflare.com/api/resources/d1/subresources/database/methods/import/
 */
class D1ImportRequest extends CloudflareRequest implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /**
     * @param  string  $database  Database UUID
     * @param  string  $action  One of 'init', 'ingest', 'poll'
     * @param  string|null  $etag  MD5 hash of the SQL file (required for init/ingest)
     * @param  string|null  $filename  Filename returned from init (required for ingest)
     * @param  string|null  $currentBookmark  Bookmark for polling status
     */
    public function __construct(
        CloudflareD1Connector $connector,
        protected readonly string $database,
        protected readonly string $action = 'init',
        protected readonly ?string $etag = null,
        protected readonly ?string $filename = null,
        protected readonly ?string $currentBookmark = null,
    ) {
        parent::__construct($connector);
    }

    public function resolveEndpoint(): string
    {
        return sprintf(
            '/accounts/%s/d1/database/%s/import',
            $this->connector->accountId,
            $this->database,
        );
    }

    protected function defaultBody(): array
    {
        $body = ['action' => $this->action];

        if ($this->etag !== null) {
            $body['etag'] = $this->etag;
        }

        if ($this->filename !== null) {
            $body['filename'] = $this->filename;
        }

        if ($this->currentBookmark !== null) {
            $body['current_bookmark'] = $this->currentBookmark;
        }

        return $body;
    }
}
