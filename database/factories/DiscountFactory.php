<?php

namespace Database\Factories;

use App\Models\Discount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Discount>
 */
class DiscountFactory extends Factory
{



    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $types = ['percentage', 'nominal'];
        $type = fake()->randomElement($types);

        return [
            'owner_id' => null, // Will be set when used
            'name' => fake()->randomElement([
                'Diskon Pelanggan Setia',
                'Promo Weekend',
                'Buy More Save More',
                'Flash Sale Siang Ini',
            ]),

            'type' => $type,
            'value' => $type === 'percentage' ? fake()->numberBetween(5, 50) : fake()->numberBetween(5000, 50000),
            'min_purchase' => fake()->numberBetween(50000, 300000),
            'start_date' => fake()->dateTimeBetween('-1 month', 'now'),
            'end_date' => fake()->dateTimeBetween('now', '+1 month'),
            'is_active' => true,
            'used_count' => 0,
            'max_usage' => null,
        ];
    }

    /**
     * F&B POS - Promo Makanan Realistis
     */
    public function lunchSpecial(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Special Makan Siang 20%',
            'type' => 'percentage',
            'value' => 20,
            'min_purchase' => 50000,
            'start_date' => now()->startOfWeek(),
            'end_date' => now()->endOfWeek()->addHours(14),

        ]);
    }

    public function happyHour(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Happy Hour - Potong Rp15.000',
            'type' => 'nominal',
            'value' => 15000,
            'min_purchase' => 75000,
            'start_date' => now()->setTime(17, 0),
            'end_date' => now()->copy()->addHours(3),
        ]);
    }

    public function buy1Get1(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Buy 1 Get 1 Free (50%)',
            'type' => 'percentage',
            'value' => 50,
            'min_purchase' => 25000,
        ]);
    }

    public function memberDiscount(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Member Loyalty 10%',
            'type' => 'percentage',
            'value' => 10,
            'max_usage' => 100,
        ]);
    }

    public function weekdayPromo(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Promo Senin-Kamis Rp10rb OFF',
            'type' => 'nominal',
            'value' => 10000,
            'min_purchase' => 100000,
            'start_date' => now()->startOfWeek(),
            'end_date' => now()->endOfWeek(),

        ]);
    }

    /**
     * Limited usage promo
     */
    public function limitedQuota(int $quota = 50): static
    {
        return $this->state(fn (array $attributes) => [
            'max_usage' => $quota,
            'used_count' => fake()->numberBetween(0, $quota - 1),
        ]);
    }
}

