<?php

namespace Database\Factories;

use App\Models\Outlet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Outlet>
 */
class OutletFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),

            // JANGAN buat user di sini
            'owner_id' => null,
        ];
    }

    // optional: kalau mau set owner
    public function withOwner($userId): static
    {
        return $this->state(fn () => [
            'owner_id' => $userId,
        ]);
    }
}
