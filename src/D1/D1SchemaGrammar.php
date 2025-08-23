<?php

namespace Ntanduy\CFD1\D1;

use Illuminate\Database\Schema\Grammars\SQLiteGrammar;
use Illuminate\Support\Str;

class D1SchemaGrammar extends SQLiteGrammar
{
    public function compileTableExists($schema, $table)
    {
        return Str::of(parent::compileTableExists($schema, $table))
            ->replace('sqlite_master', 'sqlite_schema')
            ->__toString();
    }

    public function compileDropAllTables($schema = null)
    {
        return Str::of(parent::compileDropAllTables($schema))
            ->replace('sqlite_master', 'sqlite_schema')
            ->__toString();
    }

    public function compileDropAllViews($schema = null)
    {
        return Str::of(parent::compileDropAllViews($schema))
            ->replace('sqlite_master', 'sqlite_schema')
            ->__toString();
    }

    public function compileGetAllTables($schema = null)
    {
        return Str::of(parent::compileGetAllTables($schema))
            ->replace('sqlite_master', 'sqlite_schema')
            ->__toString();
    }

    public function compileGetAllViews($schema = null)
    {
        return Str::of(parent::compileGetAllViews($schema))
            ->replace('sqlite_master', 'sqlite_schema')
            ->__toString();
    }
}
