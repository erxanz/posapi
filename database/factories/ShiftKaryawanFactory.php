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
        $status = fake()->randomElement(['draft', 'active', 'closed']);
        $startedAt = match ($status) {
            'active' => now()->subMinutes(fake()->numberBetween(5, 240)),
            'closed' => now()->subHours(fake()->numberBetween(1, 8)),
            default => null,
        };

        return [
            'outlet_id' => null,
            'user_id' => null,
            'shift_ke' => fake()->randomElement([1, 2]),
            'uang_awal' => fake()->numberBetween(0, 500000),
            'started_at' => $startedAt,
            'ended_at' => $status === 'closed' ? now() : null,
            'status' => $status,
        ];
    }
}
