<?php

namespace Ntanduy\CFD1\D1\Pdo;

use Illuminate\Support\Arr;
use PDO;
use PDOException;
use PDOStatement;

class D1PdoStatement extends PDOStatement
{
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
            default => $value,
        };

        return true;
    }

    public function execute(?array $params = null): bool
    {
        if ($params !== null) {
            $this->bindings = $params;
        }

        // NEVER reorder — keep PDO behavior
        $bindings = array_values($this->bindings);

        $response = $this->pdo->d1()->databaseQuery(
            $this->query,
            $bindings,
        );

        if ($response->failed() || !$response->json('success')) {
            throw new PDOException(
                (string) $response->json('errors.0.message'),
                (int) $response->json('errors.0.code'),
            );
        }

        $this->responses = $response->json('result');
        $this->results = $this->rowsFromResponses();
        $this->currentResultIndex = 0;
        $this->affectedRows = collect($this->responses)->sum('meta.changes');

        $lastId = Arr::get(Arr::last($this->responses), 'meta.last_row_id', null);

        if (!in_array($lastId, [0, null])) {
            $this->pdo->setLastInsertId(value: $lastId);
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

        return array_map(fn($row) => $this->formatRow($row, $fetchMode), $rows);
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
        return collect($this->responses)
            ->map(fn($response) => $response['results'] ?? [])
            ->collapse()
            ->toArray();
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
