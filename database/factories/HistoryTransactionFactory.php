<?php

namespace Database\Factories;

use App\Models\HistoryTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HistoryTransaction>
 */
class HistoryTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->numberBetween(10000, 300000);
        $discountAmount = fake()->numberBetween(0, 20000);
        $taxAmount = fake()->numberBetween(0, 20000);
        $totalPrice = max(0, $subtotal - $discountAmount + $taxAmount);
        $paidAmount = $totalPrice;

        return [
            'outlet_id' => null,
            'order_id' => null,
            'payment_id' => null,
            'invoice_number' => 'INV-' . strtoupper(fake()->bothify('######??')),
            'customer_name' => fake()->optional()->name(),
            'subtotal_price' => $subtotal,
            'discount_amount' => $discountAmount,
            'tax_amount' => $taxAmount,
            'total_price' => $totalPrice,
            'paid_amount' => $paidAmount,
            'change_amount' => fake()->numberBetween(0, 50000),
            'payment_method' => fake()->randomElement(['cash', 'debit', 'credit', 'qris', 'ewallet', 'split']),
            'paid_at' => fake()->dateTimeBetween('-30 days', 'now'),
            'cashier_id' => null,
            'status' => 'paid',
            'metadata' => [
                'payments_count' => fake()->numberBetween(1, 2),
                'items_count' => fake()->numberBetween(1, 6),
            ],
        ];
    }
}
