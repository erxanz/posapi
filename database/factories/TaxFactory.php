<?php

namespace Database\Factories;

use App\Models\Tax;
use App\Models\Outlet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tax>
 */
class TaxFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'rate' => fake()->randomFloat(4, 0, 25),
            'type' => fake()->randomElement(['percentage', 'fixed']),
            'outlet_id' => fn () => Outlet::inRandomOrder()->first()?->id ?? 1,
            'active' => fake()->boolean(90),
        ];
    }
}
