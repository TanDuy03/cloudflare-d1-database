<?php

namespace Ntanduy\CFD1\D1;

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
        ($grammar = new D1SchemaGrammar())->setConnection($this);

        return $this->withTablePrefix($grammar);
    }

    public function d1(): CloudflareD1Connector
    {
        return $this->connector;
    }
}
