<?php

namespace Ntanduy\CFD1\D1;

use Illuminate\Database\Schema\Grammars\SQLiteGrammar;

class D1SchemaGrammar extends SQLiteGrammar
{
    /**
     * The connection instance.
     */
    protected $connection;

    /**
     * Cache for method signature detection
     */
    protected static ?bool $supportsSchemaParameter = null;

    /**
     * Create a new database schema grammar instance.
     */
    public function __construct($connection = null)
    {
        $this->connection = $connection;

        // Detect method signature once
        if (self::$supportsSchemaParameter === null) {
            self::$supportsSchemaParameter = $this->detectSchemaParameterSupport();
        }
    }

    /**
     * Detect if parent methods support schema parameter (Laravel 12+)
     * Uses reflection to check method signature once
     */
    protected function detectSchemaParameterSupport(): bool
    {
        try {
            $reflection = new \ReflectionMethod(get_parent_class($this), 'compileDropAllTables');
            return $reflection->getNumberOfParameters() > 0;
        } catch (\ReflectionException $e) {
            return false;
        }
    }

    protected function replaceSystemTable(string $sql): string
    {
        return str_replace('sqlite_master', 'sqlite_schema', $sql);
    }

    /**
     * Compile the SQL needed to drop all tables.
     * Compatible with Laravel 10, 11, 12
     */
    public function compileDropAllTables($schema = null)
    {
        $result = self::$supportsSchemaParameter
            ? parent::compileDropAllTables($schema)
            : parent::compileDropAllTables();


        return $this->replaceSystemTable($result);
    }

    /**
     * Compile the SQL needed to drop all views.
     * Compatible with Laravel 10, 11, 12
     */
    public function compileDropAllViews($schema = null)
    {
        $result = self::$supportsSchemaParameter
            ? parent::compileDropAllViews($schema)
            : parent::compileDropAllViews();

        return $this->replaceSystemTable($result);
    }

    /**
     * Compile the SQL needed to retrieve all tables.
     * Compatible with Laravel 10
     */
    public function compileGetAllTables()
    {
        return $this->replaceSystemTable(parent::compileGetAllTables());
    }

    /**
     * Compile the SQL needed to retrieve all views.
     * Compatible with Laravel 10
     */
    public function compileGetAllViews()
    {
        return $this->replaceSystemTable(parent::compileGetAllViews());
    }

    /**
     * Compile the query to determine if a table exists.
     * Compatible with Laravel 10, 11, 12
     *
     * @param string|null $schema
     * @param string|null $table
     * @return string
     */
    public function compileTableExists($schema = null, $table = null)
    {
        // Laravel 10 support (no arguments passed)
        if (func_num_args() === 0) {
            return "select * from sqlite_schema where type = 'table' and name = ?";
        }

        // Laravel 11 support (1 argument passed: $table)
        // In Laravel 11, the signature is compileTableExists($table)
        if (func_num_args() === 1) {
            $table = $schema; // The first argument is actually the table
            $schema = null;   // Schema is default
        }

        // Laravel 12+ support (2 arguments passed: $schema, $table)
        // We implement this directly to avoid deprecation warnings in some Laravel versions
        // when passing null schema, and to ensure sqlite_schema is used.
        return sprintf(
            'select exists (select 1 from %s.sqlite_schema where name = %s and type = \'table\') as "exists"',
            $this->wrapValue($schema ?? 'main'),
            $this->quoteString($table)
        );
    }
}
