<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\Test\Unit;

use Ntanduy\CFD1\Connectors\CloudflareWorkerConnector;
use Ntanduy\CFD1\D1\Requests\Worker\WorkerQueryRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

class CloudflareWorkerConnectorTest extends TestCase
{
    private function makeConnector(array $options = []): CloudflareWorkerConnector
    {
        return new CloudflareWorkerConnector(
            workerUrl: 'https://d1-worker.example.workers.dev',
            workerSecret: 'test-secret-token',
            options: array_merge([
                'retries'         => 0,
                'retry_delay'     => 1,
                'timeout'         => 5,
                'connect_timeout' => 2,
            ], $options),
        );
    }

    private function successBody(): array
    {
        return [
            'success'  => true,
            'errors'   => [],
            'messages' => [],
            'result'   => [['results' => [['id' => 1]], 'success' => true, 'meta' => ['changes' => 0]]],
        ];
    }

    private function failureBody(int|string $code = 1000, string $message = 'SQL error'): array
    {
        return [
            'success'  => false,
            'errors'   => [['code' => $code, 'message' => $message]],
            'messages' => [],
            'result'   => [],
        ];
    }

    // ─── Constructor & Base URL ────────────────────────────────────────

    #[Test]
    public function test_resolve_base_url_returns_worker_url(): void
    {
        $connector = $this->makeConnector();

        $this->assertSame('https://d1-worker.example.workers.dev', $connector->resolveBaseUrl());
    }

    #[Test]
    public function test_worker_url_is_publicly_accessible(): void
    {
        $connector = $this->makeConnector();

        $this->assertSame('https://d1-worker.example.workers.dev', $connector->workerUrl);
    }

    // ─── Headers & Authorization ──────────────────────────────────────

    #[Test]
    public function test_default_headers_include_bearer_authorization(): void
    {
        $connector = $this->makeConnector();

        $mockClient = new MockClient([
            WorkerQueryRequest::class => MockResponse::make($this->successBody(), 200),
        ]);
        $connector->withMockClient($mockClient);

        $connector->databaseQuery('SELECT 1', []);

        $lastRequest = $mockClient->getLastPendingRequest();
        $headers = $lastRequest->headers()->all();

        $this->assertSame('Bearer test-secret-token', $headers['Authorization']);
        $this->assertSame('application/json', $headers['Content-Type']);
        $this->assertSame('application/json', $headers['Accept']);
    }

    #[Test]
    public function test_no_cloudflare_token_authenticator_is_used(): void
    {
        $connector = $this->makeConnector();

        // defaultAuth returns null — no TokenAuthenticator
        $reflection = new \ReflectionMethod($connector, 'defaultAuth');
        $result = $reflection->invoke($connector);

        $this->assertNull($result);
    }

    // ─── databaseQuery ────────────────────────────────────────────────

    #[Test]
    public function test_database_query_sends_worker_query_request(): void
    {
        $connector = $this->makeConnector();

        $mockClient = new MockClient([
            WorkerQueryRequest::class => MockResponse::make($this->successBody(), 200),
        ]);
        $connector->withMockClient($mockClient);

        $response = $connector->databaseQuery('SELECT * FROM users WHERE id = ?', [1]);

        $this->assertSame(200, $response->status());
        $this->assertTrue($response->json('success'));
        $mockClient->assertSentCount(1);
    }

    #[Test]
    public function test_database_query_uses_retry_when_enabled(): void
    {
        $connector = $this->makeConnector(['retries' => 1, 'retry_delay' => 1]);

        $attempt = 0;
        $mockClient = new MockClient([
            WorkerQueryRequest::class => function () use (&$attempt): MockResponse {
                $attempt++;
                if ($attempt === 1) {
                    return MockResponse::make(['success' => false], 500);
                }

                return MockResponse::make($this->successBody(), 200);
            },
        ]);
        $connector->withMockClient($mockClient);

        $response = $connector->databaseQuery('SELECT 1', [], true);

        $this->assertSame(200, $response->status());
        $mockClient->assertSentCount(2);
    }

