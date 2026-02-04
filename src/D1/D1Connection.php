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
        protected $config = [],
    ) {
        parent::__construct(
            new D1Pdo('sqlite::memory:', $this->connector),
            $config['database'] ?? '',
            $config['prefix'] ?? '',
            $config,
        );
    }

    protected function getDefaultSchemaGrammar()
    {
        $grammar = new D1SchemaGrammar($this);

        if ($this->getTablePrefix()) {
            $grammar->setTablePrefix($this->getTablePrefix());
        }

        return $grammar;
    }

    /**
     * Get the schema builder for the connection.
     * Disables retry for DDL operations to speed up migrations.
     */
    public function getSchemaBuilder(): Builder
    {
        $this->getPdo()->setRetry(false);

        return parent::getSchemaBuilder();
    }

    public function d1(): CloudflareD1Connector
    {
        return $this->connector;
    }

    /**
     * Get the D1 PDO instance.
     */
    public function getPdo(): D1Pdo
    {
        return $this->pdo;
    }
}
