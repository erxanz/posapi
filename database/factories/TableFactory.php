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
            'name' => 'Meja ' . fake()->numberBetween(1, 99),
            'qr_code' => null,
            'is_active' => true,
        ];
    }
}
