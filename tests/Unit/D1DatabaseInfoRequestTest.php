<?php

declare(strict_types=1);

use Ntanduy\CFD1\Connectors\CloudflareD1Connector;
use Ntanduy\CFD1\D1\Requests\Rest\D1DatabaseInfoRequest;

test('D1DatabaseInfoRequest resolves correct endpoint', function () {
    $connector = new CloudflareD1Connector(
        database: 'test-db-uuid',
        token: 'test-token',
        accountId: 'test-account-id',
    );

    $request = new D1DatabaseInfoRequest($connector, 'test-db-uuid');

    expect($request->resolveEndpoint())
        ->toBe('/accounts/test-account-id/d1/database/test-db-uuid');
});

test('D1DatabaseInfoRequest uses GET method', function () {
    $connector = new CloudflareD1Connector(
        database: 'test-db-uuid',
        token: 'test-token',
        accountId: 'test-account-id',
    );

    $request = new D1DatabaseInfoRequest($connector, 'test-db-uuid');

    $reflection = new ReflectionProperty($request, 'method');
    $reflection->setAccessible(true);

    expect($reflection->getValue($request)->value)->toBe('GET');
});
