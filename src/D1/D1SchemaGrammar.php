<?php

namespace Ntanduy\CFD1\D1;

use Illuminate\Database\Schema\Grammars\SQLiteGrammar;

class D1SchemaGrammar extends SQLiteGrammar
{
    // Removed compileTableExists override for Laravel version compatibility
    // The parent SQLiteGrammar method works fine with sqlite_master

    /**
     * Compile the SQL needed to drop all tables.
     *
     * @param string|null $schema
     *
     * @return string
     */
    public function compileDropAllTables($schema = null)
    {
        return sprintf(
            "delete from %s.sqlite_schema where type in ('table', 'index', 'trigger')",
            $this->wrapValue($schema ?? 'main')
        );
    }

    /**
     * Compile the SQL needed to drop all views.
     *
     * @param string|null $schema
     *
     * @return string
     */
    public function compileDropAllViews($schema = null)
    {
        return sprintf(
            "delete from %s.sqlite_schema where type in ('view')",
            $this->wrapValue($schema ?? 'main')
        );
    }

    // Removed compileViews override for Laravel version compatibility
    // The parent SQLiteGrammar method works fine with sqlite_master

    // Removed compileLegacyTables override for Laravel version compatibility
    // The parent SQLiteGrammar method works fine with sqlite_master

    // Removed compileSqlCreateStatement override for Laravel version compatibility
    // The parent SQLiteGrammar method works fine with sqlite_master
}
