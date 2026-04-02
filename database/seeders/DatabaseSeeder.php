<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Outlet;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ================= OUTLET =================
        $outlets = Outlet::factory()->count(3)->create();

        // ================= DEVELOPER =================
        User::factory()->developer()->create([
            'name' => 'Developer',
            'email' => 'developer@example.com',
            'password' => Hash::make('123456'),
            'pin' => '111111',
        ]);

        // ================= MANAGER =================
        foreach ($outlets as $i => $outlet) {
            User::factory()->manager()->create([
                'name' => 'Manager ' . ($i + 1),
                'email' => 'manager' . ($i + 1) . '@example.com',
                'password' => Hash::make('123456'),
                'pin' => '22222' . $i,
                'outlet_id' => $outlet->id,
            ]);
        }

        // ================= KARYAWAN =================
        $counter = 1;

        foreach ($outlets as $outlet) {
            for ($i = 0; $i < 2; $i++) {
                User::factory()->karyawan()->create([
                    'name' => 'Karyawan ' . $counter,
                    'email' => 'karyawan' . $counter . '@example.com',
                    'password' => Hash::make('123456'),
                    'pin' => str_pad($counter, 6, '0', STR_PAD_LEFT),
                    'outlet_id' => $outlet->id,
                ]);
                $counter++;
            }
        }
    }
}
