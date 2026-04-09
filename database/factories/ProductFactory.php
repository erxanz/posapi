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
            'owner_id' => null,

            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'cost_price' => $costPrice,
            'image' => null,
            'station_id' => null,
        ];
    }
}
