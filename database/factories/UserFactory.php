<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),

            // default password
            'password' => static::$password ??= Hash::make('password'),

            'remember_token' => Str::random(10),

            'pin' => fake()->numerify('######'),
            'outlet_id' => null,

            'role' => fake()->randomElement([
                'developer',
                'manager',
                'karyawan'
            ]),
        ];
    }

    // ===== ROLE =====

    public function developer(): static
    {
        return $this->state(fn () => [
            'role' => 'developer',
            'outlet_id' => null,
        ]);
    }

    public function manager(): static
    {
        return $this->state(fn () => [
            'role' => 'manager',
        ]);
    }

    public function karyawan(): static
    {
        return $this->state(fn () => [
            'role' => 'karyawan',
        ]);
    }
}
