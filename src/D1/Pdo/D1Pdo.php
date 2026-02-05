<?php

namespace Ntanduy\CFD1\D1\Pdo;

use Ntanduy\CFD1\CloudflareD1Connector;
use PDO;
use PDOStatement;

class D1Pdo extends PDO
{
    protected array $lastInsertIds = [];

    protected bool $inTransaction = false;

    protected array $errorInfo = ['00000', null, null];

    protected array $attributes = [];

    protected bool $useRetry = true;

    public function __construct(
        protected string $dsn,
        protected CloudflareD1Connector $connector,
    ) {
        parent::__construct('sqlite::memory:');
    }

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
        $this->lastInsertIds[$name] = $value;
    }

    public function lastInsertId($name = null): bool|string
    {
        $name = $name ?? 'id';
        return $this->lastInsertIds[$name] ?? false;
    }

    public function beginTransaction(): bool
    {
        if ($this->inTransaction) {
            throw new \PDOException('There is already an active transaction');
        }

        return $this->inTransaction = true;
    }

    public function commit(): bool
    {
        if (!$this->inTransaction) {
            throw new \PDOException('There is no active transaction');
        }

        $this->inTransaction = false;

        return true;
    }

    public function rollBack(): bool
    {
        if (!$this->inTransaction) {
            throw new \PDOException('There is no active transaction');
        }

        $this->inTransaction = false;

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function exec($statement): int|false
    {
        $response = $this->connector->databaseQuery($statement, [], $this->useRetry);

        if ($response->failed() || !$response->json('success')) {
            $errorCode = $response->json('errors.0.code');
            $errorMessage = $response->json('errors.0.message', 'Unknown error');

            // Map D1 error codes to SQLSTATE codes
            $sqlState = match (true) {
                str_contains($errorMessage, 'UNIQUE constraint') => '23000',
                str_contains($errorMessage, 'NOT NULL constraint') => '23000',
                str_contains($errorMessage, 'syntax error') => '42000',
                str_contains($errorMessage, 'no such table') => '42S02',
                str_contains($errorMessage, 'no such column') => '42S22',
                default => 'HY000'
            };

            $this->errorInfo = [
                $sqlState,
                $errorCode,
                $errorMessage
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

        return "'" . str_replace("'", "''", (string) $value) . "'";
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
}
