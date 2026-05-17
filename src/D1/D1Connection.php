<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\D1;

use Closure;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Log;
use Ntanduy\CFD1\Connectors\CloudflareConnector;
use Ntanduy\CFD1\Connectors\CloudflareWorkerConnector;
use Ntanduy\CFD1\Contracts\D1ConnectorInterface;
use Ntanduy\CFD1\D1\Exceptions\D1BatchException;
use Ntanduy\CFD1\D1\Exceptions\D1TransactionException;
use Ntanduy\CFD1\D1\Exceptions\D1UnsupportedFeatureException;
use Ntanduy\CFD1\D1\Pdo\D1Pdo;

/**
 * Laravel database connection for Cloudflare D1.
 *
 * Extends SQLiteConnection to reuse Laravel's SQLite grammar and schema builder
 * while routing all queries through the Cloudflare D1 API (REST or Worker).
 *
 * Key design decisions:
 * - Transactions are no-ops because D1 is stateless (use batch() for atomicity)
 * - Read/write splitting is Worker-only, using D1 Sessions for consistency
 * - bulkInsert() chunks rows into D1's 100-statement batch limit
 */
class D1Connection extends SQLiteConnection
{
    protected ?CloudflareConnector $readConnector = null;

    public function __construct(
        protected CloudflareConnector $connector,
        array $config = [],
        ?CloudflareConnector $readConnector = null,
    ) {
        $this->readConnector = $readConnector;

        parent::__construct(
            fn () => $this->createD1Pdo(),
            $config['database'] ?? '',
            $config['prefix'] ?? '',
            $config,
        );

        if ($this->readConnector !== null) {
            $this->setReadPdo(new D1Pdo('sqlite::memory:', $this->readConnector));
        }
    }

    protected function getDefaultSchemaGrammar()
    {
        return new D1SchemaGrammar($this);
    }

    /**
     * Execute a Closure within a "transaction".
     *
     * D1 is stateless over HTTP — real BEGIN/COMMIT/ROLLBACK are impossible.
     * This override adds configurable behavior so developers are aware:
     *
     *   - 'silent'    (default) — no-op, backward compatible
     *   - 'log'       — logs a warning once per request
     *   - 'exception' — throws D1TransactionException immediately
     *
     * Set via config: `transaction_mode` in your D1 connection config,
     * or env: CF_D1_TRANSACTION_MODE=log
     *
     * For atomic multi-statement execution, use batch() instead:
     *   DB::connection('d1')->batch([...]);
     *
     * @param  int  $attempts
     *
     * @throws D1TransactionException When transaction_mode is 'exception'
     */
    public function transaction(Closure $callback, $attempts = 1): mixed
    {
        $mode = $this->getConfig('transaction_mode') ?? 'silent';

        if ($mode === 'exception') {
            throw new D1TransactionException(
                'DB::transaction() is not supported on D1 — it provides no atomicity or rollback. '
                .'Use DB::connection(\'d1\')->batch() for atomic multi-statement execution. '
                .'Set transaction_mode to "silent" or "log" to suppress this exception.'
            );
        }

        if ($mode === 'log') {
            Log::warning(
                'D1: DB::transaction() provides no atomicity — each query executes immediately '
                .'and cannot be rolled back on failure. Use batch() for atomic operations.',
                ['connection' => $this->getName()]
            );
        }

        return parent::transaction($callback, $attempts);
    }

    /**
     * Execute the BEGIN transaction statement.
     *
     * D1 is stateless — this no-op prevents BEGIN SQL from being sent to D1.
     * Respects `transaction_mode` config: 'exception' throws, 'log' warns.
     */
    protected function executeBeginTransactionStatement(): void
    {
        $this->applyTransactionMode('DB::beginTransaction()');
    }

    /**
     * Commit the active database transaction.
     *
     * D1 is stateless — there is nothing to commit. All queries execute
     * immediately when issued. Respects `transaction_mode` config.
     *
     * Overrides the parent to prevent sending COMMIT SQL to D1 and to
     * apply the configured transaction_mode behavior.
     */
    public function commit(): void
    {
        $this->applyTransactionMode('DB::commit()');

        // Still decrement the transaction counter and fire events so
        // Laravel's internal tracking stays consistent.
        if ($this->transactions > 0) {
            $this->transactions--;
        }

        $this->fireConnectionEvent('committed');
    }

    /**
     * Create a save point within the database.
     *
     * D1 does not support savepoints — this is a no-op so that nested
     * transactions tracked by Laravel's ManagesTransactions trait do not
     * attempt to send unsupported SAVEPOINT SQL to D1.
     */
    protected function createSavepoint(): void
    {
        // No-op: D1 does not support savepoints.
    }

