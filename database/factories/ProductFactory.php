<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $costPrice = fake()->numberBetween(3000, 35000);

        return [
            'category_id' => null,
            'outlet_id' => null,

            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'price' => fake()->numberBetween($costPrice, 75000),
            'cost_price' => $costPrice,
            'stock' => fake()->numberBetween(0, 100),
            'image' => null,
            'is_active' => true,
        ];
    }
}
