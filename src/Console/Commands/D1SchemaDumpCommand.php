<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Ntanduy\CFD1\Connectors\CloudflareD1Connector;
use Throwable;

class D1SchemaDumpCommand extends Command
{
    protected $signature = 'd1:schema-dump
        {--connection=d1 : The D1 connection name}
        {--path= : Custom output path for the SQL dump}
        {--prune : Delete all migration files after dumping}
        {--no-data : Export only table definitions, not data}';

    protected $description = 'Export the D1 database schema (and optionally data) as a SQL dump using the Cloudflare D1 export API';

    public function handle(): int
    {
        $connectionName = $this->option('connection');
        $config = config("database.connections.{$connectionName}", []);

        if (empty($config)) {
            $this->error("Connection \"{$connectionName}\" not found in database config.");

            return self::FAILURE;
        }

        // Export always uses the REST API — validate credentials
        $database = $config['database'] ?? '';
        $token = $config['auth']['token'] ?? $config['token'] ?? '';
        $accountId = $config['auth']['account_id'] ?? $config['account_id'] ?? '';
        $apiUrl = $config['api'] ?? 'https://api.cloudflare.com/client/v4';

        if (empty($database) || empty($token) || empty($accountId)) {
            $this->error('D1 schema dump requires REST API credentials:');
            $this->line('  • CF_D1_DATABASE_ID (database)');
            $this->line('  • CF_D1_API_TOKEN (auth.token)');
            $this->line('  • CF_D1_ACCOUNT_ID (auth.account_id)');
            $this->newLine();
            $this->line('Even when using the Worker driver, the export API requires REST credentials.');

            return self::FAILURE;
        }

        $noData = (bool) $this->option('no-data');

        $this->info('Starting D1 database export...');
        $this->line("  <fg=gray>Connection :</> {$connectionName}");
        $this->line('  <fg=gray>Database   :</> '.$database);
        $this->line('  <fg=gray>Mode       :</> '.($noData ? 'schema only' : 'schema + data'));
        $this->newLine();

        try {
            $connector = new CloudflareD1Connector(
                database: $database,
                token: $token,
                accountId: $accountId,
                apiUrl: $apiUrl,
            );

            $signedUrl = $this->pollExport($connector, $noData);

            if ($signedUrl === null) {
                return self::FAILURE;
            }

            $sql = $this->downloadDump($signedUrl);

            if ($sql === null) {
                return self::FAILURE;
            }

            $outputPath = $this->resolveOutputPath($connectionName);
            $this->saveDump($outputPath, $sql);

            $this->newLine();
            $this->info("Schema dump saved to: {$outputPath}");

            if ($this->option('prune')) {
                $this->pruneMigrations();
            }

            return self::SUCCESS;

        } catch (Throwable $e) {
            $this->error('Export failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Poll the D1 export API until the export completes or fails.
     */
    private function pollExport(CloudflareD1Connector $connector, bool $noData): ?string
    {
        $bookmark = null;
        $maxAttempts = 60;
        $attempt = 0;

        $this->line('  <fg=cyan>Initiating export...</>');

        while ($attempt < $maxAttempts) {
            $response = $connector->databaseExport(
                currentBookmark: $bookmark,
                noData: $noData,
            );

            $body = $response->json();

            if (!($body['success'] ?? false)) {
                $errorMsg = $body['errors'][0]['message'] ?? 'Unknown API error';
                $this->error("Export API error: {$errorMsg}");

                return null;
            }

            $result = $body['result'] ?? [];
            $status = $result['status'] ?? 'unknown';
            $bookmark = $result['at_bookmark'] ?? $bookmark;

            // Show progress messages from the API
            foreach ($result['messages'] ?? [] as $message) {
                $this->line("  <fg=gray>{$message}</>");
            }

            if ($status === 'complete') {
                $signedUrl = $result['result']['signed_url'] ?? null;

                if (empty($signedUrl)) {
                    $this->error('Export completed but no download URL was returned.');

                    return null;
                }

                $this->line('  <fg=green>Export complete.</>');

                return $signedUrl;
            }

            if ($status === 'error') {
                $errorMsg = $result['error'] ?? 'Unknown export error';
                $this->error("Export failed: {$errorMsg}");

                return null;
            }

            // Status is 'active' or similar — keep polling
            $attempt++;
            $this->line("  <fg=yellow>Polling... (attempt {$attempt}/{$maxAttempts})</>");
            sleep(2);
        }

        $this->error('Export timed out after '.$maxAttempts.' polling attempts.');

        return null;
    }

    /**
     * Download the SQL dump from the signed URL.
     */
    private function downloadDump(string $signedUrl): ?string
    {
        $this->line('  <fg=cyan>Downloading SQL dump...</>');

        try {
            $response = Http::timeout(120)->retry(3, 2000)->get($signedUrl);
        } catch (Throwable $e) {
            $this->error("Failed to download dump: {$e->getMessage()}");

            return null;
        }

        if (!$response->successful()) {
            $this->error("Failed to download dump: HTTP {$response->status()}");

            return null;
        }

        $sql = $response->body();
        $sizeKb = round(strlen($sql) / 1024, 1);
        $this->line("  <fg=green>Downloaded {$sizeKb} KB</>");

        return $sql;
    }

    /**
     * Resolve the output path for the schema dump.
     */
    private function resolveOutputPath(string $connectionName): string
    {
        $customPath = $this->option('path');

        if (!empty($customPath)) {
            return $customPath;
        }

        $schemaDir = database_path('schema');

        if (!File::isDirectory($schemaDir)) {
            File::makeDirectory($schemaDir, 0755, true);
        }

        return $schemaDir."/{$connectionName}-schema.sql";
    }

    /**
     * Save the SQL dump to disk.
     */
    private function saveDump(string $path, string $sql): void
    {
        $dir = dirname($path);

        if (!File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        File::put($path, $sql);
    }

    /**
     * Delete all migration files (same behavior as native schema:dump --prune).
     */
    private function pruneMigrations(): void
    {
        $migrationPath = database_path('migrations');

        if (!File::isDirectory($migrationPath)) {
            return;
        }

        $files = File::glob($migrationPath.'/*.php');

        if (empty($files)) {
            $this->line('  <fg=gray>No migration files to prune.</>');

            return;
        }

        foreach ($files as $file) {
            File::delete($file);
        }

        $count = count($files);
        $this->line("  <fg=yellow>Pruned {$count} migration file(s).</>");
    }
}
