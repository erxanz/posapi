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
        $subtotal = fake()->numberBetween(10000, 250000);

        return [
            'outlet_id' => null,
            'user_id' => null,
            'table_id' => null,
            'customer_name' => fake()->optional()->name(),
            'notes' => fake()->optional()->sentence(),
            'invoice_number' => 'INV-' . strtoupper(Str::random(10)),
            'subtotal_price' => $subtotal,
            'discount_type' => null,
            'discount_value' => null,
            'discount_amount' => 0,
            'tax_type' => null,
            'tax_value' => null,
            'tax_amount' => 0,
            'total_price' => $subtotal,
            'status' => fake()->randomElement(['pending', 'paid', 'cancelled']),
        ];
    }
}
