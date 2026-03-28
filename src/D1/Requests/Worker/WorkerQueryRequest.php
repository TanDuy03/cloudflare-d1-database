<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\D1\Requests\Worker;

use Ntanduy\CFD1\CloudflareRequest;
use Ntanduy\CFD1\Connectors\CloudflareWorkerConnector;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Traits\Body\HasJsonBody;

class WorkerQueryRequest extends CloudflareRequest implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        CloudflareWorkerConnector $connector,
        protected readonly string $sql,
        protected readonly array $bindings,
    ) {
        parent::__construct($connector);
    }

    public function resolveEndpoint(): string
    {
        return '/query';
    }

    protected function defaultBody(): array
    {
        return [
            'sql' => $this->sql,
            'bindings' => $this->bindings,
        ];
    }
}
