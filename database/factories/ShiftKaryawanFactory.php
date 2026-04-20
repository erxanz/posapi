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
        $startHour = fake()->numberBetween(8, 15);
        $daysAgo = fake()->numberBetween(1, 30); // sebulan

        $minutesOffset = fake()->numberBetween(0, 30);
        $startedAt = now()->subDays($daysAgo)->setTime($startHour, $minutesOffset);

        return [
            'outlet_id' => null,
            'user_id' => null,
            'shift_id' => null,
            'uang_awal' => fake()->numberBetween(100000, 500000),
            'started_at' => $startedAt,
            'ended_at' => null,
            'opening_balance' => fake()->numberBetween(100000, 500000),
            'closing_balance_system' => null,
            'closing_balance_actual' => null,
            'difference' => 0,
            'notes' => null,
            'status' => 'active',
        ];

    }

    public function closed()
    {
        $endHour = fake()->numberBetween(15, 22);

        $endMinutesOffset = fake()->numberBetween(0, 30);
        $endedAt = fake()->dateTimeBetween('-30 days', 'now')->setTime($endHour, $endMinutesOffset);

        return $this->state(fn (array $attributes) => [
            'status' => 'closed',
            'ended_at' => $endedAt,
            'closing_balance_system' => $attributes['opening_balance'] + fake()->numberBetween(200000, 2000000), // +sales
            'closing_balance_actual' => $attributes['opening_balance'] + fake()->numberBetween(150000, 2500000), // slight variance
'difference' => $attributes['closing_balance_actual'] - $attributes['closing_balance_system'], // actual - system

            'notes' => fake()->boolean(30) ? 'Selisih kecil akibat pembulatan kembalian.' : null,
        ]);

    }
}
