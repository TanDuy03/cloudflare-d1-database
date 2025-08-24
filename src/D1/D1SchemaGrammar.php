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
}
