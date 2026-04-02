<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Category;
use App\Models\Product;
use App\Models\Table;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ================= DEVELOPER =================
        User::factory()->developer()->create([
            'name' => 'Developer',
            'email' => 'developer@example.com',
            'password' => Hash::make('password'),
            'pin' => '111111',
        ]);

        $counter = 1;

        // ================= LOOP =================
        for ($i = 1; $i <= 3; $i++) {

            // 1. buat manager dulu
            $manager = User::factory()->manager()->create([
                'name' => 'Manager ' . $i,
                'email' => 'manager' . $i . '@example.com',
                'password' => Hash::make('password'),
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
                    'password' => Hash::make('password'),
                    'pin' => str_pad($counter, 6, '0', STR_PAD_LEFT),
                    'outlet_id' => $outlet->id,
                ]);
                $counter++;
            }
        }

        // ================= CATEGORY & PRODUCT =================
        foreach (Outlet::all() as $outlet) {

            // buat 8 meja per outlet
            Table::factory()->count(8)->create([
                'outlet_id' => $outlet->id,
            ]);

            // buat 3 kategori per outlet
            $categories = collect([
                'Makanan',
                'Minuman',
                'Snack'
            ])->map(function ($name) use ($outlet) {
                return Category::factory()->create([
                    'name' => $name,
                    'outlet_id' => $outlet->id,
                ]);
            });

            // produk per kategori
            foreach ($categories as $category) {

                // 5 produk per kategori
                Product::factory()->count(5)->create([
                    'category_id' => $category->id,
                    'outlet_id' => $outlet->id,
                ]);
            }
        }
        // ================= END LOOP =================
    }
}
