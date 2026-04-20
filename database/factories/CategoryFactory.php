<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Makanan', 'Minuman', 'Snack', 'Dessert', 'Makanan Pembuka']),
            'owner_id' => null,
        ];
    }

    public function makanan(): static
    {
        return $this->state(fn () => [
            'name' => 'Makanan',
        ]);
    }

    public function minuman(): static
    {
        return $this->state(fn () => [
            'name' => 'Minuman',
        ]);
    }

    public function snack(): static
    {
        return $this->state(fn () => [
            'name' => 'Snack',
        ]);
    }

}
