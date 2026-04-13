<?php

namespace Database\Factories;

use App\Models\Table;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Table>
 */
class TableFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'outlet_id' => null,
            'name' => fake()->numberBetween(1, 99),
            'code' => 'T' . str_pad((string) fake()->numberBetween(1, 99), 2, '0', STR_PAD_LEFT),
            'capacity' => fake()->numberBetween(1, 8),
            'status' => fake()->randomElement(['available', 'occupied', 'reserved', 'maintenance']),
            'qr_code' => null,
            'qr_token' => null,
            'is_active' => true,
        ];
    }
}
