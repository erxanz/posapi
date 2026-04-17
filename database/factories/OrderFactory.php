<?php

namespace Database\Factories;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
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

    public function withDiscount(): static
    {
        return $this->state(function (array $attributes) {
            $discount = fake()->numberBetween(5000, 50000);

            return [
                'discount_amount' => $discount,
                'total_price' => $attributes['subtotal_price'] - $discount,
            ];
        });
    }

    public function withTax(): static
    {
        return $this->state(function (array $attributes) {
            $tax = fake()->numberBetween(1000, 20000);

            return [
                'tax_amount' => $tax,
                'total_price' => $attributes['subtotal_price'] + $tax,
            ];
        });
    }

    public function full(): static
    {
        return $this->state(function (array $attributes) {
            $discount = fake()->numberBetween(5000, 30000);
            $tax = fake()->numberBetween(2000, 10000);

            return [
                'discount_amount' => $discount,
                'tax_amount' => $tax,
                'total_price' => $attributes['subtotal_price'] - $discount + $tax,
            ];
        });
    }
}
