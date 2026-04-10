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
    private const DEFAULT_PRODUCT_IMAGE_URL = 'https://images.pexels.com/photos/36892229/pexels-photo-36892229.jpeg';

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
        foreach (Outlet::orderBy('id')->get() as $outlet) {

            // buat 8 meja per outlet
            for ($tableNo = 1; $tableNo <= 8; $tableNo++) {
                Table::factory()->create([
                    'outlet_id' => $outlet->id,
                    'name' => 'Meja ' . str_pad((string) $tableNo, 2, '0', STR_PAD_LEFT),
                    'code' => 'T' . str_pad((string) $tableNo, 2, '0', STR_PAD_LEFT),
                    'capacity' => 4,
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

            $productCatalog = [
                'Makanan' => [
                    ['name' => 'Nasi Goreng', 'description' => 'Nasi goreng spesial', 'cost_price' => 12000, 'station' => 'Kitchen'],
                    ['name' => 'Mie Goreng', 'description' => 'Mie goreng gurih', 'cost_price' => 11000, 'station' => 'Kitchen'],
                    ['name' => 'Ayam Geprek', 'description' => 'Ayam geprek sambal', 'cost_price' => 15000, 'station' => 'Kitchen'],
                    ['name' => 'Soto Ayam', 'description' => 'Soto ayam hangat', 'cost_price' => 13000, 'station' => 'Kitchen'],
                    ['name' => 'Bakso', 'description' => 'Bakso kuah sapi', 'cost_price' => 14000, 'station' => 'Kitchen'],
                ],
                'Minuman' => [
                    ['name' => 'Es Teh Manis', 'description' => 'Teh manis dingin', 'cost_price' => 3000, 'station' => 'Bar'],
                    ['name' => 'Es Jeruk', 'description' => 'Jeruk peras segar', 'cost_price' => 4000, 'station' => 'Bar'],
                    ['name' => 'Kopi Hitam', 'description' => 'Kopi hitam panas', 'cost_price' => 5000, 'station' => 'Bar'],
                    ['name' => 'Cappuccino', 'description' => 'Kopi susu foam', 'cost_price' => 8000, 'station' => 'Bar'],
                    ['name' => 'Matcha Latte', 'description' => 'Matcha latte dingin', 'cost_price' => 9000, 'station' => 'Bar'],
                ],
                'Snack' => [
                    ['name' => 'Kentang Goreng', 'description' => 'Kentang goreng crispy', 'cost_price' => 7000, 'station' => 'Kitchen'],
                    ['name' => 'Pisang Goreng', 'description' => 'Pisang goreng hangat', 'cost_price' => 6000, 'station' => 'Kitchen'],
                    ['name' => 'Cireng', 'description' => 'Cireng isi', 'cost_price' => 5000, 'station' => 'Kitchen'],
                    ['name' => 'Roti Bakar', 'description' => 'Roti bakar coklat', 'cost_price' => 6500, 'station' => 'Kitchen'],
                    ['name' => 'Donat', 'description' => 'Donat gula halus', 'cost_price' => 5500, 'station' => 'Kitchen'],
                ],
            ];

            $stationMap = $stations->keyBy('name');

            // produk per kategori
            foreach ($categories as $categoryIndex => $category) {
                $productRows = $productCatalog[$category->name] ?? [];

                foreach ($productRows as $productIndex => $row) {
                    $stationId = optional($stationMap->get($row['station']))->id;

                    $product = Product::create([
                        'owner_id' => $outlet->owner_id,
                        'category_id' => $category->id,
                        'station_id' => $stationId,
                        'name' => $row['name'],
                        'description' => $row['description'],
                        'cost_price' => $row['cost_price'],
                        'image' => self::DEFAULT_PRODUCT_IMAGE_URL,
                    ]);

                    $price = $row['cost_price'] + 5000;
                    $stock = 20 + ($categoryIndex * 10) + ($productIndex * 3);

                    $outlet->products()->syncWithoutDetaching([
                        $product->id => [
                            'price' => $price,
                            'stock' => $stock,
                            'is_active' => true,
                        ]
                    ]);
                }
            }

            // ================= SAMPLE ORDER =================
            $tables = Table::where('outlet_id', $outlet->id)->orderBy('id')->get();
            $products = $outlet->products()->orderBy('products.id')->get();
            $users = User::where('outlet_id', $outlet->id)
                ->whereIn('role', ['manager', 'karyawan'])
                ->orderBy('id')
                ->get();

            // 4 order per outlet
            for ($o = 0; $o < 4; $o++) {
                $status = $o % 2 === 0 ? 'pending' : 'paid';
                $selectedUser = $users[$o % $users->count()];
                $selectedTable = $tables[$o % $tables->count()];

                $order = Order::factory()->create([
                    'outlet_id' => $outlet->id,
                    'user_id' => $selectedUser->id,
                    'table_id' => $selectedTable->id,
                    'customer_name' => 'Customer ' . $outlet->id . '-' . ($o + 1),
                    'notes' => null,
                    'invoice_number' => 'INV-' . str_pad((string) $outlet->id, 2, '0', STR_PAD_LEFT) . '-' . str_pad((string) ($o + 1), 4, '0', STR_PAD_LEFT),
                    'subtotal_price' => 0,
                    'discount_type' => null,
                    'discount_value' => null,
                    'discount_amount' => 0,
                    'tax_type' => null,
                    'tax_value' => null,
                    'tax_amount' => 0,
                    'status' => $status,
                    'total_price' => 0,
                ]);

                $pickedProducts = $products->slice($o * 2, 2);
                if ($pickedProducts->isEmpty()) {
                    $pickedProducts = $products->take(2);
                }

                $total = 0;

                foreach ($pickedProducts->values() as $itemIndex => $product) {
                    $qty = $itemIndex + 1;
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

                $order->update([
                    'subtotal_price' => $total,
                    'total_price' => $total,
                ]);

                // order pending -> meja occupied, order paid -> meja available
                $order->table->update([
                    'status' => $status === 'pending' ? 'occupied' : 'available',
                ]);
            }
        }
        // ================= END LOOP =================

        // ================= SHIFT KARYAWAN =================
        foreach (User::where('role', 'karyawan')->get() as $karyawan) {
            $shiftCount = fake()->numberBetween(1, 3);
            for ($s = 1; $s <= $shiftCount; $s++) {
                DB::table('shift_karyawans')->insert([
                    'outlet_id' => $karyawan->outlet_id,
                    'user_id' => $karyawan->id,
                    'shift_ke' => $s,
                    'uang_awal' => fake()->numberBetween(100000, 500000),
                    'started_at' => now()->subHours($s * 8)->subMinutes(fake()->numberBetween(0, 59)),
                    'ended_at' => null,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // ================= PAYMENT =================
        foreach (Order::where('status', 'paid')->get() as $order) {
            DB::table('payments')->insert([
                'order_id' => $order->id,
                'amount_paid' => $order->total_price + fake()->numberBetween(0, 50000),
                'change_amount' => fake()->numberBetween(0, 50000),
                'method' => fake()->randomElement(['cash', 'debit', 'credit', 'qris', 'ewallet']),
                'reference_no' => fake()->optional()->bothify('REF-########'),
                'paid_at' => now()->subMinutes(fake()->numberBetween(0, 120)),
                'paid_by' => fake()->boolean(70) ? User::factory()->create()->id : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // ================= END SHIFT KARYAWAN & PAYMENT =================
        foreach (User::where('role', 'karyawan')->get() as $karyawan) {
            DB::table('shift_karyawans')->where('user_id', $karyawan->id)->update([
                'ended_at' => now(),
                'status' => 'closed',
            ]);
        }

        // pastikan meja yang occupied karena order pending, sisanya available
        foreach (Table::all() as $table) {
            $hasPendingOrder = Order::where('table_id', $table->id)->where('status', 'pending')->exists();
            $table->update([
                'status' => $hasPendingOrder ? 'occupied' : 'available',
            ]);
        }

        // ================= SUMMARY =================
        $outletCount = Outlet::count();
        $managerCount = User::where('role', 'manager')->count();
        $karyawanCount = User::where('role', 'karyawan')->count();
        $categoryCount = Category::count();
        $productCount = Product::count();
        $tableCount = Table::count();
        $stationCount = Station::count();
        $orderCount = Order::count();
        $paymentCount = DB::table('payments')->count();
    }
}
