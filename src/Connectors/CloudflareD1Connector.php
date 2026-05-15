<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\Connectors;

use Ntanduy\CFD1\D1\Requests\Rest\D1BatchQueryRequest;
use Ntanduy\CFD1\D1\Requests\Rest\D1DatabaseInfoRequest;
use Ntanduy\CFD1\D1\Requests\Rest\D1ExportRequest;
use Ntanduy\CFD1\D1\Requests\Rest\D1ImportRequest;
use Ntanduy\CFD1\D1\Requests\Rest\D1QueryRequest;
use Ntanduy\CFD1\D1\Requests\Rest\D1TimeTravelBookmarkRequest;
use Ntanduy\CFD1\D1\Requests\Rest\D1TimeTravelRestoreRequest;
use Saloon\Http\Response;

class CloudflareD1Connector extends CloudflareConnector
{
    public function __construct(
        public readonly ?string $database = null,
        #[\SensitiveParameter]
        ?string $token = null,
        #[\SensitiveParameter]
        ?string $accountId = null,
        string $apiUrl = 'https://api.cloudflare.com/client/v4',
        array $options = [],
    ) {
        parent::__construct($token, $accountId, $apiUrl, $options);
    }

    public function databaseQuery(string $query, array $params, bool $retry = true): Response
    {
        $startTime = microtime(true);

        $request = new D1QueryRequest($this, $this->database, $query, $params);

        $response = $retry
            ? $this->sendWithRetry($request)
            : $this->send($request);

        $this->logQuery($query, $params, $startTime, $response);

        return $response;
    }

    /**
     * Execute a batch of SQL statements via the D1 REST API.
     *
     * @param  array<int, array{sql: string, params: array}>  $statements
     */
    public function databaseBatch(array $statements, bool $retry = true): Response
    {
        $request = new D1BatchQueryRequest($this, $this->database, $statements);

        return $retry
            ? $this->sendWithRetry($request)
            : $this->send($request);
    }

    /**
     * Get D1 database metadata via the REST API.
     *
     * Returns name, UUID, file_size, num_tables, read_replication, created_at, version.
     *
     * @see https://developers.cloudflare.com/api/resources/d1/subresources/database/methods/get/
     */
    public function databaseInfo(): Response
    {
        $request = new D1DatabaseInfoRequest($this, $this->database);

        return $this->send($request);
    }

    /**
     * Export a D1 database as SQL via the REST API.
     *
     * Uses polling mode — call once to initiate, then again with currentBookmark to poll.
     *
     * @param  string|null  $currentBookmark  Bookmark from previous poll response
     * @param  bool  $noData  Export only schema, not data
     * @param  bool  $noSchema  Export only data, not schema
     * @param  array<string>  $tables  Filter to specific tables
     */
    public function databaseExport(
        ?string $currentBookmark = null,
        bool $noData = false,
        bool $noSchema = false,
        array $tables = [],
    ): Response {
        $request = new D1ExportRequest(
            $this,
            $this->database,
            $currentBookmark,
            $noData,
            $noSchema,
            $tables,
        );

        // Export requests should not be retried — the export is stateful
        return $this->send($request);
    }

    /**
     * Import SQL into a D1 database via the REST API.
     *
     * Phases: 'init' → upload → 'ingest' → 'poll' until complete.
     *
     * @param  string  $action  'init', 'ingest', or 'poll'
     * @param  string|null  $etag  MD5 hash of the SQL file
     * @param  string|null  $filename  Filename from init response
     * @param  string|null  $currentBookmark  Bookmark for polling
     *
     * @see https://developers.cloudflare.com/api/resources/d1/subresources/database/methods/import/
     */
    public function databaseImport(
        string $action = 'init',
        ?string $etag = null,
        ?string $filename = null,
        ?string $currentBookmark = null,
    ): Response {
        $request = new D1ImportRequest(
            $this,
            $this->database,
            $action,
            $etag,
            $filename,
            $currentBookmark,
        );

        // Import requests are stateful — do not retry
        return $this->send($request);
    }

    /**
     * Get the current bookmark, or the nearest bookmark at or before a timestamp.
     *
     * @param  string|null  $timestamp  Optional ISO 8601 timestamp
     *
     * @see https://developers.cloudflare.com/d1/reference/time-travel/
     */
    public function timeTravelBookmark(?string $timestamp = null): Response
    {
        $request = new D1TimeTravelBookmarkRequest(
            $this,
            $this->database,
            $timestamp,
        );

        return $this->send($request);
    }

    /**
     * Restore a D1 database to a previous point in time.
     *
     * Warning: This is a destructive operation.
     *
     * @param  string|null  $bookmark  Bookmark to restore to
     * @param  string|null  $timestamp  ISO 8601 timestamp to restore to
     *
     * @see https://developers.cloudflare.com/d1/reference/time-travel/
     */
    public function timeTravelRestore(?string $bookmark = null, ?string $timestamp = null): Response
    {
        $request = new D1TimeTravelRestoreRequest(
            $this,
            $this->database,
            $bookmark,
            $timestamp,
        );

        return $this->send($request);
    }
}
