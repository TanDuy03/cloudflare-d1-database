<?php

namespace Ntanduy\CFD1\D1;

use Illuminate\Database\Schema\Builder;
use Illuminate\Database\SQLiteConnection;
use Ntanduy\CFD1\CloudflareD1Connector;
use Ntanduy\CFD1\D1\Pdo\D1Pdo;

class D1Connection extends SQLiteConnection
{
    public function __construct(
        protected CloudflareD1Connector $connector,
        array $config = [],
    ) {
        parent::__construct(
            fn() => $this->createD1Pdo(),
            $config['database'] ?? '',
            $config['prefix'] ?? '',
            $config,
        );
    }

    protected function getDefaultSchemaGrammar()
    {
        return new D1SchemaGrammar($this);
    }

    /**
     * Start a new database transaction.
     * D1 supports nested transactions through transaction depth tracking.
     */
    public function beginTransaction(): void
    {
        ++$this->transactions;

        if ($this->transactions === 1) {
            $this->getPdo()->beginTransaction();
        }

        $this->fireConnectionEvent('beganTransaction');
    }

    public function d1(): CloudflareD1Connector
    {
        return $this->connector;
    }

    /**
     * Get the D1 PDO instance for reads.
     */
    public function getReadPdo(): D1Pdo
    {
        if ($this->readPdo instanceof \Closure) {
            $this->readPdo = ($this->readPdo)();
        }

        if ($this->readPdo === null) {
            return $this->getPdo();
        }

        return $this->readPdo;
    }

    /**
     * Get the D1 PDO instance.
     */
    public function getPdo(): D1Pdo
    {
        if ($this->pdo instanceof \Closure) {
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
