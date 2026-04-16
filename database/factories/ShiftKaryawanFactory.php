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
        $shiftKe = fake()->randomElement([1, 2]);
        $daysAgo = fake()->numberBetween(1, 30); // sebulan
        $startHour = $shiftKe === 1 ? 8 : 15;
        $minutesOffset = fake()->numberBetween(0, 30);
        $startedAt = now()->subDays($daysAgo)->setTime($startHour, $minutesOffset);

        return [
            'outlet_id' => null,
            'user_id' => null,
            'shift_ke' => $shiftKe,
            'uang_awal' => fake()->numberBetween(100000, 500000),
            'started_at' => $startedAt,
            'ended_at' => null,
            'opening_balance' => fake()->numberBetween(100000, 500000),
            'status' => 'active',
        ];
    }

    public function closed()
    {
        $shiftKe = $this->faker->randomElement([1, 2]);
        $endHour = $shiftKe === 1 ? 15 : 22;
        $endMinutesOffset = fake()->numberBetween(0, 30);
        $endedAt = $this->faker->dateTimeBetween('-30 days', 'now')->setTime($endHour, $endMinutesOffset);

        return $this->state(fn (array $attributes) => [
            'status' => 'closed',
            'ended_at' => $endedAt,
            'closing_balance_system' => $attributes['opening_balance'] + fake()->numberBetween(0, 100000),
            'closing_balance_actual' => $attributes['opening_balance'] + fake()->numberBetween(-50000, 150000),
        ]);
    }
}
