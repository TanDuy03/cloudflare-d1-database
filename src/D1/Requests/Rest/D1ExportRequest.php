<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\D1\Requests\Rest;

use Ntanduy\CFD1\CloudflareRequest;
use Ntanduy\CFD1\Connectors\CloudflareD1Connector;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Export a D1 database as SQL via the Cloudflare REST API.
 *
 * Uses polling mode: first call initiates the export, subsequent calls with
 * `current_bookmark` poll for completion. When status is 'complete', the
 * response contains a signed URL to download the SQL dump.
 *
 * @see https://developers.cloudflare.com/api/resources/d1/subresources/database/methods/export/
 */
class D1ExportRequest extends CloudflareRequest implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        CloudflareD1Connector $connector,
        protected readonly string $database,
        protected readonly ?string $currentBookmark = null,
        protected readonly bool $noData = false,
        protected readonly bool $noSchema = false,
        protected readonly array $tables = [],
    ) {
        parent::__construct($connector);
    }

    public function resolveEndpoint(): string
    {
        return sprintf(
            '/accounts/%s/d1/database/%s/export',
            $this->connector->getAccountId(),
            $this->database,
        );
    }

    protected function defaultBody(): array
    {
        $body = [
            'output_format' => 'polling',
        ];

        if ($this->currentBookmark !== null) {
            $body['current_bookmark'] = $this->currentBookmark;
        }

        $dumpOptions = [];

        if ($this->noData) {
            $dumpOptions['no_data'] = true;
        }

        if ($this->noSchema) {
            $dumpOptions['no_schema'] = true;
        }

        if (!empty($this->tables)) {
            $dumpOptions['tables'] = $this->tables;
        }

        if (!empty($dumpOptions)) {
            $body['dump_options'] = $dumpOptions;
        }

        return $body;
    }
}
