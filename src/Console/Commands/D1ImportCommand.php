<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Ntanduy\CFD1\Connectors\CloudflareD1Connector;
use Throwable;

class D1ImportCommand extends Command
{
    protected $signature = 'd1:import
        {file : Path to the SQL file to import}
        {--connection=d1 : The D1 connection name}';

    protected $description = 'Import a SQL file into a Cloudflare D1 database via the REST API';

    public function handle(): int
    {
        $filePath = $this->argument('file');
        $connectionName = $this->option('connection');

        // ── Validate file ─────────────────────────────────────────
        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");

            return self::FAILURE;
        }

        $fileContents = file_get_contents($filePath);
        if ($fileContents === false || $fileContents === '') {
            $this->error('File is empty or unreadable.');

            return self::FAILURE;
        }

        $fileSizeKb = round(strlen($fileContents) / 1024, 1);
        $etag = md5($fileContents);

        // ── Validate connection config ────────────────────────────
        $config = config("database.connections.{$connectionName}", []);
        if (empty($config)) {
            $this->error("Connection \"{$connectionName}\" not found in database config.");

            return self::FAILURE;
        }

        $database = $config['database'] ?? '';
        $token = $config['auth']['token'] ?? $config['token'] ?? '';
        $accountId = $config['auth']['account_id'] ?? $config['account_id'] ?? '';
        $apiUrl = $config['api'] ?? 'https://api.cloudflare.com/client/v4';

        if (empty($database) || empty($token) || empty($accountId)) {
            $this->error('D1 import requires REST API credentials:');
            $this->line('  • CF_D1_DATABASE_ID (database)');
            $this->line('  • CF_D1_API_TOKEN (auth.token)');
            $this->line('  • CF_D1_ACCOUNT_ID (auth.account_id)');

            return self::FAILURE;
        }

        $this->info('Starting D1 database import...');
        $this->line("  <fg=gray>Connection :</> {$connectionName}");
        $this->line("  <fg=gray>File       :</> {$filePath} ({$fileSizeKb} KB)");
        $this->line("  <fg=gray>MD5        :</> {$etag}");
        $this->newLine();

        try {
            $connector = new CloudflareD1Connector(
                database: $database,
                token: $token,
                accountId: $accountId,
                apiUrl: $apiUrl,
            );

            // Phase 1: Init — get presigned upload URL
            $uploadUrl = $this->initImport($connector, $etag);
            if ($uploadUrl === null) {
                return self::FAILURE;
            }

            // Phase 2: Upload SQL file to presigned URL
            if (!$this->uploadFile($uploadUrl, $fileContents)) {
                return self::FAILURE;
            }

            // Phase 3: Ingest — tell D1 to start consuming
            if (!$this->ingestImport($connector, $etag)) {
                return self::FAILURE;
            }

            // Phase 4: Poll until complete
            if (!$this->pollImport($connector)) {
                return self::FAILURE;
            }

            $this->newLine();
            $this->info('Import completed successfully.');

            return self::SUCCESS;

        } catch (Throwable $e) {
            $this->error('Import failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Phase 1: Initialize the import and get the presigned upload URL.
     */
    private function initImport(CloudflareD1Connector $connector, string $etag): ?string
    {
        $this->line('  <fg=cyan>Initializing import...</>');

        $response = $connector->databaseImport(action: 'init', etag: $etag);
        $body = $response->json();

        if (!($body['success'] ?? false)) {
            $errorMsg = $body['errors'][0]['message'] ?? 'Unknown API error';
            $this->error("Init failed: {$errorMsg}");

            return null;
        }

        $result = $body['result'] ?? [];
        $uploadUrl = $result['upload_url'] ?? null;

        if (empty($uploadUrl)) {
            $this->error('Init succeeded but no upload URL was returned.');

            return null;
        }

        // Store filename for ingest phase
        $this->filename = $result['filename'] ?? null;
        $this->bookmark = $result['at_bookmark'] ?? null;

        $this->line('  <fg=green>Upload URL received.</>');

        return $uploadUrl;
    }

    /**
     * Phase 2: Upload the SQL file to the presigned R2 URL.
     */
    private function uploadFile(string $uploadUrl, string $fileContents): bool
    {
        $this->line('  <fg=cyan>Uploading SQL file...</>');

        $response = Http::timeout(300)
            ->withBody($fileContents, 'application/octet-stream')
            ->put($uploadUrl);

        if (!$response->successful()) {
            $this->error("Upload failed: HTTP {$response->status()}");

            return false;
        }

        $this->line('  <fg=green>Upload complete.</>');

        return true;
    }

    /**
     * Phase 3: Tell D1 to start ingesting the uploaded file.
     */
    private function ingestImport(CloudflareD1Connector $connector, string $etag): bool
    {
        $this->line('  <fg=cyan>Starting ingestion...</>');

        $response = $connector->databaseImport(
            action: 'ingest',
            etag: $etag,
            filename: $this->filename,
        );
        $body = $response->json();

        if (!($body['success'] ?? false)) {
            $errorMsg = $body['errors'][0]['message'] ?? 'Unknown API error';
            $this->error("Ingest failed: {$errorMsg}");

            return false;
        }

        $result = $body['result'] ?? [];
        $this->bookmark = $result['at_bookmark'] ?? $this->bookmark;

        $this->line('  <fg=green>Ingestion started.</>');

        return true;
    }

    /**
     * Phase 4: Poll the import status until complete or error.
     */
    private function pollImport(CloudflareD1Connector $connector): bool
    {
        $maxAttempts = 120;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $response = $connector->databaseImport(
                action: 'poll',
                currentBookmark: $this->bookmark,
            );
            $body = $response->json();

            if (!($body['success'] ?? false)) {
                $errorMsg = $body['errors'][0]['message'] ?? 'Unknown API error';
                $this->error("Poll failed: {$errorMsg}");

                return false;
            }

            $result = $body['result'] ?? [];
            $status = $result['status'] ?? 'unknown';
            $this->bookmark = $result['at_bookmark'] ?? $this->bookmark;

            // Show progress messages
            foreach ($result['messages'] ?? [] as $message) {
                $this->line("  <fg=gray>{$message}</>");
            }

            if ($status === 'complete') {
                $meta = $result['result']['meta'] ?? [];
                $numQueries = $result['result']['num_queries'] ?? 0;
                $duration = $meta['duration'] ?? $meta['timings']['sql_duration_ms'] ?? 0;
                $sizeAfter = $meta['size_after'] ?? 0;

                $this->line('  <fg=green>Import complete.</>');
                if ($numQueries > 0) {
                    $this->line("  <fg=gray>Queries executed :</> {$numQueries}");
                }
                if ($duration > 0) {
                    $this->line("  <fg=gray>Duration         :</> {$duration}ms");
                }
                if ($sizeAfter > 0) {
                    $this->line('  <fg=gray>DB size after    :</> '.$this->formatBytes($sizeAfter));
                }

                return true;
            }

            if ($status === 'error') {
                $errorMsg = $result['error'] ?? 'Unknown import error';
                $this->error("Import error: {$errorMsg}");

                return false;
            }

            // Still active — keep polling
            $attempt++;
            $this->line("  <fg=yellow>Importing... (poll {$attempt}/{$maxAttempts})</>");
            sleep(2);
        }

        $this->error('Import timed out after '.$maxAttempts.' polling attempts.');

        return false;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2).' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }

    private ?string $filename = null;

    private ?string $bookmark = null;
}
