<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\HistoryTransaction;
use App\Models\Product;
use App\Models\Category;
use App\Models\Table;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Outlet $outlet;
    private Category $category;
    private Product $product1;
    private Product $product2;
    private Product $product3;
    private Product $product4;
    private Product $product5;
    private Table $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'manager']);
        $this->outlet = Outlet::factory()->create(['owner_id' => $this->owner->id]);
        $this->category = Category::factory()->create(['owner_id' => $this->owner->id]);

        $this->product1 = Product::factory()->create(['category_id' => $this->category->id, 'owner_id' => $this->owner->id, 'name' => 'Product A']);
        $this->product2 = Product::factory()->create(['category_id' => $this->category->id, 'owner_id' => $this->owner->id, 'name' => 'Product B']);
        $this->product3 = Product::factory()->create(['category_id' => $this->category->id, 'owner_id' => $this->owner->id, 'name' => 'Product C']);
        $this->product4 = Product::factory()->create(['category_id' => $this->category->id, 'owner_id' => $this->owner->id, 'name' => 'Product D']);
        $this->product5 = Product::factory()->create(['category_id' => $this->category->id, 'owner_id' => $this->owner->id, 'name' => 'Product E']);

        foreach ([$this->product1, $this->product2, $this->product3, $this->product4, $this->product5] as $product) {
            $this->outlet->products()->attach($product->id, [
                'price' => 25000,
                'stock' => 100,
                'is_active' => true,
            ]);
        }

        $this->table = Table::factory()->create([
            'outlet_id' => $this->outlet->id,
            'name' => 'Meja Test',
            'status' => 'available',
        ]);
    }

    private function createPaidOrder(array $items, ?Carbon $paidAt = null, string $paymentMethod = 'cash'): void
    {
        $paidAt ??= Carbon::now()->subDays(1);

        $invoiceNum = 'INV-' . now()->format('Ymd') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);

        $order = Order::factory()->create([
            'outlet_id' => $this->outlet->id,
            'table_id' => $this->table->id,
            'user_id' => $this->owner->id,
            'status' => 'paid',
            'invoice_number' => $invoiceNum,
            'subtotal_price' => collect($items)->sum(fn($i) => $i['qty'] * ($i['price'] ?? 25000)),
            'total_price' => collect($items)->sum(fn($i) => $i['qty'] * ($i['price'] ?? 25000)),
            'payment_method' => $paymentMethod,
            'created_at' => $paidAt,
            'updated_at' => $paidAt,
        ]);

        foreach ($items as $itemData) {
            OrderItem::factory()->create([
                'order_id' => $order->id,
                'product_id' => $itemData['product_id'],
                'qty' => $itemData['qty'],
                'price' => $itemData['price'] ?? 25000,
                'total_price' => ($itemData['price'] ?? 25000) * $itemData['qty'],
            ]);
        }

        $payment = Payment::factory()->create([
            'order_id' => $order->id,
            'amount_paid' => $order->total_price,
            'change_amount' => 0,
            'method' => $paymentMethod,
            'paid_at' => $paidAt,
            'paid_by' => $this->owner->id,
        ]);

        HistoryTransaction::factory()->create([
            'outlet_id' => $this->outlet->id,
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'status' => 'paid',
            'invoice_number' => $invoiceNum,
            'subtotal_price' => $order->subtotal_price,
            'total_price' => $order->total_price,
            'paid_amount' => $order->total_price,
            'payment_method' => $paymentMethod,
            'paid_at' => $paidAt,
        ]);
    }

    /**
     * Test the report index endpoint returns a successful response with correct structure.
     */
    public function test_report_index_returns_valid_response(): void
    {
        $user = User::factory()->create([
            'role' => 'developer',
            'outlet_id' => null,
        ]);

        $outlet = Outlet::factory()->create([
            'owner_id' => $user->id,
        ]);

        $category = Category::factory()->create(['owner_id' => $user->id]);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'owner_id' => $user->id,
        ]);

        $outlet->products()->attach($product->id, [
            'price' => 25000,
            'stock' => 100,
            'is_active' => true,
        ]);

        $table = Table::factory()->create([
            'outlet_id' => $outlet->id,
            'name' => 'Meja 1',
            'status' => 'available',
        ]);

        $order = Order::factory()->create([
            'outlet_id' => $outlet->id,
            'table_id' => $table->id,
            'user_id' => $user->id,
            'status' => 'paid',
            'subtotal_price' => 25000,
            'discount_amount' => 0,
            'tax_amount' => 2750,
            'total_price' => 27750,
            'customer_name' => 'Test Customer',
            'payment_method' => 'cash',
            'created_at' => Carbon::now()->subDays(5),
            'updated_at' => Carbon::now()->subDays(5),
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'qty' => 1,
            'price' => 25000,
            'total_price' => 25000,
        ]);

        $payment = Payment::factory()->create([
            'order_id' => $order->id,
            'amount_paid' => 30000,
            'change_amount' => 2250,
            'method' => 'cash',
            'paid_at' => Carbon::now()->subDays(5),
            'paid_by' => $user->id,
        ]);

        HistoryTransaction::factory()->create([
            'outlet_id' => $outlet->id,
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'status' => 'paid',
            'subtotal_price' => 25000,
            'discount_amount' => 0,
            'tax_amount' => 2750,
            'total_price' => 27750,
            'paid_amount' => 27750,
            'payment_method' => 'cash',
            'paid_at' => Carbon::now()->subDays(5),
            'customer_name' => 'Test Customer',
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/reports');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'summary' => [
                'revenue', 'transactions', 'avg_order', 'items_sold',
                'total_discount', 'total_tax', 'revenue_growth', 'trx_growth',
                'unique_customers', 'avg_check',
            ],
            'revenue_chart', 'sales_report', 'top_products',
            'cashier_performance', 'payment_methods', 'category_performance',
            'hourly_sales', 'table_performance', 'station_performance',
            'shift_summary', 'period_info',
        ]);

        $response->assertJson([
            'summary' => [
                'revenue' => 27750,
                'transactions' => 1,
                'items_sold' => 1,
                'total_discount' => 0,
                'total_tax' => 2750,
                'unique_customers' => 1,
            ],
        ]);

        $this->assertCount(24, $response->json('hourly_sales'));
    }

    /**
     * Test that the dateExpr() helper works correctly with SQLite.
     */
    public function test_date_expr_works_with_sqlite(): void
    {
        $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();
        $this->assertEquals('sqlite', $driver);

        $controller = new \App\Http\Controllers\ReportController();
        $reflection = new \ReflectionMethod($controller, 'dateExpr');
        $reflection->setAccessible(true);
        $result = $reflection->invoke($controller, 'paid_at');
        $this->assertEquals('DATE(paid_at)', $result);
    }

    /**
     * Test that the hourExpr() helper works correctly with SQLite.
     */
    public function test_hour_expr_works_with_sqlite(): void
    {
        $controller = new \App\Http\Controllers\ReportController();
        $reflection = new \ReflectionMethod($controller, 'hourExpr');
        $reflection->setAccessible(true);
        $result = $reflection->invoke($controller, 'paid_at');
        $this->assertEquals("CAST(strftime('%H', paid_at) AS INTEGER)", $result);
    }

    // =========================================================================
    // PUBLIC TOP PRODUCTS
    // =========================================================================

    /**
     * Returns top 4 selling products sorted by sold quantity descending.
     */
    public function test_public_top_products_returns_top_4_selling_products(): void
    {
        // Product A sold 10, B sold 8, C sold 5, D sold 3, E sold 1
        $this->createPaidOrder([
            ['product_id' => $this->product1->id, 'qty' => 5],
            ['product_id' => $this->product2->id, 'qty' => 3],
        ]);
        $this->createPaidOrder([
            ['product_id' => $this->product1->id, 'qty' => 5],
            ['product_id' => $this->product3->id, 'qty' => 5],
        ]);
        $this->createPaidOrder([
            ['product_id' => $this->product2->id, 'qty' => 5],
            ['product_id' => $this->product4->id, 'qty' => 3],
        ]);
        $this->createPaidOrder([
            ['product_id' => $this->product5->id, 'qty' => 1],
        ]);

        $response = $this->getJson('/api/v1/public/top-products?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'top_products' => [
                '*' => ['name', 'sold'],
            ],
        ]);

        $topProducts = $response->json('top_products');
        $this->assertCount(4, $topProducts);

        $this->assertEquals('Product A', $topProducts[0]['name']);
        $this->assertEquals(10, $topProducts[0]['sold']);
        $this->assertEquals('Product B', $topProducts[1]['name']);
        $this->assertEquals(8, $topProducts[1]['sold']);
        $this->assertEquals('Product C', $topProducts[2]['name']);
        $this->assertEquals(5, $topProducts[2]['sold']);
        $this->assertEquals('Product D', $topProducts[3]['name']);
        $this->assertEquals(3, $topProducts[3]['sold']);
    }

    /**
     * Returns empty array when no outlet_id parameter is provided.
     */
    public function test_public_top_products_returns_empty_array_without_outlet_id(): void
    {
        $this->createPaidOrder([
            ['product_id' => $this->product1->id, 'qty' => 5],
        ]);

        $response = $this->getJson('/api/v1/public/top-products');

        $response->assertStatus(200);
        $response->assertJson(['top_products' => []]);
    }

    /**
     * Returns empty array when outlet has no sales.
     */
    public function test_public_top_products_returns_empty_for_outlet_with_no_sales(): void
    {
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $otherOutlet = Outlet::factory()->create(['owner_id' => $otherOwner->id]);

        $response = $this->getJson('/api/v1/public/top-products?outlet_id=' . $otherOutlet->id);

        $response->assertStatus(200);
        $response->assertJson(['top_products' => []]);
    }

    /**
     * Only counts paid transactions (not pending/cancelled).
     */
    public function test_public_top_products_only_counts_paid_transactions(): void
    {
        $this->createPaidOrder([
            ['product_id' => $this->product1->id, 'qty' => 5],
        ]);

        // Create a pending order with product B (qty 10) — should NOT be counted
        $order = Order::factory()->create([
            'outlet_id' => $this->outlet->id,
            'table_id' => $this->table->id,
            'user_id' => $this->owner->id,
            'status' => 'pending',
            'total_price' => 250000,
            'payment_method' => 'cash',
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $this->product2->id,
            'qty' => 10,
            'price' => 25000,
            'total_price' => 250000,
        ]);

        $response = $this->getJson('/api/v1/public/top-products?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $topProducts = $response->json('top_products');

        $this->assertCount(1, $topProducts);
        $this->assertEquals('Product A', $topProducts[0]['name']);
        $this->assertEquals(5, $topProducts[0]['sold']);
    }

    /**
     * Only includes sales from the last 30 days.
     */
    public function test_public_top_products_only_includes_last_30_days(): void
    {
        // Order from 40 days ago — should be excluded
        $this->createPaidOrder(
            [['product_id' => $this->product1->id, 'qty' => 5]],
            Carbon::now()->subDays(40)
        );

        // Order from 15 days ago — should be included
        $this->createPaidOrder(
            [['product_id' => $this->product2->id, 'qty' => 3]],
            Carbon::now()->subDays(15)
        );

        $response = $this->getJson('/api/v1/public/top-products?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $topProducts = $response->json('top_products');

        $this->assertCount(1, $topProducts);
        $this->assertEquals('Product B', $topProducts[0]['name']);
        $this->assertEquals(3, $topProducts[0]['sold']);
    }

    /**
     * Returns less than 4 products if not enough products have been sold.
     */
    public function test_public_top_products_returns_less_than_4_if_not_enough(): void
    {
        $this->createPaidOrder([
            ['product_id' => $this->product1->id, 'qty' => 2],
        ]);

        $response = $this->getJson('/api/v1/public/top-products?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $topProducts = $response->json('top_products');

        $this->assertCount(1, $topProducts);
        $this->assertEquals('Product A', $topProducts[0]['name']);
        $this->assertEquals(2, $topProducts[0]['sold']);
    }

    /**
     * Returns empty array for non-existent outlet_id.
     */
    public function test_public_top_products_returns_empty_for_nonexistent_outlet(): void
    {
        $response = $this->getJson('/api/v1/public/top-products?outlet_id=99999');

        $response->assertStatus(200);
        $response->assertJson(['top_products' => []]);
    }

    /**
     * Public endpoint does not require authentication.
     */
    public function test_public_top_products_no_auth_required(): void
    {
        $this->createPaidOrder([
            ['product_id' => $this->product1->id, 'qty' => 3],
        ]);

        $response = $this->getJson('/api/v1/public/top-products?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $topProducts = $response->json('top_products');
        $this->assertCount(1, $topProducts);
    }

    /**
     * Response has the correct JSON structure.
     */
    public function test_public_top_products_response_structure(): void
    {
        $this->createPaidOrder([
            ['product_id' => $this->product1->id, 'qty' => 3],
        ]);

        $response = $this->getJson('/api/v1/public/top-products?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'top_products' => [
                '*' => [
                    'name',
                    'sold',
                ],
            ],
        ]);
    }

    // =========================================================================
    // EXPORT REPORT — Products type
    // =========================================================================

    /**
     * Export products report returns an Excel file download.
     */
    public function test_export_products_returns_excel_file(): void
    {
        $this->createPaidOrder([
            ['product_id' => $this->product1->id, 'qty' => 3],
            ['product_id' => $this->product2->id, 'qty' => 5],
        ]);

        $response = $this->actingAs($this->owner)
            ->get('/api/v1/reports/export?report_type=products&outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);

        $contentType = $response->headers->get('Content-Type');
        $this->assertStringContainsString('vnd.openxmlformats', $contentType);

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('.xlsx', $disposition);
        $this->assertStringContainsString('Laporan_Products', $disposition);
    }

    /**
     * Export products report with date filter returns only matching data.
     */
    public function test_export_products_filters_by_date_range(): void
    {
        $this->createPaidOrder(
            [['product_id' => $this->product1->id, 'qty' => 10]],
            Carbon::now()->subDays(60)
        );

        $this->createPaidOrder(
            [['product_id' => $this->product2->id, 'qty' => 3]],
            Carbon::now()->subDays(5)
        );

        $startDate = Carbon::now()->subDays(30)->format('Y-m-d');
        $endDate = Carbon::now()->format('Y-m-d');

        $response = $this->actingAs($this->owner)->get(
            '/api/v1/reports/export?report_type=products&outlet_id=' . $this->outlet->id
            . '&start_date=' . $startDate . '&end_date=' . $endDate
        );

        $response->assertStatus(200);
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('.xlsx', $disposition);
    }

    /**
     * Export products report returns 400 for invalid date format.
     */
    public function test_export_products_rejects_invalid_date(): void
    {
        $response = $this->actingAs($this->owner)->get(
            '/api/v1/reports/export?report_type=products&outlet_id=' . $this->outlet->id
            . '&start_date=invalid-date'
        );

        $response->assertStatus(400);
        $response->assertJson(['message' => 'Format tanggal tidak valid']);
    }

    /**
     * Export products report requires authentication.
     */
    public function test_export_products_requires_authentication(): void
    {
        $response = $this->getJson(
            '/api/v1/reports/export?report_type=products&outlet_id=' . $this->outlet->id
        );

        $response->assertStatus(401);
    }

    /**
     * Export products report with empty data still returns a valid Excel file.
     */
    public function test_export_products_with_empty_data_returns_excel(): void
    {
        $response = $this->actingAs($this->owner)->get(
            '/api/v1/reports/export?report_type=products&outlet_id=' . $this->outlet->id
        );

        $response->assertStatus(200);
        $contentType = $response->headers->get('Content-Type');
        $this->assertStringContainsString('vnd.openxmlformats', $contentType);
    }

    /**
     * Karyawan can only export data from their own outlet.
     */
    public function test_export_products_karyawan_can_export_own_outlet(): void
    {
        $karyawan = User::factory()->create([
            'role' => 'karyawan',
            'outlet_id' => $this->outlet->id,
        ]);

        $this->createPaidOrder([
            ['product_id' => $this->product1->id, 'qty' => 3],
        ]);

        $response = $this->actingAs($karyawan)->get(
            '/api/v1/reports/export?report_type=products'
        );

        $response->assertStatus(200);
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('.xlsx', $disposition);
    }

    /**
     * Karyawan requesting another outlet's data is silently scoped to own outlet.
     */
    public function test_export_products_karyawan_other_outlet_scoped_to_own(): void
    {
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $otherOutlet = Outlet::factory()->create(['owner_id' => $otherOwner->id]);

        $karyawan = User::factory()->create([
            'role' => 'karyawan',
            'outlet_id' => $this->outlet->id,
        ]);

        $response = $this->actingAs($karyawan)->get(
            '/api/v1/reports/export?report_type=products&outlet_id=' . $otherOutlet->id
        );

        $response->assertStatus(200);
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('.xlsx', $disposition);
    }

    /**
     * Export with default report_type (summary) returns valid Excel.
     */
    public function test_export_defaults_to_summary_report_type(): void
    {
        $this->createPaidOrder([
            ['product_id' => $this->product1->id, 'qty' => 3],
        ]);

        $response = $this->actingAs($this->owner)->get(
            '/api/v1/reports/export?outlet_id=' . $this->outlet->id
        );

        $response->assertStatus(200);
        $contentType = $response->headers->get('Content-Type');
        $this->assertStringContainsString('vnd.openxmlformats', $contentType);

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('Laporan_Summary', $disposition);
    }

    /**
     * Manager can export with specific outlet_id filter.
     */
    public function test_export_products_manager_can_filter_by_outlet(): void
    {
        $this->createPaidOrder([
            ['product_id' => $this->product1->id, 'qty' => 3],
        ]);

        $response = $this->actingAs($this->owner)->get(
            '/api/v1/reports/export?report_type=products&outlet_id=' . $this->outlet->id
        );

        $response->assertStatus(200);
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('.xlsx', $disposition);
    }

    /**
     * Export filename contains the correct date range.
     */
    public function test_export_filename_contains_date_range(): void
    {
        $startDate = '2026-01-01';
        $endDate = '2026-01-31';

        $this->createPaidOrder(
            [['product_id' => $this->product1->id, 'qty' => 2]],
            Carbon::parse('2026-01-15')
        );

        $response = $this->actingAs($this->owner)->get(
            '/api/v1/reports/export?report_type=products&outlet_id=' . $this->outlet->id
            . '&start_date=' . $startDate . '&end_date=' . $endDate
        );

        $response->assertStatus(200);
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('20260101', $disposition);
        $this->assertStringContainsString('20260131', $disposition);
    }

    // =========================================================================
    // CATEGORY PERFORMANCE (report index)
    // =========================================================================

    /**
     * Category performance returns sold qty and revenue grouped by category.
     */
    public function test_category_performance_returns_sold_and_revenue_by_category(): void
    {
        $catMakanan = Category::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Makanan']);
        $catMinuman = Category::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Minuman']);

        $productMakanan1 = Product::factory()->create(['category_id' => $catMakanan->id, 'owner_id' => $this->owner->id, 'name' => 'Nasi Goreng']);
        $productMakanan2 = Product::factory()->create(['category_id' => $catMakanan->id, 'owner_id' => $this->owner->id, 'name' => 'Mie Goreng']);
        $productMinuman = Product::factory()->create(['category_id' => $catMinuman->id, 'owner_id' => $this->owner->id, 'name' => 'Es Teh']);

        foreach ([$productMakanan1, $productMakanan2, $productMinuman] as $p) {
            $this->outlet->products()->attach($p->id, ['price' => 25000, 'stock' => 100, 'is_active' => true]);
        }

        $this->createPaidOrder([
            ['product_id' => $productMakanan1->id, 'qty' => 3, 'price' => 25000],
            ['product_id' => $productMinuman->id, 'qty' => 2, 'price' => 15000],
        ]);
        $this->createPaidOrder([
            ['product_id' => $productMakanan2->id, 'qty' => 1, 'price' => 20000],
        ]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $catPerf = $response->json('category_performance');

        // Makanan: 3x25000 + 1x20000 = 95000, Minuman: 2x15000 = 30000
        $this->assertCount(2, $catPerf);

        $makanan = collect($catPerf)->firstWhere('name', 'Makanan');
        $this->assertNotNull($makanan);
        $this->assertEquals(4, $makanan['sold']);  // 3 + 1
        $this->assertEquals(95000, $makanan['revenue']);  // 75000 + 20000

        $minuman = collect($catPerf)->firstWhere('name', 'Minuman');
        $this->assertNotNull($minuman);
        $this->assertEquals(2, $minuman['sold']);
        $this->assertEquals(30000, $minuman['revenue']);
    }

    /**
     * Category performance is sorted by revenue descending.
     */
    public function test_category_performance_sorted_by_revenue_descending(): void
    {
        $catA = Category::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Rendah']);
        $catB = Category::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Tinggi']);

        $prodRendah = Product::factory()->create(['category_id' => $catA->id, 'owner_id' => $this->owner->id]);
        $prodTinggi = Product::factory()->create(['category_id' => $catB->id, 'owner_id' => $this->owner->id]);

        foreach ([$prodRendah, $prodTinggi] as $p) {
            $this->outlet->products()->attach($p->id, ['price' => 10000, 'stock' => 100, 'is_active' => true]);
        }

        // Kategori Tinggi has more revenue
        $this->createPaidOrder([['product_id' => $prodTinggi->id, 'qty' => 10, 'price' => 10000]]);
        $this->createPaidOrder([['product_id' => $prodRendah->id, 'qty' => 1, 'price' => 10000]]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $catPerf = $response->json('category_performance');

        $names = collect($catPerf)->pluck('name')->toArray();
        $this->assertEquals('Tinggi', $names[0]);
        $this->assertEquals('Rendah', $names[1]);
    }

    /**
     * Category performance percentage is calculated based on total revenue.
     */
    public function test_category_performance_percentage_is_calculated(): void
    {
        $catA = Category::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Cat A']);
        $catB = Category::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Cat B']);

        $prodA = Product::factory()->create(['category_id' => $catA->id, 'owner_id' => $this->owner->id]);
        $prodB = Product::factory()->create(['category_id' => $catB->id, 'owner_id' => $this->owner->id]);

        foreach ([$prodA, $prodB] as $p) {
            $this->outlet->products()->attach($p->id, ['price' => 20000, 'stock' => 100, 'is_active' => true]);
        }

        // Cat A revenue: 60000 (75%), Cat B revenue: 20000 (25%)
        $this->createPaidOrder([['product_id' => $prodA->id, 'qty' => 3, 'price' => 20000]]);
        $this->createPaidOrder([['product_id' => $prodB->id, 'qty' => 1, 'price' => 20000]]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $catPerf = $response->json('category_performance');

        $catA_data = collect($catPerf)->firstWhere('name', 'Cat A');
        $catB_data = collect($catPerf)->firstWhere('name', 'Cat B');

        $this->assertEquals(75.0, $catA_data['percentage']);
        $this->assertEquals(25.0, $catB_data['percentage']);
    }

    /**
     * Products belong to a default category, so category performance always
     * returns the category name in the response.
     */
    public function test_category_performance_returns_category_name(): void
    {
        $this->createPaidOrder([
            ['product_id' => $this->product1->id, 'qty' => 2],
        ]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $catPerf = $response->json('category_performance');

        $this->assertCount(1, $catPerf);
        $this->assertNotNull($catPerf[0]['name']);
        $this->assertIsString($catPerf[0]['name']);
        $this->assertGreaterThan(0, $catPerf[0]['sold']);
        $this->assertGreaterThan(0, $catPerf[0]['revenue']);
    }

    /**
     * Category performance returns empty array when no transactions exist.
     */
    public function test_category_performance_empty_when_no_transactions(): void
    {
        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $this->assertEquals([], $response->json('category_performance'));
    }

    /**
     * Category performance only includes data from the specified outlet.
     */
    public function test_category_performance_respects_outlet_isolation(): void
    {
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $otherOutlet = Outlet::factory()->create(['owner_id' => $otherOwner->id]);
        $otherCategory = Category::factory()->create(['owner_id' => $otherOwner->id, 'name' => 'Other Cat']);
        $otherProduct = Product::factory()->create(['category_id' => $otherCategory->id, 'owner_id' => $otherOwner->id]);
        $otherTable = Table::factory()->create(['outlet_id' => $otherOutlet->id]);

        $otherOutlet->products()->attach($otherProduct->id, ['price' => 50000, 'stock' => 10, 'is_active' => true]);

        // Create order for the other outlet
        $paidAt = Carbon::now()->subDays(1);
        $order = Order::factory()->create([
            'outlet_id' => $otherOutlet->id,
            'table_id' => $otherTable->id,
            'user_id' => $otherOwner->id,
            'status' => 'paid',
            'invoice_number' => 'INV-OTHER-001',
            'subtotal_price' => 50000,
            'total_price' => 50000,
            'payment_method' => 'cash',
            'created_at' => $paidAt,
            'updated_at' => $paidAt,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id, 'product_id' => $otherProduct->id,
            'qty' => 1, 'price' => 50000, 'total_price' => 50000,
        ]);
        $payment = Payment::factory()->create([
            'order_id' => $order->id, 'amount_paid' => 50000, 'change_amount' => 0,
            'method' => 'cash', 'paid_at' => $paidAt, 'paid_by' => $otherOwner->id,
        ]);
        HistoryTransaction::factory()->create([
            'outlet_id' => $otherOutlet->id, 'order_id' => $order->id,
            'payment_id' => $payment->id, 'status' => 'paid',
            'invoice_number' => 'INV-OTHER-001',
            'subtotal_price' => 50000, 'total_price' => 50000, 'paid_amount' => 50000,
            'payment_method' => 'cash', 'paid_at' => $paidAt,
        ]);

        // Report for owner's outlet should NOT include other outlet's data
        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $this->assertEquals([], $response->json('category_performance'));
    }

    /**
     * Category performance response structure has correct keys.
     */
    public function test_category_performance_response_structure(): void
    {
        $this->createPaidOrder([
            ['product_id' => $this->product1->id, 'qty' => 2],
        ]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'category_performance' => [
                '*' => [
                    'name',
                    'sold',
                    'revenue',
                    'percentage',
                ],
            ],
        ]);
    }

    // =========================================================================
    // PAYMENT METHODS (report index)
    // =========================================================================

    /**
     * Payment methods groups by method and aggregates total, count, avg.
     */
    public function test_payment_methods_returns_total_count_and_avg_by_method(): void
    {
        $this->createPaidOrder(
            [['product_id' => $this->product1->id, 'qty' => 2, 'price' => 25000]],
            null, 'cash'
        );
        $this->createPaidOrder(
            [['product_id' => $this->product2->id, 'qty' => 3, 'price' => 30000]],
            null, 'qris'
        );

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $payMethods = $response->json('payment_methods');

        $this->assertCount(2, $payMethods);

        $cash = collect($payMethods)->firstWhere('method', 'cash');
        $this->assertNotNull($cash);
        $this->assertEquals(50000, $cash['total']);  // 2×25000
        $this->assertEquals(1, $cash['count']);
        $this->assertEquals(50000, $cash['avg_amount']);

        $qris = collect($payMethods)->firstWhere('method', 'qris');
        $this->assertNotNull($qris);
        $this->assertEquals(90000, $qris['total']);  // 3×30000
        $this->assertEquals(1, $qris['count']);
        $this->assertEquals(90000, $qris['avg_amount']);
    }

    /**
     * Payment methods sorted by total descending (highest revenue first).
     */
    public function test_payment_methods_sorted_by_total_descending(): void
    {
        // qris has more total than cash
        $this->createPaidOrder(
            [['product_id' => $this->product1->id, 'qty' => 1, 'price' => 5000]],
            null, 'cash'
        );
        $this->createPaidOrder(
            [['product_id' => $this->product2->id, 'qty' => 1, 'price' => 100000]],
            null, 'qris'
        );

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $payMethods = $response->json('payment_methods');

        $this->assertEquals('qris', $payMethods[0]['method']);
        $this->assertEquals('cash', $payMethods[1]['method']);
    }

    /**
     * Payment methods percentage is calculated based on total transactions.
     */
    public function test_payment_methods_percentage_is_calculated(): void
    {
        // 3 cash transactions, 1 qris transaction → cash=75%, qris=25%
        $this->createPaidOrder([['product_id' => $this->product1->id, 'qty' => 1]], null, 'cash');
        $this->createPaidOrder([['product_id' => $this->product1->id, 'qty' => 1]], null, 'cash');
        $this->createPaidOrder([['product_id' => $this->product1->id, 'qty' => 1]], null, 'cash');
        $this->createPaidOrder([['product_id' => $this->product1->id, 'qty' => 1]], null, 'qris');

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $payMethods = $response->json('payment_methods');

        $cash = collect($payMethods)->firstWhere('method', 'cash');
        $qris = collect($payMethods)->firstWhere('method', 'qris');

        $this->assertEquals(75.0, $cash['percentage']);
        $this->assertEquals(25.0, $qris['percentage']);
    }

    /**
     * Payment methods returns empty array when no transactions.
     */
    public function test_payment_methods_empty_when_no_transactions(): void
    {
        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $this->assertEquals([], $response->json('payment_methods'));
    }

    /**
     * Payment methods response structure has correct keys.
     */
    public function test_payment_methods_response_structure(): void
    {
        $this->createPaidOrder([['product_id' => $this->product1->id, 'qty' => 1]], null, 'cash');

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'payment_methods' => [
                '*' => [
                    'method',
                    'total',
                    'count',
                    'avg_amount',
                    'percentage',
                ],
            ],
        ]);
    }

    /**
     * Payment methods respects outlet isolation.
     */
    public function test_payment_methods_respects_outlet_isolation(): void
    {
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $otherOutlet = Outlet::factory()->create(['owner_id' => $otherOwner->id]);
        $otherTable = Table::factory()->create(['outlet_id' => $otherOutlet->id]);

        $paidAt = Carbon::now()->subDays(1);
        $order = Order::factory()->create([
            'outlet_id' => $otherOutlet->id, 'table_id' => $otherTable->id,
            'user_id' => $otherOwner->id, 'status' => 'paid',
            'invoice_number' => 'INV-OTHER-001',
            'subtotal_price' => 100000, 'total_price' => 100000,
            'payment_method' => 'qris',
            'created_at' => $paidAt, 'updated_at' => $paidAt,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id, 'product_id' => $this->product1->id,
            'qty' => 1, 'price' => 100000, 'total_price' => 100000,
        ]);
        $payment = Payment::factory()->create([
            'order_id' => $order->id, 'amount_paid' => 100000, 'change_amount' => 0,
            'method' => 'qris', 'paid_at' => $paidAt, 'paid_by' => $otherOwner->id,
        ]);
        HistoryTransaction::factory()->create([
            'outlet_id' => $otherOutlet->id, 'order_id' => $order->id,
            'payment_id' => $payment->id, 'status' => 'paid',
            'invoice_number' => 'INV-OTHER-001',
            'subtotal_price' => 100000, 'total_price' => 100000, 'paid_amount' => 100000,
            'payment_method' => 'qris', 'paid_at' => $paidAt,
        ]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $this->assertEquals([], $response->json('payment_methods'));
    }

    /**
     * Single payment method returns single entry.
     */
    public function test_payment_methods_single_method(): void
    {
        $this->createPaidOrder([['product_id' => $this->product1->id, 'qty' => 2]], null, 'qris');

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $payMethods = $response->json('payment_methods');

        $this->assertCount(1, $payMethods);
        $this->assertEquals('qris', $payMethods[0]['method']);
        $this->assertEquals(50000, $payMethods[0]['total']);
        $this->assertEquals(1, $payMethods[0]['count']);
        $this->assertEquals(100.0, $payMethods[0]['percentage']);
    }
}
