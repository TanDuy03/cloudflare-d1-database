<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\D1\Pdo;

use Ntanduy\CFD1\Connectors\CloudflareConnector;
use Ntanduy\CFD1\Contracts\D1ConnectorInterface;
use Ntanduy\CFD1\D1\Exceptions\D1QueryException;
use Ntanduy\CFD1\D1\Pdo\Concerns\MapsSqlState;
use PDO;
use PDOStatement;

class D1Pdo extends PDO
{
    use MapsSqlState;

    protected array $lastInsertIds = [];

    protected array $errorInfo = ['00000', null, null];

    protected array $attributes = [];

    protected bool $useRetry = true;

    public function __construct(
        protected readonly string $dsn,
        protected readonly CloudflareConnector $connector,
    ) {
        // Trade-off: extending PDO requires calling parent::__construct(), which
        // opens an unused SQLite in-memory connection (~1MB overhead). This is
        // currently unavoidable without switching to a composition pattern
        // (wrapping PDO instead of extending it), deferred to a future major version.
        parent::__construct('sqlite::memory:');
    }

    #[\ReturnTypeWillChange]
    public function prepare($query, $options = []): PDOStatement|bool
    {
        return new D1PdoStatement(
            $this,
            $query,
            $options,
        );
    }

    public function d1(): D1ConnectorInterface
    {
        return $this->connector;
    }

    public function setLastInsertId(?string $name = null, mixed $value = null): void
    {
        $name = $name ?? 'id';
        $this->lastInsertIds[$name] = $value !== null ? (string) $value : null;
    }

    #[\ReturnTypeWillChange]
    public function lastInsertId(?string $name = null): string
    {
        $name = $name ?? 'id';

        return $this->lastInsertIds[$name] ?? '0';
    }

    /**
     * Begin a transaction (no-op).
     *
     * WARNING: D1 is stateless over HTTP — this method does nothing.
     * DB::transaction(Closure) provides NO actual atomicity: each query
     * inside the closure executes immediately and cannot be rolled back
     * on failure. For atomic multi-statement execution, use
     * DB::connection('d1')->batch() which leverages D1's native batch API.
     *
     * DB::transaction(Closure, attempts: N) will retry the closure on any
     * exception, but without real transaction semantics (no deadlock
     * detection, no isolation).
     */
    #[\ReturnTypeWillChange]
    public function beginTransaction(): bool
    {
        return true;
    }

    /**
     * Commit a transaction (no-op).
     *
     * WARNING: D1 is stateless — there is nothing to commit.
     * All queries execute immediately when issued.
     *
     * Respects the connection's `transaction_mode` config when the connector
     * provides access to it (via D1Pdo → connector → connection config).
     * Falls back to silent no-op if config is not accessible.
     */
    #[\ReturnTypeWillChange]
    public function commit(): bool
    {
        return true;
    }

    /**
     * Roll back a transaction (no-op).
     *
     * WARNING: D1 is stateless — previously executed queries cannot be
     * undone. This no-op exists so Laravel's internal transaction tracking
     * (auth, sessions, middleware) does not crash.
     */
    #[\ReturnTypeWillChange]
    public function rollBack(): bool
    {
        return true;
    }

    /**
     * Check if currently inside a transaction.
     *
     * Always returns false — D1 has no real transaction state.
     */
    #[\ReturnTypeWillChange]
    public function inTransaction(): bool
    {
        return false;
    }

    #[\ReturnTypeWillChange]
    public function exec(string $statement): int|false
    {
        $shouldRetry = $this->shouldRetryFor($statement);
        $response = $this->connector->databaseQuery($statement, [], $shouldRetry);

        // Normalize malformed JSON into a D1QueryException so callers don't
        // need to catch JsonException separately from driver errors.
        try {
            if ($response->failed() || !$response->json('success')) {
                $errorCode = $response->json('errors.0.code');
                $errorMessage = $response->json('errors.0.message', 'Unknown error');

                $sqlState = $this->mapErrorToSqlState($errorMessage);

                $this->errorInfo = [
                    $sqlState,
                    $errorCode,
                    $errorMessage,
                ];

                // Throw exception if error mode is set to EXCEPTION
                if ($this->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION) {
                    throw D1QueryException::fromApiError($errorMessage, (int) $errorCode, $sqlState);
                }

                return false;
            }

            $this->errorInfo = ['00000', null, null];

            $resultData = $response->json('result.0') ?? [];

            if (isset($resultData['meta']['last_row_id'])) {
                $this->setLastInsertId(null, $resultData['meta']['last_row_id']);
            }

            return $resultData['meta']['changes'] ?? 0;
        } catch (\JsonException $e) {
            throw D1QueryException::fromApiError(
                'Malformed JSON response from D1: '.$e->getMessage(),
                0,
                'HY000'
            );
        }
    }

