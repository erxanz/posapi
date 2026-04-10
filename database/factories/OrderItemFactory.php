<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Station;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $qty = fake()->numberBetween(1, 5);
        $price = fake()->numberBetween(5000, 100000);

        return [
            'order_id' => Order::factory(),
            'product_id' => Product::factory(),
            'station_id' => fake()->boolean(30) ? Station::factory() : null,
            'qty' => $qty,
            'price' => $price,
            'total_price' => $qty * $price,
        ];
    }
}
