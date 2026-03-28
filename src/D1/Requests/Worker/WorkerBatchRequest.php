<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\D1\Requests\Worker;

use Ntanduy\CFD1\CloudflareRequest;
use Ntanduy\CFD1\Connectors\CloudflareWorkerConnector;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Traits\Body\HasJsonBody;

class WorkerBatchRequest extends CloudflareRequest implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /**
     * @param  array<int, array{sql: string, bindings: array}>  $statements
     */
    public function __construct(
        CloudflareWorkerConnector $connector,
        protected readonly array $statements,
    ) {
        parent::__construct($connector);
    }

    public function resolveEndpoint(): string
    {
        return '/batch';
    }

    protected function defaultBody(): array
    {
        return [
            'statements' => $this->statements,
        ];
    }
}
