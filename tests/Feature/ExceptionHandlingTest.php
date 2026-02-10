<?php

declare(strict_types=1);

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
            'password' => 'secret',
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23000');

        User::create([
            'name' => 'User 2',
            'email' => 'duplicate@example.com',
            'password' => 'secret',
        ]);
    }

    public function test_table_not_found_throws_exception()
    {
        $this->expectException(QueryException::class);

        DB::table('non_existent_table')->get();
    }

    public function test_transactions_are_not_supported()
    {
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('D1 does not support transactions over stateless HTTP.');

        DB::beginTransaction();
    }
}
