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
            'name' => fake()->randomElement(['Shift Pagi', 'Shift Malam']),

            'start_time' => fake()->randomElement(['07:00:00', '15:00:00']),
            'end_time' => match(fake()->randomElement(['07:00:00', '15:00:00'])) {
                '07:00:00' => '15:00:00',
                '15:00:00' => '23:00:00',
                default => '23:00:00',
            },

        ];
    }

    public function pagi()
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Shift Pagi',
            'start_time' => '07:00:00',
            'end_time' => '15:00:00',
        ]);
    }


    public function malam()
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Shift Malam',
            'start_time' => '15:00:00',
            'end_time' => '23:00:00',
        ]);
    }

    public function siang()
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Shift Siang',
            'start_time' => '11:00:00',
            'end_time' => '19:00:00',
        ]);
    }
}

