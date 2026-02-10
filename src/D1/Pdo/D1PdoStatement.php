<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\D1\Pdo;

use Ntanduy\CFD1\D1\Pdo\Concerns\MapsSqlState;
use PDO;
use PDOException;
use PDOStatement;

class D1PdoStatement extends PDOStatement
{
    use MapsSqlState;

    protected int $fetchMode = PDO::FETCH_ASSOC;

    protected array $bindings = [];

    protected array $responses = [];

    protected array $results = [];

    protected int $currentResultIndex = 0;

    protected int $affectedRows = 0;

    public function __construct(
        protected D1Pdo &$pdo,
        protected string $query,
        protected array $options = [],
    ) {
        //
    }

    public function setFetchMode(int $mode, mixed ...$args): bool
    {
        $this->fetchMode = $mode;

        return true;
    }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->bindings[$param] = match ($type) {
            PDO::PARAM_STR => $value === null ? null : (string) $value,
            PDO::PARAM_BOOL => (bool) $value,
            PDO::PARAM_INT => (int) $value,
            PDO::PARAM_NULL => null,
            PDO::PARAM_LOB => $this->convertLOBToString($value),
            default => $value,
        };

        return true;
    }

    protected function convertLOBToString($value): string
    {
        if (is_resource($value)) {
            $content = stream_get_contents($value);
            if ($content === false) {
                throw new PDOException('Failed to read LOB stream');
            }

            return $content;
        }

        return (string) $value;
    }

    public function execute(?array $params = null): bool
    {
        if ($params !== null) {
            $this->bindings = $params;
        }

        // NEVER reorder — keep PDO behavior
        $bindings = array_values($this->bindings);

        $shouldRetry = $this->pdo->shouldRetryFor($this->query);

        $response = $this->pdo->d1()->databaseQuery(
            $this->query,
            $bindings,
            $shouldRetry,
        );

        if ($response->failed() || !$response->json('success')) {
            $errorCode = $response->json('errors.0.code');
            $errorMessage = $response->json('errors.0.message', 'Unknown error');

            $sqlState = $this->mapErrorToSqlState($errorMessage);

            // Throw exception if error mode is set to EXCEPTION
            if ($this->pdo->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION) {
                $exception = new PDOException($errorMessage, (int) $errorCode);
                $exception->errorInfo = [$sqlState, $errorCode, $errorMessage];

                throw $exception;
            }

            return false;
        }

        $this->responses = $response->json('result');
        $this->results = $this->rowsFromResponses();
        $this->currentResultIndex = 0;
        $this->affectedRows = array_reduce(
            $this->responses,
            fn ($sum, $response) => $sum + ($response['meta']['changes'] ?? 0),
            0
        );

        if (!empty($this->responses)) {
            $lastId = end($this->responses)['meta']['last_row_id'] ?? null;
            if ($lastId) {
                $this->pdo->setLastInsertId(null, $lastId);
            }
        }

        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if ($cursorOrientation === PDO::FETCH_ORI_ABS) {
            $this->currentResultIndex = $cursorOffset;
        } elseif ($cursorOrientation === PDO::FETCH_ORI_REL) {
            $this->currentResultIndex += $cursorOffset;
        }

        if (!isset($this->results[$this->currentResultIndex])) {
            return false;
        }

        $row = $this->results[$this->currentResultIndex];
        $this->currentResultIndex++;

        $fetchMode = $mode === PDO::FETCH_DEFAULT ? $this->fetchMode : $mode;

        return $this->formatRow($row, $fetchMode);
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $fetchMode = $mode === PDO::FETCH_DEFAULT ? $this->fetchMode : $mode;

        $rows = array_slice($this->results, $this->currentResultIndex);
        $this->currentResultIndex = count($this->results);

        return array_map(fn ($row) => $this->formatRow($row, $fetchMode), $rows);
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->fetch(PDO::FETCH_NUM);

        if ($row === false) {
            return false;
        }

        return $row[$column] ?? null;
    }

    public function rowCount(): int
    {
        if ($this->isSelectQuery()) {
            return 0;
        }

        return $this->affectedRows;
    }

    protected function isSelectQuery(): bool
    {
        return preg_match('/^\s*(SELECT|WITH)\b/i', $this->query) === 1;
    }

    protected function rowsFromResponses(): array
    {
        $rows = [];
        foreach ($this->responses as $response) {
            array_push($rows, ...($response['results'] ?? []));
        }

        return $rows;
    }

    protected function formatRow($row, $mode)
    {
        return match ($mode) {
            PDO::FETCH_ASSOC => $row,
            PDO::FETCH_OBJ => (object) $row,
            PDO::FETCH_NUM => array_values($row),
            PDO::FETCH_BOTH => array_merge($row, array_values($row)),
            default => throw new PDOException('Unsupported fetch mode.'),
        };
    }
}
