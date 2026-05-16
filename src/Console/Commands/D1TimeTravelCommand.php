<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\Console\Commands;

use Illuminate\Console\Command;
use Ntanduy\CFD1\Connectors\CloudflareD1Connector;
use Ntanduy\CFD1\D1\D1Connection;
use Throwable;

class D1TimeTravelCommand extends Command
{
    protected $signature = 'd1:time-travel
        {--connection=d1 : The D1 connection name}
        {--timestamp= : ISO 8601 timestamp or Unix timestamp to look up or restore to}
        {--bookmark= : Bookmark to restore to (requires --restore)}
        {--restore : Restore the database to the given bookmark or timestamp}';

    protected $description = 'Get the current D1 Time Travel bookmark or restore to a previous point in time';

    public function handle(): int
    {
        $connectionName = $this->option('connection');
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
            $this->error('Time Travel requires REST API credentials:');
            $this->line('  • CF_D1_DATABASE_ID (database)');
            $this->line('  • CF_D1_API_TOKEN (auth.token)');
            $this->line('  • CF_D1_ACCOUNT_ID (auth.account_id)');

            return self::FAILURE;
        }

        try {
            // Reuse the existing connection's connector if it's a REST connector
            $connection = app('db')->connection($connectionName);
            $existingConnector = $connection instanceof D1Connection ? $connection->d1() : null;

            if ($existingConnector instanceof CloudflareD1Connector) {
                $connector = $existingConnector;
            } else {
                $connector = new CloudflareD1Connector(
                    database: $database,
                    token: $token,
                    accountId: $accountId,
                    apiUrl: $apiUrl,
                );
            }

            if ($this->option('restore')) {
                return $this->handleRestore($connector);
            }

            return $this->handleBookmark($connector);

        } catch (Throwable $e) {
            $this->error('Time Travel failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Show the current bookmark or the bookmark at a given timestamp.
     */
    private function handleBookmark(CloudflareD1Connector $connector): int
    {
        $timestamp = $this->option('timestamp');

        if ($timestamp !== null) {
            $timestamp = $this->normalizeTimestamp($timestamp);
            $this->info("Looking up bookmark at: {$timestamp}");
        } else {
            $this->info('Fetching current bookmark...');
        }

        $response = $connector->timeTravelBookmark($timestamp);
        $body = $response->json();

        if (!($body['success'] ?? false)) {
            $errorMsg = $body['errors'][0]['message'] ?? 'Unknown API error';
            $this->error("API error: {$errorMsg}");

            return self::FAILURE;
        }

        $bookmark = $body['result']['bookmark'] ?? null;

        if (empty($bookmark)) {
            $this->error('No bookmark returned.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("  <fg=cyan>Bookmark :</> {$bookmark}");
        $this->newLine();
        $this->line('  <fg=gray>To restore to this bookmark:</>');
        $this->line("  <fg=yellow>php artisan d1:time-travel --restore --bookmark=\"{$bookmark}\"</>");

        return self::SUCCESS;
    }

    /**
     * Restore the database to a previous point in time.
     */
    private function handleRestore(CloudflareD1Connector $connector): int
    {
        $bookmark = $this->option('bookmark');
        $timestamp = $this->option('timestamp');

        if (empty($bookmark) && empty($timestamp)) {
            $this->error('--restore requires either --bookmark or --timestamp.');

            return self::FAILURE;
        }

        if ($timestamp !== null) {
            $timestamp = $this->normalizeTimestamp($timestamp);
        }

        // Show what we're about to do
        $this->warn('⚠️  This will overwrite all data in the database.');
        $this->warn('   In-flight queries and transactions will be cancelled.');
        $this->newLine();

        if ($bookmark) {
            $this->line("  <fg=gray>Restore to bookmark  :</> {$bookmark}");
        }
        if ($timestamp) {
            $this->line("  <fg=gray>Restore to timestamp :</> {$timestamp}");
        }

        $this->newLine();

        if (!$this->confirm('Are you sure you want to proceed?')) {
            $this->info('Restore cancelled.');

            return self::SUCCESS;
        }

        $this->line('  <fg=cyan>Restoring database...</>');

        $response = $connector->timeTravelRestore($bookmark, $timestamp);
        $body = $response->json();

        if (!($body['success'] ?? false)) {
            $errorMsg = $body['errors'][0]['message'] ?? 'Unknown API error';
            $this->error("Restore failed: {$errorMsg}");

            return self::FAILURE;
        }

        $result = $body['result'] ?? [];
        $newBookmark = $result['bookmark'] ?? 'unknown';
        $previousBookmark = $result['previous_bookmark'] ?? null;
        $message = $result['message'] ?? 'Database restored successfully';

        $this->newLine();
        $this->info("✅ {$message}");
        $this->line("  <fg=cyan>New bookmark      :</> {$newBookmark}");

        if ($previousBookmark) {
            $this->line("  <fg=cyan>Previous bookmark :</> {$previousBookmark}");
            $this->newLine();
            $this->line('  <fg=gray>To undo this restore:</>');
            $this->line("  <fg=yellow>php artisan d1:time-travel --restore --bookmark=\"{$previousBookmark}\"</>");
        }

        return self::SUCCESS;
    }

    /**
     * Normalize a timestamp — convert Unix timestamp to ISO 8601 if needed.
     */
    private function normalizeTimestamp(string $timestamp): string
    {
        // If it looks like a pure numeric Unix timestamp, convert to ISO 8601
        if (ctype_digit($timestamp)) {
            return date('c', (int) $timestamp);
        }

        return $timestamp;
    }
}
