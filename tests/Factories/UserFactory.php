<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\Test\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Ntanduy\CFD1\Test\Models\User;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition()
    {
        return [
            'name' => 'Name' . Str::random(5),
            'email' => Str::random(5) . '@gmail.com',
            'password' => Hash::make('TanDuy03'),
            'remember_token' => Str::random(10),
        ];
    }
}
