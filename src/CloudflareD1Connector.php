<?php

namespace Ntanduy\CFD1;

use Saloon\Http\Response;

class CloudflareD1Connector extends CloudflareConnector
{
    protected static ?\Closure $queryLogger = null;

    public function __construct(
        public ?string $database = null,
        #[\SensitiveParameter] protected ?string $token = null,
        #[\SensitiveParameter] public ?string $accountId = null,
        public string $apiUrl = 'https://api.cloudflare.com/client/v4',
        array $options = [],
    ) {
        parent::__construct($token, $accountId, $apiUrl, $options);
    }

    /**
     * Set a global query logger callback.
     * Useful for debugging and monitoring D1 queries.
     *
     * @param \Closure|null $callback function(string $query, array $params, float $time, bool $success, ?array $error): void
     */
    public static function setQueryLogger(?\Closure $callback): void
    {
        static::$queryLogger = $callback;
    }

    public function databaseQuery(string $query, array $params, bool $retry = true): Response
    {
        $startTime = microtime(true);

        $request = new D1\Requests\D1QueryRequest($this, $this->database, $query, $params);

        $response = $retry
            ? $this->sendWithRetry($request)
            : $this->send($request);

        if (static::$queryLogger) {
            $time = microtime(true) - $startTime;
            $success = !$response->failed() && $response->json('success');

            $error = null;
            if (!$success) {
                $error = [
                    'code' => $response->json('errors.0.code'),
                    'message' => $response->json('errors.0.message', 'Unknown error'),
                    'status' => $response->status(),
                ];
            }

            (static::$queryLogger)($query, $params, $time, $success, $error);
        }

        return $response;
    }
}
