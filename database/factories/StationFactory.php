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
        return [
            'owner_id' => null,
            'name' => fake()->randomElement([
                'Dapur Utama',
                'Bar Minuman',
                'Kasir Utama',
                'Dessert Station',
                'Appetizer',
            ]),
        ];
    }

    public function dapur(): static
    {
        return $this->state(fn () => [
            'name' => 'Dapur Utama',
        ]);
    }

    public function bar(): static
    {
        return $this->state(fn () => [
            'name' => 'Bar Minuman',
        ]);
    }

    public function kasir(): static
    {
        return $this->state(fn () => [
            'name' => 'Kasir Utama',
        ]);
    }

}
