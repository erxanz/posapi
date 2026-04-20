<?php

namespace Database\Factories;

use App\Models\Tax;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tax>
 */
class TaxFactory extends Factory
{
    protected $model = Tax::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement([
                'PPN 11%',
                'PPh 21 0.5%',
                'Service Charge',
                'Pajak Restoran',
            ]),
            'rate' => fake()->randomFloat(4, 0.5, 15),
            'type' => fake()->randomElement(['percentage', 'fixed']),
            'outlet_id' => fn () => \App\Models\Outlet::inRandomOrder()->first()?->id ?? \App\Models\Outlet::factory()->create()->id,
            'active' => true,
        ];
    }

    /**
     * Pajak Standar Indonesia
     */
    public function ppn(): static
    {
        return $this->state(fn () => [
            'name' => 'PPN 11%',
            'rate' => 11.0,
            'type' => 'percentage',
        ]);
    }

    public function pph(): static
    {
        return $this->state(fn () => [
            'name' => 'PPh 21',
            'rate' => 0.5,
            'type' => 'percentage',
        ]);
    }

    public function serviceCharge(): static
    {
        return $this->state(fn () => [
            'name' => 'Service Charge 10%',
            'rate' => 10.0,
            'type' => 'percentage',
        ]);
    }

    public function fixed(): static
    {
        return $this->state(fn () => [
            'name' => 'Biaya Administrasi',
            'rate' => 2500,
            'type' => 'fixed',
        ]);
    }

}
