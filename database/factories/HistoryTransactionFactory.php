<?php

namespace Database\Factories;

use App\Models\HistoryTransaction;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

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

        $items = fake()->randomElements([
            'Nasi Goreng Spesial x2 Rp45.000',
            'Es Teh Manis x1 Rp8.000',
            'Kentang Goreng x1 Rp15.000',
            'Ayam Geprek x1 Rp35.000',
            'Kopi Susu x1 Rp12.000',
            'Sate Ayam x1 Rp25.000',
            'Jus Jeruk x1 Rp10.000',
        ], fake()->numberBetween(2, 5));

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
            'order_items_summary' => json_encode([
                'items_count' => count($items),
                'items' => $items,
                'table_number' => fake()->numberBetween(1, 20),
                'discount_name' => fake()->randomElement(['Pelanggan Setia 10%', 'Promo Siang', null]),
            ]),
            'metadata' => json_encode([
                'shift_karyawan_id' => fake()->numberBetween(1, 50),
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
            'order_items_summary' => null,
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
            'payment_method' => fake()->randomElement(['QRIS', 'Debit', 'E-Wallet']),
            'change_amount' => 0,
        ]);
    }

    public function withOrderItemsSummary(array $items): static
    {
        return $this->state(fn () => [
            'order_items_summary' => json_encode([
                'items_count' => count($items),
                'items' => $items,
            ]),
        ]);
    }
}

