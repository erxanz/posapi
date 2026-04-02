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
        // ================= DEVELOPER =================
        User::factory()->developer()->create([
            'name' => 'Developer',
            'email' => 'developer@example.com',
            'password' => Hash::make('123456'),
            'pin' => '111111',
        ]);

        $counter = 1;

        // ================= LOOP =================
        for ($i = 1; $i <= 3; $i++) {

            // 1. buat manager dulu
            $manager = User::factory()->manager()->create([
                'name' => 'Manager ' . $i,
                'email' => 'manager' . $i . '@example.com',
                'password' => Hash::make('123456'),
                'pin' => '22222' . $i,
                'outlet_id' => null, // nanti diisi
            ]);

            // 2. buat outlet dengan owner_id
            $outlet = Outlet::factory()->create([
                'name' => 'Outlet ' . $i,
                'owner_id' => $manager->id,
            ]);

            // 3. update manager → masuk ke outlet
            $manager->update([
                'outlet_id' => $outlet->id
            ]);

            // 4. buat karyawan
            for ($j = 0; $j < 2; $j++) {
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
