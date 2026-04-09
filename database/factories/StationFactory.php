<?php

namespace Database\Factories;

use App\Models\Station;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Station>
 */
class StationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement([
            'Kitchen',
            'Bar',
            'Kasir',
            'Dessert',
        ]);

        return [
            'owner_id' => null,
            'name' => $name,
        ];
    }
}
