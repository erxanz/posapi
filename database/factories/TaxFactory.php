<?php

namespace Database\Factories;

use App\Models\Tax;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tax>
 */
class TaxFactory extends Factory
{
    protected $model = Tax::class;

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
            'outlet_id' => fn () => \App\Models\Outlet::inRandomOrder()->first()?->id ?? \App\Models\Outlet::factory()->create()->id,
            'active' => fake()->boolean(90),
        ];
    }
}
