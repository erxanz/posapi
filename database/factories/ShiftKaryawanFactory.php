<?php

namespace Database\Factories;

use App\Models\ShiftKaryawan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShiftKaryawan>
 */
class ShiftKaryawanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'outlet_id' => null,
            'user_id' => null,
            'shift_ke' => fake()->numberBetween(1, 3),
            'uang_awal' => fake()->numberBetween(100000, 500000),
            'started_at' => now()->subHours(fake()->numberBetween(1, 12))->subMinutes(fake()->numberBetween(0, 59)),
            'ended_at' => null,
            'opening_balance' => fake()->numberBetween(100000, 500000),
            'status' => 'active',
        ];
    }

    public function closed()
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'closed',
            'ended_at' => now(),
            'closing_balance_system' => $attributes['opening_balance'] + fake()->numberBetween(0, 100000),
            'closing_balance_actual' => $attributes['opening_balance'] + fake()->numberBetween(-50000, 150000),
        ]);
    }
}
