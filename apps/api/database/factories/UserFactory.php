<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'referral_code' => 'LP'.Str::upper(Str::random(10)),
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            'status' => 'active',
            'remember_token' => Str::random(10),
        ];
    }
}
