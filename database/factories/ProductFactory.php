<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected static int $sequence = 1;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $seq = self::$sequence++;
        $costPrice = 5000 + (($seq - 1) * 1000);

        return [
            'category_id' => Category::factory(),
            'owner_id' => null,

            'name' => 'Produk ' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
            'description' => 'Deskripsi produk ' . $seq,
            'cost_price' => $costPrice,
            'image' => null,
            'station_id' => null,
        ];
    }
}
