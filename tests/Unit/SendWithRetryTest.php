<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\Test\Unit;

use Ntanduy\CFD1\Connectors\CloudflareD1Connector;
use Ntanduy\CFD1\D1\Exceptions\D1Exception;
use Ntanduy\CFD1\D1\Requests\Rest\D1QueryRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Saloon\Http\Response;

class SendWithRetryTest extends TestCase
{
    private function makeConnector(array $options = []): CloudflareD1Connector
    {
        return new CloudflareD1Connector(
            database: 'test-db-id',
            token: 'test-token',
            accountId: 'test-account-id',
            apiUrl: 'https://api.cloudflare.com/client/v4',
            options: array_merge([
                'retries' => 2,
                'retry_delay' => 10, // Keep low for fast tests
                'timeout' => 5,
                'connect_timeout' => 2,
            ], $options),
        );
    }

    private function makeRequest(CloudflareD1Connector $connector): D1QueryRequest
    {
        return new D1QueryRequest($connector, 'test-db-id', 'SELECT 1', []);
    }

    private function successBody(): array
    {
        return [
            'success' => true,
            'errors' => [],
            'messages' => [],
            'result' => [['results' => [['1' => 1]], 'success' => true]],
        ];
    }

    // ---------------------------------------------------------
    // 1. Success on first try
    // ---------------------------------------------------------

    #[Test]
    public function test_returns_response_immediately_on_first_success(): void
    {
        $connector = $this->makeConnector();
        $request = $this->makeRequest($connector);

        $mockClient = new MockClient([
            D1QueryRequest::class => MockResponse::make($this->successBody(), 200),
        ]);
        $connector->withMockClient($mockClient);

        $start = microtime(true);
        $response = $connector->sendWithRetry($request);
        $elapsed = microtime(true) - $start;

        $this->assertSame(200, $response->status());
        $this->assertTrue($response->json('success'));

        // Should return almost instantly (no retry delay)
        $this->assertLessThan(0.5, $elapsed, 'Successful request should not have retry delays.');

        $mockClient->assertSentCount(1);
    }

    // ---------------------------------------------------------
    // 2. Retry logic — throws D1Exception after exhausting retries on 500
    // ---------------------------------------------------------

    #[Test]
    public function test_throws_exception_after_retries_exhausted_on_500(): void
    {
        $retries = 2;
        $connector = $this->makeConnector(['retries' => $retries, 'retry_delay' => 1]);
        $request = $this->makeRequest($connector);

        // All responses are 500 — should get 1 initial + 2 retries = 3 total sends
        $mockClient = new MockClient([
            D1QueryRequest::class => MockResponse::make(['success' => false, 'errors' => [['message' => 'Internal Server Error']]], 500),
        ]);
        $connector->withMockClient($mockClient);

        // After exhausting retries, sendWithRetry now throws D1Exception
        $this->expectException(D1Exception::class);
        $this->expectExceptionMessage('Cloudflare API returned HTTP 500 after 2 retries');

        $connector->sendWithRetry($request);
    }

    #[Test]
    public function test_retries_on_429_rate_limit(): void
    {
        $connector = $this->makeConnector(['retries' => 1, 'retry_delay' => 1]);
        $request = $this->makeRequest($connector);

        $attempt = 0;
        $mockClient = new MockClient([
            D1QueryRequest::class => function () use (&$attempt): MockResponse {
                $attempt++;
                if ($attempt === 1) {
                    return MockResponse::make(['success' => false], 429);
                }

                return MockResponse::make($this->successBody(), 200);
            },
        ]);
        $connector->withMockClient($mockClient);

        $response = $connector->sendWithRetry($request);

        $this->assertSame(200, $response->status());
        $mockClient->assertSentCount(2);
    }

