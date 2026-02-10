<?php

namespace Ntanduy\CFD1\Test;

use Illuminate\Support\Facades\DB;
use Ntanduy\CFD1\Test\Models\User;

class D1Test extends TestCase
{
    public function test_d1_database_select()
    {
        // Use Laravel's factory helper function
        $user = User::factory()->create();

        // Test that user was created successfully
        expect($user)->not->toBeNull();
        expect($user->id)->not->toBeNull();
        expect($user->name)->not->toBeEmpty();
        expect($user->email)->not->toBeEmpty();

        // Test database retrieval
        $foundUser = User::find($user->id);
        expect($foundUser)->not->toBeNull();
        expect($foundUser->id)->toBe($user->id);
        expect($foundUser->email)->toBe($user->email);
    }

    public function test_d1_database_transaction_throws_exception()
    {
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('D1 does not support transactions over stateless HTTP.');

        DB::transaction(function () {
            User::factory()->create();
        });
    }
}
