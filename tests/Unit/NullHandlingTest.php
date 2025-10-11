<?php

namespace Ntanduy\CFD1\Test\Unit;

use Illuminate\Support\Facades\DB;
use Ntanduy\CFD1\Test\TestCase;

class NullHandlingTest extends TestCase
{
    public function test_handles_null_values_correctly_in_database_operations()
    {
        // Test using raw SQL with parameter binding on the users table
        // which already exists from the TestCase migrations
        
        // Insert a record with NULL remember_token using parameter binding
        $insertResult = DB::insert('INSERT INTO users (name, email, email_verified_at, password, remember_token) VALUES (?, ?, ?, ?, ?)', [
            'Test User',
            'test@example.com',
            now(),
            'password',
            null  // This should be NULL, not an empty string
        ]);
        dump('Insert result:', $insertResult);
        
        // Retrieve the record using raw SQL to see exactly what was stored
        $result = DB::select('SELECT * FROM users WHERE email = ?', ['test@example.com']);
        
        dump('Result count:', count($result));
        if (count($result) > 0) {
            dump('User record:', $result[0]);
            dump('remember_token value:', $result[0]->remember_token);
            dump('remember_token type:', gettype($result[0]->remember_token));
        }
        
        // The remember_token should be NULL, not an empty string
        $this->assertGreaterThan(0, count($result), 'User should exist');
        $this->assertNull($result[0]->remember_token, 'remember_token should be NULL');
        $this->assertNotSame('', $result[0]->remember_token, 'remember_token should not be empty string');
    }
}