    #[Test]
    public function test_succeeds_after_transient_500_error(): void
    {
        $connector = $this->makeConnector(['retries' => 2, 'retry_delay' => 1]);
        $request = $this->makeRequest($connector);

        $attempt = 0;
        $mockClient = new MockClient([
            D1QueryRequest::class => function () use (&$attempt): MockResponse {
                $attempt++;
                if ($attempt === 1) {
                    return MockResponse::make(['success' => false], 500);
                }

                return MockResponse::make($this->successBody(), 200);
            },
        ]);
        $connector->withMockClient($mockClient);

        $response = $connector->sendWithRetry($request);

        $this->assertSame(200, $response->status());
        $this->assertTrue($response->json('success'));
        $mockClient->assertSentCount(2);
    }

    // ---------------------------------------------------------
    // 3. Backoff delay — verifies actual waiting between retries
    // ---------------------------------------------------------

    #[Test]
    public function test_backoff_delay_introduces_measurable_wait(): void
    {
        // Use a larger retry_delay to make timing measurable
        $retryDelayMs = 50;
        $connector = $this->makeConnector(['retries' => 2, 'retry_delay' => $retryDelayMs]);
        $request = $this->makeRequest($connector);

        // All 500s — will trigger 2 retries with backoff
        $mockClient = new MockClient([
            D1QueryRequest::class => MockResponse::make(['success' => false], 500),
        ]);
        $connector->withMockClient($mockClient);

        $start = microtime(true);

        try {
            $connector->sendWithRetry($request);
        } catch (D1Exception) {
            // Expected — sendWithRetry throws after exhausting retries on 5xx
        }
        $elapsedMs = (microtime(true) - $start) * 1000;

        // Exponential backoff: attempt 1 = 50ms, attempt 2 = 100ms
        // Minimum total delay = 50 + 100 = 150ms (excluding jitter)
        // We check for at least the base delay sum to prove waiting occurred
        $minimumExpectedMs = $retryDelayMs + ($retryDelayMs * 2); // 150ms
        $this->assertGreaterThanOrEqual(
            $minimumExpectedMs * 0.8, // Allow 20% tolerance for timing precision
            $elapsedMs,
            "Expected at least ~{$minimumExpectedMs}ms of backoff delay, got {$elapsedMs}ms."
        );
    }

    // ---------------------------------------------------------
    // 4. Final failure — throws exception after retries exhausted
    // ---------------------------------------------------------

    #[Test]
    public function test_throws_exception_after_retries_exhausted_on_connection_failure(): void
    {
        $retries = 2;
        $connector = $this->makeConnector(['retries' => $retries, 'retry_delay' => 1]);
        $request = $this->makeRequest($connector);

        // MockClient that throws exceptions (simulating connection failures)
        $mockClient = new MockClient([
            D1QueryRequest::class => MockResponse::make(['error' => 'connection refused'], 500),
        ]);
        $connector->withMockClient($mockClient);

        // For Throwable path: use a connector subclass that throws
        $throwingConnector = new class('test-db', 'token', 'account', 'https://api.example.com', ['retries' => 2, 'retry_delay' => 1]) extends CloudflareD1Connector
        {
            private int $sendCount = 0;

            public function send(Request $request, ?MockClient $mockClient = null, ?callable $handleRetry = null): Response
            {
                $this->sendCount++;

                throw new \RuntimeException("Connection failed (attempt {$this->sendCount})");
            }

            public function getSendCount(): int
            {
                return $this->sendCount;
            }
        };

        $throwingRequest = $this->makeRequest($throwingConnector);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Connection failed');

        $throwingConnector->sendWithRetry($throwingRequest);
    }

    #[Test]
    public function test_does_not_retry_on_4xx_client_errors(): void
    {
        $connector = $this->makeConnector(['retries' => 2, 'retry_delay' => 1]);
        $request = $this->makeRequest($connector);

        // 400 errors should NOT trigger retry
        $mockClient = new MockClient([
            D1QueryRequest::class => MockResponse::make(['success' => false, 'errors' => [['message' => 'Bad Request']]], 400),
        ]);
        $connector->withMockClient($mockClient);

        $response = $connector->sendWithRetry($request);

        $this->assertSame(400, $response->status());
        // Should be exactly 1 call — no retries for 4xx
        $mockClient->assertSentCount(1);
    }
}
