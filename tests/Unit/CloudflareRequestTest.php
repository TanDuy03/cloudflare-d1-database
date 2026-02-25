<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\Test\Unit;

use Ntanduy\CFD1\CloudflareConnector;
use Ntanduy\CFD1\CloudflareRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Saloon\Http\Connector;

class CloudflareRequestTest extends TestCase
{
    #[Test]
    public function test_constructor_assigns_connector(): void
    {
        $connector = $this->createMock(CloudflareConnector::class);

        $request = new class($connector) extends CloudflareRequest
        {
            public function resolveEndpoint(): string
            {
                return '/test';
            }

            public function exposeResolveConnector(): Connector
            {
                return $this->resolveConnector();
            }
        };

        $this->assertSame($connector, $request->exposeResolveConnector());
    }
}
