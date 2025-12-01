<?php

namespace Ntanduy\CFD1\D1;

use Illuminate\Database\Schema\Grammars\SQLiteGrammar;
use Illuminate\Support\Str;

class D1SchemaGrammar extends SQLiteGrammar
{
    /**
     * The connection instance.
     */
    protected $connection;

    /**
     * Create a new database schema grammar instance.
     */
    public function __construct($connection = null)
    {
        $this->connection = $connection;
    }

    /**
     * Compile the SQL needed to drop all tables.
     * Compatible with Laravel 10, 11, 12
     */
    public function compileDropAllTables($schema = null)
    {
        try {
            // Try Laravel 12 signature first
            $result = parent::compileDropAllTables($schema);
        } catch (\ArgumentCountError $e) {
            // Fallback to Laravel 10-11 signature
            $result = parent::compileDropAllTables();
        }

        return Str::of($result)
            ->replace('sqlite_master', 'sqlite_schema')
            ->__toString();
    }

    /**
     * Compile the SQL needed to drop all views.
     * Compatible with Laravel 10, 11, 12
     */
    public function compileDropAllViews($schema = null)
    {
        try {
            // Try Laravel 12 signature first
            $result = parent::compileDropAllViews($schema);
        } catch (\ArgumentCountError $e) {
            // Fallback to Laravel 10-11 signature
            $result = parent::compileDropAllViews();
        }

        return Str::of($result)
            ->replace('sqlite_master', 'sqlite_schema')
            ->__toString();
    }

    /**
     * Compile the SQL needed to retrieve all tables.
     * Compatible with Laravel 10
     */
    public function compileGetAllTables()
    {
        // Get the parent's query and replace sqlite_master with sqlite_schema for D1
        $result = parent::compileGetAllTables();

        return Str::of($result)
            ->replace('sqlite_master', 'sqlite_schema')
            ->__toString();
    }

    /**
     * Compile the SQL needed to retrieve all views.
     * Compatible with Laravel 10
     */
    public function compileGetAllViews()
    {
        // Get the parent's query and replace sqlite_master with sqlite_schema for D1
        $result = parent::compileGetAllViews();

        return Str::of($result)
            ->replace('sqlite_master', 'sqlite_schema')
            ->__toString();
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

        // Laravel 11+ support
        // We implement this directly to avoid deprecation warnings in some Laravel versions
        // when passing null schema, and to ensure sqlite_schema is used.
        return sprintf(
            'select exists (select 1 from %s.sqlite_schema where name = %s and type = \'table\') as "exists"',
            $this->wrapValue($schema ?? 'main'),
            $this->quoteString($table)
        );
    }
}
