<?php

namespace Ntanduy\CFD1\Test\Unit;

use Illuminate\Support\Facades\DB;
use Ntanduy\CFD1\Test\TestCase;

class NullHandlingTest extends TestCase
{
    public function test_handles_null_values_correctly_in_database_operations()
    {
        // Test that our bindValue method correctly handles NULL values
        // by testing the D1PdoStatement directly
        
        $pdo = DB::connection('d1')->getPdo();
        $statement = new \Ntanduy\CFD1\D1\Pdo\D1PdoStatement($pdo, 'test query');
        
        // Test that NULL values are preserved when PDO::PARAM_STR is used
        $statement->bindValue(1, null, \PDO::PARAM_STR);
        
        // Use reflection to access the protected bindings property
        $reflection = new \ReflectionClass($statement);
        $bindingsProperty = $reflection->getProperty('bindings');
        $bindingsProperty->setAccessible(true);
        $bindings = $bindingsProperty->getValue($statement);
        
        // The binding should be NULL, not an empty string
        $this->assertNull($bindings[1], 'NULL value should be preserved as NULL');
        $this->assertNotSame('', $bindings[1], 'NULL should not be converted to empty string');
        
        // Test that regular strings still work
        $statement->bindValue(2, 'test string', \PDO::PARAM_STR);
        $bindings = $bindingsProperty->getValue($statement);
        $this->assertSame('test string', $bindings[2], 'String values should be preserved');
    }
}