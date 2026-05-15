<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\Console\Commands;

use Illuminate\Console\Command;
use Ntanduy\CFD1\Connectors\CloudflareD1Connector;
use Ntanduy\CFD1\Console\Concerns\FormatsBytes;
use Ntanduy\CFD1\D1\D1Connection;
use Throwable;

class D1InfoCommand extends Command
{
    use FormatsBytes;

    protected $signature = 'd1:info {--connection=d1 : The D1 connection name}';

    protected $description = 'Display Cloudflare D1 database information and connection status';

    /**
     * @var list<array{string, string, string}>
     */
    private array $rows = [];

    public function handle(): int
    {
        $connectionName = $this->option('connection');
        $config = config("database.connections.{$connectionName}", []);

        if (empty($config)) {
            $this->error("Connection \"{$connectionName}\" not found in database config.");

            return self::FAILURE;
        }

        $driver = $config['d1_driver'] ?? 'rest';

        $this->newLine();
        $this->line('<fg=cyan;options=bold>  D1 Database Info</>');
        $this->line("  <fg=gray>Connection     :</> {$connectionName}");

        // Build driver label with session info
        $driverLabel = $driver;
        $sessionConfig = $config['session'] ?? [];
        if (!empty($sessionConfig['enabled'])) {
            $mode = $sessionConfig['mode'] ?? 'first-unconstrained';
            $driverLabel .= " (sessions: {$mode})";
        }
        $this->line("  <fg=gray>Driver         :</> {$driverLabel}");
        $this->newLine();

        // ── REST metadata ───────────────────────────────────────
        $this->fetchRestMetadata($config, $connectionName);

        // ── Connection state ────────────────────────────────────
        $this->showConnectionState($config);

        // ── Query test ──────────────────────────────────────────
        $this->queryTest($connectionName);

        $this->renderTable();

        return self::SUCCESS;
    }

    /**
     * Fetch database metadata from the D1 REST API.
     */
    private function fetchRestMetadata(array $config, string $connectionName): void
    {
        $token = $config['auth']['token'] ?? $config['token'] ?? '';
        $accountId = $config['auth']['account_id'] ?? $config['account_id'] ?? '';
        $database = $config['database'] ?? '';

        if (empty($token) || empty($accountId) || empty($database)) {
            $this->addInfo('REST Metadata', 'N/A — REST credentials not configured');

            return;
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
                    apiUrl: $config['api'] ?? 'https://api.cloudflare.com/client/v4',
                    options: [
                        'retries' => 0,
                        'retry_delay' => 1,
                        'timeout' => 10,
                        'connect_timeout' => 5,
                    ],
                );
            }

            $response = $connector->databaseInfo();
            $body = $response->json();

            if (!($body['success'] ?? false)) {
                $errorMsg = $body['errors'][0]['message'] ?? 'Unknown error';
                $this->addFail('REST Metadata', $errorMsg);

                return;
            }

            $result = $body['result'] ?? [];

            $this->addPass('Database Name', $result['name'] ?? 'N/A');
            $this->addPass('Database UUID', $result['uuid'] ?? 'N/A');

            $fileSize = $result['file_size'] ?? null;
            if ($fileSize !== null) {
                $this->addPass('Size', $this->formatBytes((int) $fileSize));
            }

            $numTables = $result['num_tables'] ?? null;
            if ($numTables !== null) {
                $this->addPass('Tables', (string) $numTables);
            }

            $replication = $result['read_replication']['mode'] ?? null;
            if ($replication !== null) {
                $this->addPass('Read Replication', $replication);
            }

            $createdAt = $result['created_at'] ?? null;
            if ($createdAt !== null) {
                $this->addPass('Created', $createdAt);
            }

            $version = $result['version'] ?? null;
            if ($version !== null) {
                $this->addPass('Version', $version);
            }
        } catch (Throwable $e) {
            $this->addFail('REST Metadata', $e->getMessage());
        }
    }

    /**
     * Show internal connection state (R/W splitting, circuit breaker, sessions).
     */
    private function showConnectionState(array $config): void
    {
        // Read/Write splitting
        if (isset($config['read']) || isset($config['write'])) {
            $sticky = !empty($config['sticky']) ? 'sticky' : 'non-sticky';
            $this->addPass('R/W Splitting', "enabled ({$sticky})");
        } else {
            $this->addInfo('R/W Splitting', 'disabled');
        }

        // Circuit breaker
        $cbConfig = $config['circuit_breaker'] ?? [];
        if (!empty($cbConfig['enabled'])) {
            $threshold = $cbConfig['threshold'] ?? 5;
            $cooldown = $cbConfig['cooldown'] ?? 30;
            $this->addPass('Circuit Breaker', "enabled (threshold: {$threshold}, cooldown: {$cooldown}s)");
        } else {
            $this->addInfo('Circuit Breaker', 'disabled');
        }
    }

    /**
     * Run a test query to verify the connection works.
     */
    private function queryTest(string $connectionName): void
    {
        try {
            $start = microtime(true);
            $result = app('db')->connection($connectionName)->select('SELECT 1 as ok');
            $latencyMs = round((microtime(true) - $start) * 1000);

            if (!empty($result) && ($result[0]->ok ?? null) == 1) {
                $this->addPass('Query Test', "SELECT 1 → {$latencyMs}ms");
            } else {
                $this->addFail('Query Test', 'Unexpected response');
            }
        } catch (Throwable $e) {
            $this->addFail('Query Test', $e->getMessage());
        }
    }

    // ─── Helpers ──────────────────────────────────────────────

    private function addPass(string $property, string $detail): void
    {
        $this->rows[] = [$property, '<fg=green>✓ OK</>', $detail];
    }

    private function addFail(string $property, string $detail): void
    {
        $this->rows[] = [$property, '<fg=red>✗ FAIL</>', $detail];
    }

    private function addInfo(string $property, string $detail): void
    {
        $this->rows[] = [$property, '<fg=gray>—</>', $detail];
    }

    private function renderTable(): void
    {
        $this->table(['Property', 'Status', 'Detail'], $this->rows);
        $this->newLine();
    }
}
