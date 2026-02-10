<?php

namespace Ntanduy\CFD1\Test\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ntanduy\CFD1\Test\Models\User;
use Ntanduy\CFD1\Test\TestCase;

class LaravelIntegrationTest extends TestCase
{
    public function test_query_builder_crud()
    {
        // Create
        $id = DB::table('users')->insertGetId([
            'name' => 'Builder User',
            'email' => 'builder@example.com',
            'password' => 'secret',
        ]);

        expect($id)->toBeNumeric();

        // Read
        $user = DB::table('users')->where('id', $id)->first();
        expect($user)->not->toBeNull();
        expect($user->name)->toBe('Builder User');

        // Update
        $affected = DB::table('users')->where('id', $id)->update(['name' => 'Updated Builder']);
        expect($affected)->toBe(1);

        $user = DB::table('users')->where('id', $id)->first();
        expect($user->name)->toBe('Updated Builder');

        // Delete
        $deleted = DB::table('users')->where('id', $id)->delete();
        expect($deleted)->toBe(1);

        $user = DB::table('users')->where('id', $id)->first();
        expect($user)->toBeNull();
    }

    public function test_eloquent_crud()
    {
        // Create
        $user = User::create([
            'name' => 'Eloquent User',
            'email' => 'eloquent@example.com',
            'password' => 'secret',
        ]);

        expect($user->exists)->toBeTrue();

        // Read
        $found = User::find($user->id);
        expect($found->name)->toBe('Eloquent User');

        // Update
        $user->update(['name' => 'Updated Eloquent']);

        $found = User::find($user->id);
        expect($found->name)->toBe('Updated Eloquent');

        // Delete
        $user->delete();

        $found = User::find($user->id);
        expect($found)->toBeNull();
    }

    public function test_migrations_run_through_driver()
    {
        // This tests that the driver can handle Schema builder commands
        // The Mock will just return success for CREATE TABLE

        Schema::dropIfExists('test_table');

        Schema::create('test_table', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        expect(Schema::hasTable('test_table'))->toBeTrue();
        // Note: Schema::hasTable checks information_schema or sqlite_master. 
        // Our Mock might need to handle that SELECT to return true.
        // Let's check if Mock handles 'select * from sqlite_master' or similar.
        // If not, this expectation might fail. 
        // For now, let's just assert the create command didn't throw exception.
    }

    public function test_raw_queries()
    {
        DB::insert('insert into users (name, email, password) values (?, ?, ?)', ['Raw User', 'raw@example.com', 'secret']);

        $users = DB::select('select * from users where email = ?', ['raw@example.com']);

        expect($users)->toHaveCount(1);
        expect($users[0]->name)->toBe('Raw User');
    }

    public function test_transactions_throw_pdo_exception()
    {
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('D1 does not support transactions over stateless HTTP.');

        DB::beginTransaction();
    }
}