    /**
     * Perform a rollback within the database.
     *
     * D1 is stateless — queries execute immediately and cannot be rolled back.
     * Respects `transaction_mode` config: 'exception' throws, 'log' warns.
     *
     * @param  int  $toLevel
     */
    protected function performRollBack($toLevel): void
    {
        $this->applyTransactionMode('DB::rollBack()');
    }

    /**
     * Apply the configured transaction_mode behavior.
     *
     * Shared by beginTransaction, commit, and rollBack paths so that
     * manual transaction calls (not just DB::transaction(Closure)) also
     * respect the configured mode.
     *
     * @throws D1TransactionException When transaction_mode is 'exception'
     */
    private function applyTransactionMode(string $caller): void
    {
        $mode = $this->getConfig('transaction_mode') ?? 'silent';

        if ($mode === 'exception') {
            throw new D1TransactionException(
                "{$caller} is not supported on D1 — it provides no atomicity or rollback. "
                .'Use DB::connection(\'d1\')->batch() for atomic multi-statement execution. '
                .'Set transaction_mode to "silent" or "log" to suppress this exception.'
            );
        }

        if ($mode === 'log') {
            Log::warning(
                "D1: {$caller} is a no-op — D1 is stateless over HTTP. "
                .'Queries execute immediately and cannot be rolled back. Use batch() for atomic operations.',
                ['connection' => $this->getName()]
            );
        }
    }

    /**
     * Get the underlying connector instance.
     */
    public function d1(): D1ConnectorInterface
    {
        return $this->connector;
    }

    /**
     * Maximum number of statements allowed in a single D1 batch.
     *
     * @see https://developers.cloudflare.com/d1/platform/limits/
     */
    public const D1_BATCH_LIMIT = 100;

    /**
     * Execute a batch of SQL statements in a single API call.
     *
     * All statements execute atomically on D1 — if any fails, none are applied.
     *
     * @param  array<int, array{sql: string, params?: array}>  $statements
     * @return array<int, array> Array of result sets, one per statement
     *
     * @throws \InvalidArgumentException If batch exceeds D1's 100-statement limit
     * @throws D1BatchException If any statement in the batch fails or response is malformed
     */
    public function batch(array $statements): array
    {
        if (empty($statements)) {
            return [];
        }

        $count = count($statements);
        if ($count > self::D1_BATCH_LIMIT) {
            throw new \InvalidArgumentException(
                'D1 batch limit is '.self::D1_BATCH_LIMIT." statements, but {$count} were given. "
                .'Use bulkInsert() for large datasets (it chunks automatically) '
                .'or split your batch into smaller groups.'
            );
        }

        // Normalize: ensure every statement has a 'params' key
        $normalized = array_map(fn (array $stmt) => [
            'sql' => $stmt['sql'],
            'params' => $stmt['params'] ?? [],
        ], $statements);

        // Never retry batches — they can contain mutating statements (INSERT,
        // UPDATE, DELETE). Retrying after timeout/5xx risks duplicate writes
        // because D1 may have already committed the batch server-side.
        $response = $this->connector->databaseBatch($normalized, retry: false);

        // Normalize malformed JSON into D1BatchException so callers don't
        // need to catch JsonException separately.
        try {
            $body = $response->json();
        } catch (\JsonException $e) {
            throw D1BatchException::fromStatementError(
                0,
                'Malformed JSON response from D1 API: '.$e->getMessage(),
                0
            );
        }

        // API-level failure (e.g. auth error, malformed request)
        if (!($body['success'] ?? false)) {
            $errorMsg = $body['errors'][0]['message'] ?? 'Batch request failed';
            $errorCode = (int) ($body['errors'][0]['code'] ?? 0);

            throw D1BatchException::fromStatementError(0, $errorMsg, $errorCode);
        }

        $results = $body['result'] ?? [];

        // Check each individual statement result for errors
        foreach ($results as $index => $result) {
            if (isset($result['success']) && $result['success'] === false) {
                $errorMsg = $result['error'] ?? 'Unknown error';

                throw D1BatchException::fromStatementError($index, $errorMsg);
            }
        }

        return $results;
    }

