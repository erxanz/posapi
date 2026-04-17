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
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_price' => $subtotal,
            'status' => fake()->randomElement(['pending', 'paid', 'cancelled']),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => 'pending',
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => 'paid',
        ]);
    }
}
