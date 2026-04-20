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
        $subtotal = fake()->numberBetween(50000, 500000);
        $discountAmount = fake()->numberBetween(0, min(50000, $subtotal * 0.3));
        $taxAmount = fake()->numberBetween(2000, 30000);
        $totalPrice = $subtotal - $discountAmount + $taxAmount;
        $paidAmount = $totalPrice + fake()->numberBetween(0, 50000);

        return [
            'outlet_id' => null,
            'order_id' => null,
            'payment_id' => null,
            'invoice_number' => 'HT-' . fake()->bothify('#####'),
            'customer_name' => fake()->name(),
            'subtotal_price' => $subtotal,
            'discount_amount' => $discountAmount,
            'tax_amount' => $taxAmount,
            'total_price' => $totalPrice,
            'paid_amount' => $paidAmount,
            'change_amount' => $paidAmount - $totalPrice,
            'payment_method' => fake()->randomElement(['cash', 'qris', 'debit', 'ewallet']),
            'paid_at' => fake()->dateTimeBetween('-90 days', '-1 day'),
            'cashier_id' => null,
            'status' => fake()->randomElement(['paid', 'cancelled']),
            'metadata' => json_encode([
                'items_count' => fake()->numberBetween(2, 8),
                'table_number' => fake()->numberBetween(1, 20),
                'shift_karyawan_id' => fake()->numberBetween(1, 100),
            ]),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => 'paid',
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => 'cancelled',
            'paid_amount' => 0,
            'change_amount' => 0,
        ]);
    }

    public function cash(): static
    {
        return $this->state(fn () => [
            'payment_method' => 'cash',
        ]);
    }

    public function nonCash(): static
    {
        return $this->state(fn () => [
            'payment_method' => fake()->randomElement(['qris', 'debit', 'ewallet']),
            'change_amount' => 0,
        ]);
    }

}
