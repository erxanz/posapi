<?php

namespace Database\Factories;

use App\Models\Station;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Station>
 */
class StationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement([
            'Kitchen',
            'Bar',
            'Kasir',
            'Dessert',
        ]);

        return [
            'outlet_id' => null,
            'name' => $name,
            'code' => strtoupper(Str::slug($name)),
            'is_active' => fake()->boolean(90),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}
