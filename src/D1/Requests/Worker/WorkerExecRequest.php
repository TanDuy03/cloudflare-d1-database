<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\D1\Requests\Worker;

use Ntanduy\CFD1\CloudflareRequest;
use Ntanduy\CFD1\Connectors\CloudflareWorkerConnector;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Traits\Body\HasJsonBody;

class WorkerExecRequest extends CloudflareRequest implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        CloudflareWorkerConnector $connector,
        protected readonly string $sql,
    ) {
        parent::__construct($connector);
    }

    public function resolveEndpoint(): string
    {
        return '/exec';
    }

    protected function defaultBody(): array
    {
        return [
            'sql' => $this->sql,
        ];
    }
}
