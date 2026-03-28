<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\Test\Unit;

use Ntanduy\CFD1\CloudflareRequest;
use Ntanduy\CFD1\Connectors\CloudflareWorkerConnector;
use Ntanduy\CFD1\D1\Requests\Worker\WorkerBatchRequest;
use Ntanduy\CFD1\D1\Requests\Worker\WorkerExecRequest;
use Ntanduy\CFD1\D1\Requests\Worker\WorkerQueryRequest;
use Ntanduy\CFD1\D1\Requests\Worker\WorkerRawRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Saloon\Enums\Method;

class WorkerRequestTest extends TestCase
{
    private function makeConnector(): CloudflareWorkerConnector
    {
        return new CloudflareWorkerConnector(
            workerUrl: 'https://d1-worker.example.workers.dev',
            workerSecret: 'test-secret',
        );
    }

    // ─── WorkerQueryRequest ───────────────────────────────────────────

    #[Test]
    public function test_worker_query_request_endpoint(): void
    {
        $connector = $this->makeConnector();
        $request = new WorkerQueryRequest($connector, 'SELECT * FROM users', [1, 2]);

        $this->assertSame('/query', $request->resolveEndpoint());
    }

    #[Test]
    public function test_worker_query_request_method_is_post(): void
    {
        $connector = $this->makeConnector();
        $request = new WorkerQueryRequest($connector, 'SELECT 1', []);

        $reflection = new \ReflectionProperty($request, 'method');
        $this->assertSame(Method::POST, $reflection->getValue($request));
    }

    #[Test]
    public function test_worker_query_request_body(): void
    {
        $connector = $this->makeConnector();
        $request = new WorkerQueryRequest($connector, 'SELECT * FROM users WHERE id = ?', ['abc']);

        $body = $request->body()->all();

        $this->assertSame('SELECT * FROM users WHERE id = ?', $body['sql']);
        $this->assertSame(['abc'], $body['bindings']);
    }

    // ─── WorkerBatchRequest ───────────────────────────────────────────

    #[Test]
    public function test_worker_batch_request_endpoint(): void
    {
        $connector = $this->makeConnector();
        $request = new WorkerBatchRequest($connector, []);

        $this->assertSame('/batch', $request->resolveEndpoint());
    }

    #[Test]
    public function test_worker_batch_request_method_is_post(): void
    {
        $connector = $this->makeConnector();
        $request = new WorkerBatchRequest($connector, []);

        $reflection = new \ReflectionProperty($request, 'method');
        $this->assertSame(Method::POST, $reflection->getValue($request));
    }

    #[Test]
    public function test_worker_batch_request_body(): void
    {
        $statements = [
            ['sql' => 'INSERT INTO users (name) VALUES (?)', 'bindings' => ['Alice']],
            ['sql' => 'INSERT INTO users (name) VALUES (?)', 'bindings' => ['Bob']],
        ];

        $connector = $this->makeConnector();
        $request = new WorkerBatchRequest($connector, $statements);

        $body = $request->body()->all();

        $this->assertSame($statements, $body['statements']);
        $this->assertCount(2, $body['statements']);
    }

    // ─── WorkerExecRequest ────────────────────────────────────────────

    #[Test]
    public function test_worker_exec_request_endpoint(): void
    {
        $connector = $this->makeConnector();
        $request = new WorkerExecRequest($connector, 'CREATE TABLE foo (id INTEGER PRIMARY KEY)');

        $this->assertSame('/exec', $request->resolveEndpoint());
    }

    #[Test]
    public function test_worker_exec_request_method_is_post(): void
    {
        $connector = $this->makeConnector();
        $request = new WorkerExecRequest($connector, 'DROP TABLE foo');

        $reflection = new \ReflectionProperty($request, 'method');
        $this->assertSame(Method::POST, $reflection->getValue($request));
    }

    #[Test]
    public function test_worker_exec_request_body_has_only_sql(): void
    {
        $sql = 'CREATE TABLE foo (id INTEGER PRIMARY KEY, name TEXT)';
        $connector = $this->makeConnector();
        $request = new WorkerExecRequest($connector, $sql);

        $body = $request->body()->all();

        $this->assertSame($sql, $body['sql']);
        $this->assertArrayNotHasKey('bindings', $body);
    }

    // ─── WorkerRawRequest ─────────────────────────────────────────────

    #[Test]
    public function test_worker_raw_request_endpoint(): void
    {
        $connector = $this->makeConnector();
        $request = new WorkerRawRequest($connector, 'SELECT id FROM users', []);

        $this->assertSame('/raw', $request->resolveEndpoint());
    }

    #[Test]
    public function test_worker_raw_request_method_is_post(): void
    {
        $connector = $this->makeConnector();
        $request = new WorkerRawRequest($connector, 'SELECT 1', []);

        $reflection = new \ReflectionProperty($request, 'method');
        $this->assertSame(Method::POST, $reflection->getValue($request));
    }

    #[Test]
    public function test_worker_raw_request_body(): void
    {
        $connector = $this->makeConnector();
        $request = new WorkerRawRequest($connector, 'SELECT * FROM users WHERE active = ?', [true]);

        $body = $request->body()->all();

        $this->assertSame('SELECT * FROM users WHERE active = ?', $body['sql']);
        $this->assertSame([true], $body['bindings']);
    }

    // ─── All requests extend CloudflareRequest ────────────────────────

    #[Test]
    public function test_all_worker_requests_extend_cloudflare_request(): void
    {
        $connector = $this->makeConnector();

        $query = new WorkerQueryRequest($connector, 'SELECT 1', []);
        $batch = new WorkerBatchRequest($connector, []);
        $exec = new WorkerExecRequest($connector, 'CREATE TABLE t (id INT)');
        $raw = new WorkerRawRequest($connector, 'SELECT 1', []);

        $this->assertInstanceOf(CloudflareRequest::class, $query);
        $this->assertInstanceOf(CloudflareRequest::class, $batch);
        $this->assertInstanceOf(CloudflareRequest::class, $exec);
        $this->assertInstanceOf(CloudflareRequest::class, $raw);
    }
}