    /**
     * Insert multiple rows efficiently using D1 batch execution.
     *
     * Generates one parameterized INSERT per row and sends them all in a single
     * D1 batch call (one HTTP round-trip, atomic). This is significantly faster
     * than N individual insert calls for large datasets.
     *
     * D1 batch has a limit of 100 statements. For larger datasets, rows are
     * automatically chunked into batches of 100.
     *
     * @param  string  $table  Table name (prefix is applied automatically)
     * @param  array<int, array<string, mixed>>  $rows  Array of associative arrays
     * @return array<int, array> Array of batch result sets
     *
     * @throws D1BatchException If any statement in the batch fails
     */
    public function bulkInsert(string $table, array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        // Validate all rows have the same columns (use first row as reference)
        $expectedColumns = array_keys($rows[0]);
        foreach ($rows as $index => $row) {
            $rowColumns = array_keys($row);
            if ($rowColumns !== $expectedColumns) {
                throw new \InvalidArgumentException(
                    "Row [{$index}] has different columns than row [0]. "
                    .'All rows must have the same column structure.'
                );
            }
        }

        $prefix = $this->getTablePrefix();
        $prefixedTable = str_replace('"', '""', $prefix.$table);

        // Build column list once (all rows share the same columns)
        $columnList = implode(', ', array_map(
            fn (string $col) => '"'.str_replace('"', '""', $col).'"',
            $expectedColumns
        ));
        $placeholders = implode(', ', array_fill(0, count($expectedColumns), '?'));
        $sqlTemplate = "INSERT INTO \"{$prefixedTable}\" ({$columnList}) VALUES ({$placeholders})";

        $allResults = [];

        // D1 batch limit is 100 statements — chunk accordingly
        foreach (array_chunk($rows, 100) as $chunk) {
            $statements = array_map(fn (array $row) => [
                'sql' => $sqlTemplate,
                'params' => array_values($row),
            ], $chunk);

            $results = $this->batch($statements);
            array_push($allResults, ...$results);
        }

        return $allResults;
    }

    /**
     * Enable D1 session for read replication with sequential consistency.
     *
     * Sessions are only available with the Worker driver. The REST API does not
     * support D1 Sessions — this is a Cloudflare platform limitation.
     *
     * @param  string  $mode  'first-primary', 'first-unconstrained', or a bookmark string
     * @return $this
     *
     * @throws D1UnsupportedFeatureException If called on REST driver
     *
     * @see https://developers.cloudflare.com/d1/best-practices/read-replication/
     */
    public function withSession(string $mode = 'first-unconstrained'): static
    {
        if (!$this->isWorkerDriver()) {
            throw new D1UnsupportedFeatureException(
                'D1 Sessions are only available with the Worker driver. '
                .'The REST API does not support the Sessions API. '
                .'See: https://developers.cloudflare.com/d1/best-practices/read-replication/'
            );
        }

        /** @var CloudflareWorkerConnector $connector */
        $connector = $this->connector;
        $connector->enableSession($mode);

        return $this;
    }

    /**
     * Get the current session bookmark.
     *
     * Returns null if no session is active or no query has been executed yet.
     */
    public function getBookmark(): ?string
    {
        if (!$this->isWorkerDriver()) {
            return null;
        }

        /** @var CloudflareWorkerConnector $connector */
        $connector = $this->connector;

        return $connector->getBookmark();
    }

    /**
     * End the current D1 session and clear bookmark state.
     *
     * @return $this
     */
    public function endSession(): static
    {
        if ($this->isWorkerDriver()) {
            /** @var CloudflareWorkerConnector $connector */
            $connector = $this->connector;
            $connector->endSession();
        }

        return $this;
    }

    /**
     * Get the D1 driver type: 'worker' or 'rest'.
     */
    public function getDriver(): string
    {
        return $this->isWorkerDriver() ? 'worker' : 'rest';
    }

    /**
     * Check if this connection uses the Worker driver.
     */
    public function isWorkerDriver(): bool
    {
        return $this->connector instanceof CloudflareWorkerConnector;
    }

    /**
     * Get the D1 PDO instance for reads.
     *
     * When sticky mode is active and a write has occurred, returns the write
     * PDO so subsequent reads see the latest data. Otherwise returns the
     * dedicated read PDO (if configured) or falls back to the write PDO.
     */
    public function getReadPdo(): D1Pdo
    {
        // Sticky: after a write, use write PDO for reads to ensure consistency
        if ($this->recordsModified && $this->getConfig('sticky')) {
            return $this->getPdo();
        }

        if ($this->readPdo instanceof Closure) {
            $this->readPdo = ($this->readPdo)();
        }

        if ($this->readPdo === null) {
            return $this->getPdo();
        }

        return $this->readPdo;
    }

    /**
     * Check if read/write splitting is active.
     */
    public function hasReadWriteSplitting(): bool
    {
        return $this->readConnector !== null;
    }

    /**
     * Get the D1 PDO instance.
     */
    public function getPdo(): D1Pdo
    {
        if ($this->pdo instanceof Closure) {
            $this->pdo = ($this->pdo)();
        }

        if ($this->pdo === null) {
            $this->pdo = $this->createD1Pdo();
        }

        return $this->pdo;
    }

    /**
     * Helper method to create a new D1 PDO instance.
     */
    protected function createD1Pdo(): D1Pdo
    {
        return new D1Pdo('sqlite::memory:', $this->connector);
    }
}
