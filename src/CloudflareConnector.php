<?php

declare(strict_types=1);

namespace Ntanduy\CFD1;

use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Http\Response;
use Throwable;

abstract class CloudflareConnector extends Connector
{
    protected int $retries = 2;
    protected int $retryDelay = 100; // milliseconds
    protected int $timeout = 10;
    protected int $connectTimeout = 5;

    public function __construct(
        #[\SensitiveParameter] protected ?string $token = null,
        #[\SensitiveParameter] public ?string $accountId = null,
        public string $apiUrl = 'https://api.cloudflare.com/client/v4',
        array $options = [],
    ) {
        $this->timeout = $options['timeout'] ?? 10;
        $this->connectTimeout = $options['connect_timeout'] ?? 5;
        $this->retries = $options['retries'] ?? 2;
        $this->retryDelay = $options['retry_delay'] ?? 100;
    }

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
     * @param int $attempt Current retry attempt number (1-based)
     * @return void
     */
    protected function sleepWithBackoff(int $attempt): void
    {
        // Calculate exponential delay: baseDelay * 2^(attempt-1)
        // Example with 100ms base: 100ms, 200ms, 400ms, 800ms...
        $exponentialDelay = $this->retryDelay * pow(2, $attempt - 1);

        // Add random jitter to avoid synchronized retries across clients
        $jitter = mt_rand(0, 100);

        // Total delay in milliseconds
        $delay = $exponentialDelay + $jitter;

        // Convert to microseconds for usleep
        usleep((int) ($delay * 1000));
    }

    /**
     * Send request with automatic retry on failure
     */
    public function sendWithRetry(mixed $request, int $retries = null): Response
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
