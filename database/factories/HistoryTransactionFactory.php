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
        $invoiceNumber = 'HT-' . str_pad((string) fake()->numberBetween(1000, 9999), 4, '0', STR_PAD_LEFT);
        $customerName = fake()->name();
        $subtotal = fake()->numberBetween(75000, 350000);
        $discountAmount = fake()->numberBetween(0, 25000);
        $taxAmount = round($subtotal * 0.11); // PPN 11%
        $totalPrice = $subtotal - $discountAmount + $taxAmount;
        $paymentMethod = fake()->randomElement(['cash', 'QRIS', 'Debit', 'E-Wallet']);
        $paidAmount = $totalPrice + fake()->numberBetween(0, 25000);
        $changeAmount = $paidAmount - $totalPrice;

        return [
            'outlet_id' => null,
            'order_id' => null,
            'payment_id' => null,
            'invoice_number' => $invoiceNumber,
            'customer_name' => $customerName,
            'subtotal_price' => $subtotal,
            'discount_amount' => $discountAmount,
            'tax_amount' => $taxAmount,
            'total_price' => $totalPrice,
            'paid_amount' => $paidAmount,
            'change_amount' => $changeAmount,
            'payment_method' => $paymentMethod,
            'paid_at' => fake()->dateTimeBetween('-60 days', '-1 day'),
            'cashier_id' => null,
            'status' => 'paid',
            'metadata' => json_encode([
                'items_count' => fake()->numberBetween(3, 7),
                'table_number' => fake()->numberBetween(1, 20),
                'discount_name' => fake()->randomElement(['Pelanggan Setia 10%', 'Promo Siang', null]),
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
