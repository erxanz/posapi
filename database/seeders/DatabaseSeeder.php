<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Category;
use App\Models\Product;
use App\Models\Table;
use App\Models\Station;
use App\Models\Order;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

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
            for ($tableNo = 1; $tableNo <= 8; $tableNo++) {
                Table::factory()->create([
                    'outlet_id' => $outlet->id,
                    'name' => 'Meja ' . str_pad((string) $tableNo, 2, '0', STR_PAD_LEFT),
                    'code' => 'T' . str_pad((string) $tableNo, 2, '0', STR_PAD_LEFT),
                    'status' => 'available',
                    'is_active' => true,
                ]);
            }

            // buat 3 kategori per outlet
            $categories = collect([
                'Makanan',
                'Minuman',
                'Snack'
            ])->map(function ($name) use ($outlet) {
                return Category::factory()->create([
                    'name' => $name,
                    'owner_id' => $outlet->owner_id,
                ]);
            });

            // station per outlet
            $stations = collect([
                'Kitchen',
                'Bar',
                'Kasir',
            ])->map(function ($name) use ($outlet) {
                return Station::create([
                    'name' => $name,
                    'owner_id' => $outlet->owner_id,
                ]);
            });

            // produk per kategori
            foreach ($categories as $category) {

                // 5 produk per kategori
                $products = Product::factory()->count(5)->create([
                    'owner_id' => $outlet->owner_id,
                    'category_id' => $category->id,
                    'station_id' => $stations->random()->id,
                ]);

                foreach ($products as $product) {
                    $outlet->products()->syncWithoutDetaching([
                        $product->id => [
                            'price' => fake()->numberBetween($product->cost_price, 75000),
                            'stock' => fake()->numberBetween(0, 100),
                            'is_active' => true,
                        ]
                    ]);
                }
            }

            // ================= SAMPLE ORDER =================
            $tables = Table::where('outlet_id', $outlet->id)->get();
            $products = $outlet->products()->get();
            $users = User::where('outlet_id', $outlet->id)
                ->whereIn('role', ['manager', 'karyawan'])
                ->get();

            // 4 order per outlet
            for ($o = 0; $o < 4; $o++) {
                $status = fake()->randomElement(['pending', 'paid']);

                $order = Order::factory()->create([
                    'outlet_id' => $outlet->id,
                    'user_id' => $users->random()->id,
                    'table_id' => $tables->random()->id,
                    'customer_name' => fake()->name(),
                    'invoice_number' => 'INV-' . strtoupper(uniqid()),
                    'status' => $status,
                    'total_price' => 0,
                ]);

                $pickedProducts = $products->random(fake()->numberBetween(1, 3));
                $pickedProducts = $pickedProducts instanceof \Illuminate\Support\Collection
                    ? $pickedProducts
                    : collect([$pickedProducts]);

                $total = 0;

                foreach ($pickedProducts as $product) {
                    $qty = fake()->numberBetween(1, 3);
                    $subtotal = (int) $product->pivot->price * $qty;

                    DB::table('order_items')->insert([
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                        'station_id' => $product->station_id,
                        'qty' => $qty,
                        'price' => (int) $product->pivot->price,
                        'total_price' => $subtotal,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $total += $subtotal;
                }

                $order->update(['total_price' => $total]);

                // order pending -> meja occupied, order paid -> meja available
                $order->table->update([
                    'status' => $status === 'pending' ? 'occupied' : 'available',
                ]);
            }
        }
        // ================= END LOOP =================
    }
}
