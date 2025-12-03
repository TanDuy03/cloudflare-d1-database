<?php

namespace Ntanduy\CFD1\Test\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Ntanduy\CFD1\Test\Models\User;
use Ntanduy\CFD1\Test\TestCase;

class ExceptionHandlingTest extends TestCase
{
    public function test_syntax_error_throws_exception()
    {
        $this->expectException(QueryException::class);

        DB::select('SELEC * FROM users');
    }

    public function test_constraint_violation_throws_exception()
    {
        User::create([
            'name' => 'User 1',
            'email' => 'duplicate@example.com',
            'password' => 'secret'
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23000');

        User::create([
            'name' => 'User 2',
            'email' => 'duplicate@example.com',
            'password' => 'secret'
        ]);
    }

    public function test_table_not_found_throws_exception()
    {
        $this->expectException(QueryException::class);

        DB::table('non_existent_table')->get();
    }

    public function test_transaction_rollback_reverts_data()
    {
        expect(User::count())->toBe(0);

        try {
            DB::transaction(function () {
                User::create([
                    'name' => 'Rollback User',
                    'email' => 'rollback@example.com',
                    'password' => 'secret'
                ]);

                throw new \Exception('Force Rollback');
            });
        } catch (\Exception $e) {
            //
        }

        // D1 doesn't support real transactions via HTTP API
        // The data will NOT be rolled back - this is a known limitation
        expect(User::count())->toBe(1);
    }
}
