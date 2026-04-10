<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amountPaid = fake()->numberBetween(10000, 300000);
        $changeAmount = fake()->numberBetween(0, 50000);

        return [
            'order_id' => Order::factory(),
            'amount_paid' => $amountPaid,
            'change_amount' => $changeAmount,
            'method' => fake()->randomElement(['cash', 'debit', 'credit', 'qris', 'ewallet']),
            'reference_no' => fake()->optional()->bothify('REF-########'),
            'paid_at' => fake()->dateTimeBetween('-30 days', 'now'),
            'paid_by' => fake()->boolean(70) ? User::factory() : null,
        ];
    }
}
