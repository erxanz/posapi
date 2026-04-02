<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
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
            'user_id' => null,
            'table_id' => null,
            'customer_name' => fake()->optional()->name(),
            'notes' => fake()->optional()->sentence(),
            'invoice_number' => 'INV-' . strtoupper(Str::random(10)),
            'total_price' => fake()->numberBetween(0, 250000),
            'status' => fake()->randomElement(['pending', 'paid', 'cancelled']),
        ];
    }
}
