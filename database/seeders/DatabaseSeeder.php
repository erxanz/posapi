<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Discount;
use App\Models\HistoryTransaction;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ShiftKaryawan;
use App\Models\Shift;
use App\Models\Station;
use App\Models\Table;
use App\Models\Tax;
use App\Models\User;
use Database\Factories\ShiftUserFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Faker;

class DatabaseSeeder extends Seeder
{
    private const DEFAULT_PRODUCT_IMAGE_URL = 'https://images.pexels.com/photos/28041446/pexels-photo-28041446.jpeg';

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

            // ================= CREATE MASTER SHIFTS =================
            Shift::factory()->pagi()->create([
                'outlet_id' => $outlet->id,
            ]);
            Shift::factory()->malam()->create([
                'outlet_id' => $outlet->id,
            ]);
        }

        // ================= CATEGORY & PRODUCT =================
        foreach (Outlet::orderBy('id')->get() as $outlet) {

            // buat 8 meja per outlet
            for ($tableNo = 1; $tableNo <= 8; $tableNo++) {
                Table::factory()->create([
                    'outlet_id' => $outlet->id,
                    'name' => str_pad((string) $tableNo, 2, '0', STR_PAD_LEFT),
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
                return Category::factory()->state(fn() => ['name' => $name])->create([
                    'owner_id' => $outlet->owner_id,
                ]);
            });


            // station per outlet
            $stations = collect([
                'Dapur Utama',
                'Bar Minuman',
                'Kasir Utama',
            ])->map(function ($name) use ($outlet) {
                return Station::factory()->state(fn() => ['name' => $name])->create([
                    'owner_id' => $outlet->owner_id,
                ]);
            });


            // FACTORY for products - fully connected to Category & Station
            foreach ($categories as $category) {
                $matchedStation = $stations->firstWhere('name', str_contains($category->name, 'Minuman') ? 'Bar Minuman' : 'Dapur Utama');

                Product::factory()
                    ->count(5)
                    ->state(fn() => ['category_id' => $category->id, 'owner_id' => $outlet->owner_id])
                    ->create([
                        'station_id' => $matchedStation?->id ?? $stations->random()->id,
                    ]);

                // Update outlet_product pivot
                $products = Product::where('category_id', $category->id)->latest()->limit(5)->get();
                foreach ($products as $product) {
                    $price = $product->cost_price + fake()->numberBetween(5000, 15000);
                    $stock = fake()->numberBetween(10, 100);
                    $outlet->products()->syncWithoutDetaching([
                        $product->id => [
                            'station_id' => $product->station_id,
                            'price' => $price,
                            'stock' => $stock,
                            'is_active' => true,
                        ]
                    ]);
                }
            }


            // ================= DISCOUNTS & TAXES PER OUTLET =================
            Discount::factory()->lunchSpecial()->limitedQuota(50)->create(['owner_id' => $outlet->owner_id]);
            Discount::factory()->happyHour()->categoriesScope([1,2])->create(['owner_id' => $outlet->owner_id]);
            Discount::factory()->weekdayPromo()->productsScope([1,2])->create(['owner_id' => $outlet->owner_id]);

            Tax::factory()->ppn()->create(['outlet_id' => $outlet->id]);

            Tax::factory()->serviceCharge()->create(['outlet_id' => $outlet->id]);


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

                $order = Order::factory()
                    ->{$status}() // pending() atau paid()
                    ->full()
                    ->create([
                        'outlet_id' => $outlet->id,
                        'user_id' => $selectedUser->id,
                        'table_id' => $selectedTable->id,
                        'customer_name' => 'Customer ' . $outlet->id . '-' . ($o + 1),
                        'invoice_number' => 'INV-' . str_pad((string) $outlet->id, 2, '0', STR_PAD_LEFT) . '-' . str_pad((string) ($o + 1), 4, '0', STR_PAD_LEFT),
                    ]);

                // Recalculate totals with discount/tax
                $order->recalculateTotals();

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

                // Subtotal already recalculated with discount/tax, update only if needed
                $order->update([
                    'subtotal_price' => $total,
                ]);

                // order pending -> meja occupied, order paid -> meja available
                $order->table->update([
                    'status' => $status === 'pending' ? 'occupied' : 'available',
                ]);
            }
        }
        // ================= END LOOP =================

        // ================= ADDITIONAL FACTORY SEEDING =================
        foreach (Outlet::all() as $outlet) {
            // Additional orders using factory
            $users = User::where('outlet_id', $outlet->id)->whereIn('role', ['manager', 'karyawan'])->get();
            $tables = Table::where('outlet_id', $outlet->id)->get();

            Order::factory()
                ->count(3)
                ->full()
                ->paid()
                ->create([
                    'outlet_id' => $outlet->id,
                    'user_id' => $users->random()->id,
                    'table_id' => $tables->random()->id,
                ]);

            // Additional discounts using factory states
            Discount::factory()->happyHour()->limitedQuota(100)->create(['owner_id' => $outlet->owner_id]);

            Discount::factory()->buy1Get1()->productsScope([1,2,3])->create(['owner_id' => $outlet->owner_id]);


// Rapi history transactions using factory - sequential, non-random
            $paidOrders = Order::where('outlet_id', $outlet->id)
                ->where('status', 'paid')
                ->orderBy('id')
                ->get();

            foreach ($paidOrders as $index => $order) {
                $hasHistory = DB::table('history_transactions')->where('order_id', $order->id)->exists();
                if (!$hasHistory) {
                    HistoryTransaction::factory()->paid()->create([
                        'outlet_id' => $outlet->id,
                        'order_id' => $order->id,
                        'payment_id' => DB::table('payments')->where('order_id', $order->id)->value('id'),
                        'cashier_id' => $order->user_id,
                    ]);


                }
            }

        }

        // ================= SHIFT KARYAWAN =================
        foreach (User::where('role', 'karyawan')->get() as $karyawan) {
            $outletShifts = Shift::where('outlet_id', $karyawan->outlet_id)->get();
            foreach ($outletShifts as $shift) {
                ShiftKaryawan::factory()
                    ->count(30)
                    ->closed()
                    ->create([
                        'outlet_id' => $karyawan->outlet_id,
                        'user_id' => $karyawan->id,
                        'shift_id' => $shift->id,
                    ]);
            }
        }

        // ================= SHIFT SCHEDULES SEED (DEMO DATA - 1 bulan) =================
        $faker = fake();
        $shifts = Shift::all();
        $karyawans = User::where('role', 'karyawan')->get();

        foreach ($karyawans as $karyawan) {
            $outletShifts = $shifts->where('outlet_id', $karyawan->outlet_id);
            foreach ($outletShifts as $shift) {
                // Seed for 30 days
                for ($day = 0; $day < 30; $day++) {
                    $date = now()->subMonths(1)->addDays($day);
                    DB::table('shift_schedules')->insert([
                        'outlet_id' => $karyawan->outlet_id,
                        'shift_id' => $shift->id,
                        'user_id' => $karyawan->id,
                        'date' => $date->toDateString(),
                        'created_at' => $date,
                        'updated_at' => $date,
                    ]);
                }
            }
        }

        // ================= PAYMENT =================
        foreach (Order::where('status', 'paid')->get() as $order) {
            $cashierId = fake()->boolean(70)
                ? User::where('outlet_id', $order->outlet_id)->whereIn('role', ['manager', 'karyawan'])->inRandomOrder()->value('id')
                : null;

            $paidAmount = (int) $order->total_price + fake()->numberBetween(0, 50000);
            $changeAmount = max(0, $paidAmount - (int) $order->total_price);

            $paymentId = DB::table('payments')->insertGetId([
                'order_id' => $order->id,
                'amount_paid' => $paidAmount,
                'change_amount' => $changeAmount,
                'method' => fake()->randomElement(['cash', 'debit', 'credit', 'qris', 'ewallet']),
                'reference_no' => fake()->optional()->bothify('REF-########'),
                'paid_at' => now()->subMinutes(fake()->numberBetween(0, 120)),
                'paid_by' => $cashierId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // History created later by factory to avoid duplicate

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

        // Cancelled orders history handled in factory section



        // ================= SUMMARY =================
        $outletCount = Outlet::count();
        $userCount = User::count();
        $categoryCount = Category::count();
        $productCount = Product::count();
        $tableCount = Table::count();
        $orderCount = Order::count();
        $paymentCount = DB::table('payments')->count();
        $historyTransactionCount = DB::table('history_transactions')->count();
    }
}
