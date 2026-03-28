<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\Console\Commands;

use Illuminate\Console\Command;
use Throwable;

class D1HealthCommand extends Command
{
    protected $signature = 'd1:health {--connection=d1 : The D1 connection name}';

    protected $description = 'Check Cloudflare D1 connection health';

    /**
     * @var list<array{string, string, string}>
     */
    private array $rows = [];

    private bool $healthy = true;

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
        $this->line("<fg=cyan;options=bold>  D1 Health Check</>");
        $this->line("  <fg=gray>Connection :</> {$connectionName}");
        $this->line("  <fg=gray>Driver     :</> {$driver}");
        $this->newLine();

        // ── Config checks ─────────────────────────────────────
        if ($driver === 'worker') {
            $this->checkWorkerConfig($config);
        } else {
            $this->checkRestConfig($config);
        }

        // ── Stop early if config is missing ───────────────────
        if (! $this->healthy) {
            $this->renderTable();

            return self::FAILURE;
        }

        // ── Query test ────────────────────────────────────────
        $this->checkQueryTest($connectionName);

        $this->renderTable();

        return $this->healthy ? self::SUCCESS : self::FAILURE;
    }

    // ─── Config Validators ────────────────────────────────────

    private function checkWorkerConfig(array $config): void
    {
        $url = $config['worker_url'] ?? '';
        $secret = $config['worker_secret'] ?? '';

        if (empty($url)) {
            $this->addFail('worker_url configured', 'Not set → Add CF_D1_WORKER_URL to .env');
        } else {
            $this->addPass('worker_url configured', $url);
        }

        if (empty($secret)) {
            $this->addFail('worker_secret configured', 'Not set → Add CF_D1_WORKER_SECRET to .env');
        } else {
            $this->addPass('worker_secret configured', $this->mask($secret));
        }
    }

    private function checkRestConfig(array $config): void
    {
        $token = $config['auth']['token'] ?? $config['token'] ?? '';
        $accountId = $config['auth']['account_id'] ?? $config['account_id'] ?? '';
        $database = $config['database'] ?? '';

        if (empty($token)) {
            $this->addFail('api_token configured', 'Not set → Add CF_D1_API_TOKEN to .env');
        } else {
            $this->addPass('api_token configured', $this->mask($token));
        }

        if (empty($accountId)) {
            $this->addFail('account_id configured', 'Not set → Add CF_D1_ACCOUNT_ID to .env');
        } else {
            $this->addPass('account_id configured', $this->mask($accountId));
        }

        if (empty($database)) {
            $this->addFail('database_id configured', 'Not set → Add CF_D1_DATABASE_ID to .env');
        } else {
            $this->addPass('database_id configured', $this->mask($database));
        }
    }

    // ─── Query Test ───────────────────────────────────────────

    private function checkQueryTest(string $connectionName): void
    {
        try {
            $start = microtime(true);
            $result = app('db')->connection($connectionName)->select('SELECT 1 as ok');
            $latencyMs = round((microtime(true) - $start) * 1000);

            if (! empty($result) && ($result[0]->ok ?? null) == 1) {
                $this->addPass('Query test passed', 'SELECT 1 as ok');
                $this->addPass('End-to-end latency', "{$latencyMs} ms");
            } else {
                $this->addFail('Query test passed', 'Unexpected response from D1');
            }
        } catch (Throwable $e) {
            $this->addFail('Query test passed', $e->getMessage());
        }
    }

    // ─── Helpers ──────────────────────────────────────────────

    private function addPass(string $check, string $detail): void
    {
        $this->rows[] = [$check, '<fg=green>✓ OK</>', $detail];
    }

    private function addFail(string $check, string $detail): void
    {
        $this->rows[] = [$check, '<fg=red>✗ FAIL</>', $detail];
        $this->healthy = false;
    }

    private function mask(string $value): string
    {
        if (strlen($value) <= 4) {
            return str_repeat('*', strlen($value));
        }

        return str_repeat('*', strlen($value) - 4).substr($value, -4);
    }

    private function renderTable(): void
    {
        $this->table(['Check', 'Status', 'Detail'], $this->rows);
        $this->newLine();

        if ($this->healthy) {
            $this->info('  Overall: HEALTHY ✓');
        } else {
            $this->error('  Overall: UNHEALTHY ✗');
        }

        $this->newLine();
    }
}
