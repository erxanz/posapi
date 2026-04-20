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
    private const DEFAULT_IMAGE_URL = 'https://images.pexels.com/photos/28041446/pexels-photo-28041446.jpeg';

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $names = [
            'Nasi Goreng Spesial', 'Mie Ayam', 'Ayam Geprek', 'Soto Ayam', 'Bakso Urut',
            'Es Teh Manis', 'Es Jeruk', 'Kopi Susu', 'Jus Alpukat', 'Smoothie Bowl',
            'Kentang Goreng', 'Pisang Goreng', 'Cireng Pedas', 'Roti Bakar Coklat', 'Donat Mini',
            'Sate Ayam 10 Tusuk', 'Gado-Gado', 'Sate Lilit', 'Tempe Goreng', 'Tahu Isi'
        ];

        $descriptions = [
            'Nasi goreng dengan telur mata sapi dan ayam suwir',
            'Mie ayam dengan pangsit goreng crispy',
            'Ayam crispy dengan sambal bawang pedas',
            'Soto ayam kuah bening dengan emping',
            'Bakso sapi halus dengan mie kuning',
            'Es teh manis segar dengan gula aren',
            'Es jeruk peras asli segar',
            'Kopi susu gula aren premium',
            'Jus alpukat dengan susu kental manis',
            'Smoothie bowl buah-buahan segar',
            'Kentang goreng crispy dengan saus keju',
            'Pisang goreng tepung premium',
            'Cireng pedas dengan saus kecap',
            'Roti bakar coklat meses meleleh',
            'Donat mini garing luar lembut dalam',
            'Sate ayam 10 tusuk dengan bumbu kacang',
            'Gado-gado sayur dengan lontong',
            'Sate lilit ikan tenggiri khas Bali',
            'Tempe goreng tepung rancid',
            'Tahu isi sayur dengan saus kecap'
        ];

        $stations = ['Kitchen', 'Bar', 'Kasir'];

        $index = (self::$sequence - 1) % count($names);

        self::$sequence++;

        return [
            'category_id' => Category::factory(),
            'owner_id' => null,
            'name' => $names[$index],
            'description' => $descriptions[$index],
            'cost_price' => fake()->numberBetween(8000, 25000),
            'image' => self::DEFAULT_IMAGE_URL,
            'station_id' => null,
        ];
    }

    public function makanan(): static
    {
        return $this->state(fn () => [
            'name' => fake()->randomElement(['Nasi Goreng', 'Ayam Geprek', 'Sate Ayam', 'Mie Goreng']),
        ]);
    }

    public function minuman(): static
    {
        return $this->state(fn () => [
            'name' => fake()->randomElement(['Es Teh', 'Jus Jeruk', 'Kopi Susu', 'Es Kopi']),
        ]);
    }

    public function snack(): static
    {
        return $this->state(fn () => [
            'name' => fake()->randomElement(['Kentang Goreng', 'Pisang Goreng', 'Donat', 'Cireng']),
        ]);
    }

}