    #[Test]
    public function test_database_query_skips_retry_when_disabled(): void
    {
        $connector = $this->makeConnector(['retries' => 2, 'retry_delay' => 1]);

        $mockClient = new MockClient([
            WorkerQueryRequest::class => MockResponse::make(['success' => false], 500),
        ]);
        $connector->withMockClient($mockClient);

        $response = $connector->databaseQuery('SELECT 1', [], false);

        $this->assertSame(500, $response->status());
        $mockClient->assertSentCount(1);
    }

    // ─── Error Handling ───────────────────────────────────────────────

    #[Test]
    public function test_handles_401_unauthorized_response(): void
    {
        $connector = $this->makeConnector();

        $mockClient = new MockClient([
            WorkerQueryRequest::class => MockResponse::make([
                'success' => false,
                'errors'  => [['code' => 401, 'message' => 'Unauthorized']],
            ], 401),
        ]);
        $connector->withMockClient($mockClient);

        $response = $connector->databaseQuery('SELECT 1', []);

        $this->assertSame(401, $response->status());
        $this->assertFalse($response->json('success'));
        $this->assertSame('Unauthorized', $response->json('errors.0.message'));
    }

    #[Test]
    public function test_handles_500_server_error_response(): void
    {
        $connector = $this->makeConnector();

        $mockClient = new MockClient([
            WorkerQueryRequest::class => MockResponse::make($this->failureBody(7500, 'Internal Server Error'), 500),
        ]);
        $connector->withMockClient($mockClient);

        $response = $connector->databaseQuery('SELECT 1', [], false);

        $this->assertSame(500, $response->status());
        $this->assertFalse($response->json('success'));
    }

    #[Test]
    public function test_handles_invalid_json_body_response(): void
    {
        $connector = $this->makeConnector();

        // MockResponse with a string body (not JSON array) — simulates invalid JSON from Worker
        $mockClient = new MockClient([
            WorkerQueryRequest::class => MockResponse::make('not valid json', 200),
        ]);
        $connector->withMockClient($mockClient);

        $response = $connector->databaseQuery('SELECT 1', []);

        // Response should succeed HTTP-wise
        $this->assertSame(200, $response->status());

        // Saloon v4 throws JsonException when body is not valid JSON
        $this->expectException(\JsonException::class);
        $response->json('success');
    }

    // ─── Query Logger ─────────────────────────────────────────────────

    #[Test]
    public function test_query_logger_is_invoked_on_success(): void
    {
        $connector = $this->makeConnector();

        $mockClient = new MockClient([
            WorkerQueryRequest::class => MockResponse::make($this->successBody(), 200),
        ]);
        $connector->withMockClient($mockClient);

        $logged = null;
        $connector->setQueryLogger(function (string $query, array $params, float $time, bool $success, ?array $error) use (&$logged) {
            $logged = compact('query', 'params', 'time', 'success', 'error');
        });

        $connector->databaseQuery('SELECT 1', ['param1']);

        $this->assertNotNull($logged);
        $this->assertSame('SELECT 1', $logged['query']);
        $this->assertSame(['param1'], $logged['params']);
        $this->assertTrue($logged['success']);
        $this->assertNull($logged['error']);
        $this->assertGreaterThan(0, $logged['time']);
    }

    #[Test]
    public function test_query_logger_is_invoked_on_failure(): void
    {
        $connector = $this->makeConnector();

        $mockClient = new MockClient([
            WorkerQueryRequest::class => MockResponse::make($this->failureBody(1000, 'syntax error'), 200),
        ]);
        $connector->withMockClient($mockClient);

        $logged = null;
        $connector->setQueryLogger(function (string $query, array $params, float $time, bool $success, ?array $error) use (&$logged) {
            $logged = compact('query', 'params', 'time', 'success', 'error');
        });

        $connector->databaseQuery('INVALID SQL', []);

        $this->assertNotNull($logged);
        $this->assertFalse($logged['success']);
        $this->assertIsArray($logged['error']);
        $this->assertSame(1000, $logged['error']['code']);
        $this->assertSame('syntax error', $logged['error']['message']);
    }

    #[Test]
    public function test_set_query_logger_returns_self(): void
    {
        $connector = $this->makeConnector();

        $returned = $connector->setQueryLogger(function () {});

        $this->assertSame($connector, $returned);
    }
}
