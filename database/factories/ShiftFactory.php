<?php

namespace Database\Factories;

use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
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
            'name' => $this->faker->randomElement(['Shift Pagi', 'Shift Malam']),
            'start_time' => $this->faker->randomElement(['08:00:00', '15:00:00']),
            'end_time' => match($this->faker->randomElement(['08:00:00', '15:00:00'])) {
                '08:00:00' => '15:00:00',
                '15:00:00' => '22:00:00',
                default => '22:00:00',
            },
        ];
    }

    public function pagi()
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Shift Pagi',
            'start_time' => '08:00:00',
            'end_time' => '15:00:00',
        ]);
    }

    public function malam()
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Shift Malam',
            'start_time' => '15:00:00',
            'end_time' => '22:00:00',
        ]);
    }
}
