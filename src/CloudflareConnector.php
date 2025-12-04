<?php

namespace Ntanduy\CFD1;

use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;
use Throwable;

abstract class CloudflareConnector extends Connector
{
    protected int $retries = 3;
    protected int $retryDelay = 100; // milliseconds

    public function __construct(
        #[\SensitiveParameter] protected ?string $token = null,
        #[\SensitiveParameter] public ?string $accountId = null,
        public string $apiUrl = 'https://api.cloudflare.com/client/v4',
    ) {
        //
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
            'timeout' => 30,
            'connect_timeout' => 10,
        ];
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

                // Retry on 5xx server errors
                if ($response->status() >= 500 && $attempt < $retries) {
                    $attempt++;
                    usleep($this->retryDelay * 1000 * $attempt); // Exponential backoff
                    continue;
                }

                return $response;
            } catch (Throwable $e) {
                if ($attempt >= $retries) {
                    throw $e;
                }
                $attempt++;
                usleep($this->retryDelay * 1000 * $attempt);
            }
        }
    }
}