    #[\ReturnTypeWillChange]
    public function quote(mixed $value, int $type = PDO::PARAM_STR): string|false
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return "'".str_replace("'", "''", (string) $value)."'";
    }

    #[\ReturnTypeWillChange]
    public function query($query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $statement = $this->prepare($query);

        if ($fetchMode !== null) {
            $statement->setFetchMode($fetchMode, ...$fetchModeArgs);
        }

        $statement->execute();

        return $statement;
    }

    #[\ReturnTypeWillChange]
    public function errorCode(): ?string
    {
        return $this->errorInfo[0] ?? null;
    }

    #[\ReturnTypeWillChange]
    public function errorInfo(): array
    {
        return $this->errorInfo;
    }

    #[\ReturnTypeWillChange]
    public function getAttribute(int $attribute): mixed
    {
        return match ($attribute) {
            PDO::ATTR_DRIVER_NAME => 'sqlite',
            PDO::ATTR_SERVER_VERSION => 'D1',
            PDO::ATTR_CLIENT_VERSION => 'D1',
            PDO::ATTR_EMULATE_PREPARES => $this->attributes[$attribute] ?? true,
            PDO::ATTR_ERRMODE => $this->attributes[$attribute] ?? PDO::ERRMODE_EXCEPTION,
            default => $this->attributes[$attribute] ?? null,
        };
    }

    #[\ReturnTypeWillChange]
    public function setAttribute(int $attribute, mixed $value): bool
    {
        $this->attributes[$attribute] = $value;

        return true;
    }

    /**
     * Enable or disable retry for queries.
     * Disable for DDL/migration for faster execution.
     */
    public function setRetry(bool $retry): self
    {
        $this->useRetry = $retry;

        return $this;
    }

    public function shouldRetry(): bool
    {
        return $this->useRetry;
    }

    /**
     * Determine if retry should be used for a specific statement.
     * Only idempotent read queries are safe to retry.
     * Retrying mutations (INSERT, UPDATE, DELETE) risks duplicate data.
     *
     * Classification:
     *   - SELECT ...           → safe to retry (always read-only)
     *   - WITH ... SELECT ...  → safe to retry (CTE ending with SELECT)
     *   - WITH ... INSERT ...  → NOT safe (CTE ending with mutation)
     *   - INSERT/UPDATE/DELETE → NOT safe
     *   - PRAGMA / EXPLAIN     → NOT retried (not in whitelist)
     *
     * @internal Used by D1PdoStatement. Not intended for end-user consumption.
     */
    public function shouldRetryFor(string $statement): bool
    {
        // If retry is globally disabled, respect that
        if (!$this->useRetry) {
            return false;
        }

        // Pure SELECT is always safe to retry
        if (preg_match('/^\s*SELECT\b/i', $statement)) {
            return true;
        }

        // WITH (CTE): safe only if the statement does NOT contain any
        // mutating keyword. SQLite/D1 allow WITH ... INSERT/UPDATE/DELETE,
        // which are NOT safe to retry.
        //
        // This check is conservative — it scans the entire statement text,
        // so a CTE with a string literal like WHERE action = 'DELETE' would
        // be falsely classified as mutating. This is acceptable because a
        // false positive (not retrying a read) only costs one retry attempt,
        // while a false negative (retrying a mutation) risks data corruption.
        if (preg_match('/^\s*WITH\b/i', $statement)) {
            return !preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE)\b/i', $statement);
        }

        return false;
    }
}
