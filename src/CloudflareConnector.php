<?php

declare(strict_types=1);

namespace Ntanduy\CFD1;

use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Http\Response;
use Throwable;

abstract class CloudflareConnector extends Connector
{
    public function __construct(
        #[\SensitiveParameter]
        protected readonly ?string $token = null,
        #[\SensitiveParameter]
        public readonly ?string $accountId = null,
        public readonly string $apiUrl = 'https://api.cloudflare.com/client/v4',
        array $options = [],
    ) {
        $this->retries = (int) ($options['retries'] ?? 2);
        $this->retryDelay = (int) ($options['retry_delay'] ?? 100);
        $this->timeout = (int) ($options['timeout'] ?? 10);
        $this->connectTimeout = (int) ($options['connect_timeout'] ?? 5);
    }

    protected readonly int $retries;

    protected readonly int $retryDelay;

    protected readonly int $timeout;

    protected readonly int $connectTimeout;

    protected function defaultAuth(): TokenAuthenticator
    {
        return new TokenAuthenticator($this->token);
    }

    public function resolveBaseUrl(): string
    {
        return $this->apiUrl;
    }

    protected function defaultHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    protected function defaultConfig(): array
    {
        return [
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
        ];
    }

    /**
     * Sleep with exponential backoff and jitter
     *
     * Implements exponential backoff strategy: delay * 2^(attempt-1)
     * Adds random jitter (0-100ms) to prevent thundering herd problem
     * when multiple clients retry simultaneously
     *
     * @param  int  $attempt  Current retry attempt number (1-based)
     */
    protected function sleepWithBackoff(int $attempt): void
    {
        // Calculate exponential delay: baseDelay * 2^(attempt-1)
        // Example with 100ms base: 100ms, 200ms, 400ms, 800ms...
        $exponentialDelay = $this->retryDelay * pow(2, $attempt - 1);

        // Add random jitter to avoid synchronized retries across clients
        $jitter = \random_int(0, 100);

        // Total delay in milliseconds
        $delay = $exponentialDelay + $jitter;

        // Blocking synchronous sleep — acceptable for this driver version
        // but prevents async/non-blocking usage. Convert ms to µs for usleep.
        usleep((int) ($delay * 1000));
    }

    /**
     * Send request with automatic retry on failure
     */
    public function sendWithRetry(mixed $request, ?int $retries = null): Response
    {
        $retries = $retries ?? $this->retries;
        $attempt = 0;

        while (true) {
            try {
                $response = $this->send($request);

                // Retry on 5xx server errors or rate limiting (429)
                if (($response->status() >= 500 || $response->status() === 429) && $attempt < $retries) {
                    $attempt++;
                    $this->sleepWithBackoff($attempt);

                    continue;
                }

                return $response;
            } catch (Throwable $e) {
                if ($attempt >= $retries) {
                    throw $e;
                }
                $attempt++;
                $this->sleepWithBackoff($attempt);
            }
        }
    }
}
