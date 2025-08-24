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

    public function test_d1_database_transaction()
    {
        $user = User::factory()->create();

        DB::transaction(function () use ($user) {
            $newUser = User::factory()->create();
            $dbUser = User::where('email', $user->email)->first();

            expect($dbUser)->not->toBeNull();
            expect($dbUser->id)->toBe($user->id);

            return $dbUser;
        });
    }
}
