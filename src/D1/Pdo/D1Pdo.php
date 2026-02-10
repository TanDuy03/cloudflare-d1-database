<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\D1\Pdo;

use Ntanduy\CFD1\CloudflareD1Connector;
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
        protected string $dsn,
        protected CloudflareD1Connector $connector,
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

    public function d1(): CloudflareD1Connector
    {
        return $this->connector;
    }

    public function setLastInsertId($name = null, $value = null): void
    {
        $name = $name ?? 'id';
        $this->lastInsertIds[$name] = $value !== null ? (string) $value : null;
    }

    #[\ReturnTypeWillChange]
    public function lastInsertId($name = null): bool|string
    {
        $name = $name ?? 'id';

        return $this->lastInsertIds[$name] ?? false;
    }

    public function beginTransaction(): bool
    {
        throw new \PDOException(
            'D1 does not support transactions over stateless HTTP.'
        );
    }

    public function commit(): bool
    {
        throw new \PDOException(
            'D1 does not support transactions over stateless HTTP.'
        );
    }

    public function rollBack(): bool
    {
        throw new \PDOException(
            'D1 does not support transactions over stateless HTTP.'
        );
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function exec($statement): int|false
    {
        $shouldRetry = $this->shouldRetryFor($statement);
        $response = $this->connector->databaseQuery($statement, [], $shouldRetry);

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
                $exception = new \PDOException($errorMessage, (int) $errorCode);
                $exception->errorInfo = $this->errorInfo;

                throw $exception;
            }

            return false;
        }

        $this->errorInfo = ['00000', null, null];

        $resultData = $response->json('result.0') ?? [];

        if (isset($resultData['meta']['last_row_id'])) {
            $this->setLastInsertId(null, $resultData['meta']['last_row_id']);
        }

        return $resultData['meta']['changes'] ?? 0;
    }

    public function quote($value, $type = PDO::PARAM_STR): string|false
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

    public function errorCode(): ?string
    {
        return $this->errorInfo[0] ?? null;
    }

    public function errorInfo(): array
    {
        return $this->errorInfo;
    }

    public function getAttribute($attribute): mixed
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

    public function setAttribute($attribute, $value): bool
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
     * Only idempotent read queries (SELECT/WITH) are safe to retry.
     * Retrying mutations (INSERT, UPDATE, DELETE) risks duplicate data.
     *
     * @internal Used by D1PdoStatement. Not intended for end-user consumption.
     */
    public function shouldRetryFor(string $statement): bool
    {
        // If retry is globally disabled, respect that
        if (!$this->useRetry) {
            return false;
        }

        // Whitelist: only retry idempotent read queries
        return (bool) preg_match('/^\s*(SELECT|WITH)\b/i', $statement);
    }
}
