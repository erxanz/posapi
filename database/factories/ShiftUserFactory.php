<?php

namespace Database\Factories;

use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\ShiftUser>
 */

class ShiftUserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shift_id' => Shift::factory(),
            'user_id' => User::factory()->karyawan(),
        ];
    }

    public function forKaryawanInOutlet(int $outletId): static
    {
        return $this->state(fn () => [
            'user_id' => User::where('outlet_id', $outletId)->where('role', 'karyawan')->inRandomOrder()->value('id'),
        ]);
    }

    public function forShiftInOutlet(int $outletId): static
    {
        return $this->state(fn () => [
            'shift_id' => Shift::where('outlet_id', $outletId)->inRandomOrder()->value('id'),
        ]);
    }
}
