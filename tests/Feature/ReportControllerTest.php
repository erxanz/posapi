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
use App\Models\Station;
use App\Models\ShiftKaryawan;
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
        $this->category = Category::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Default Category']);

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

    private function createPaidOrder(array $items, ?Carbon $paidAt = null, string $paymentMethod = 'cash', ?int $cashierId = null, ?int $tableId = null): void
    {
        $paidAt ??= Carbon::now()->subDays(1);
        $cashierId ??= $this->owner->id;
        $tableId ??= $this->table->id;

        $invoiceNum = 'INV-' . now()->format('Ymd') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);

        $order = Order::factory()->create([
            'outlet_id' => $this->outlet->id,
            'table_id' => $tableId,
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
            $orderItemData = [
                'order_id' => $order->id,
                'product_id' => $itemData['product_id'],
                'qty' => $itemData['qty'],
                'price' => $itemData['price'] ?? 25000,
                'total_price' => ($itemData['price'] ?? 25000) * $itemData['qty'],
                'station_id' => $itemData['station_id'] ?? null,
            ];
            OrderItem::factory()->create($orderItemData);
        }

        $payment = Payment::factory()->create([
            'order_id' => $order->id,
            'amount_paid' => $order->total_price,
            'change_amount' => 0,
            'method' => $paymentMethod,
            'paid_at' => $paidAt,
            'paid_by' => $cashierId,
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
            'cashier_id' => $cashierId,
        ]);
    }

    private function createOrderOnDate(string $date, array $items, ?string $time = null, string $paymentMethod = 'cash', ?int $cashierId = null, ?int $tableId = null): void
    {
        $carbon = Carbon::parse($date);
        if ($time !== null) {
            $parts = explode(':', $time);
            $carbon->setTime((int) $parts[0], (int) ($parts[1] ?? 0), (int) ($parts[2] ?? 0));
        }
        $this->createPaidOrder($items, $carbon, $paymentMethod, $cashierId, $tableId);
    }

    private function createOtherOutletOrder(array $overrides = []): object
    {
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $otherOutlet = Outlet::factory()->create(['owner_id' => $otherOwner->id]);
        $otherTable = Table::factory()->create(['outlet_id' => $otherOutlet->id]);

        $productId = $overrides['product_id'] ?? $this->product1->id;
        $cashierId = $overrides['cashier_id'] ?? $otherOwner->id;
        $unitPrice = $overrides['price'] ?? 50000;
        $paidAt = $overrides['paid_at'] ?? Carbon::now()->subDays(1);

        $order = Order::factory()->create([
            'outlet_id' => $otherOutlet->id, 'table_id' => $otherTable->id,
            'user_id' => $otherOwner->id, 'status' => 'paid',
            'invoice_number' => 'INV-OTHER-001',
            'subtotal_price' => $unitPrice, 'total_price' => $unitPrice,
            'payment_method' => 'cash',
            'created_at' => $paidAt, 'updated_at' => $paidAt,
        ]);

        $orderItemData = [
            'order_id' => $order->id, 'product_id' => $productId,
            'qty' => 1, 'price' => $unitPrice, 'total_price' => $unitPrice,
        ];
        if (isset($overrides['station_id'])) {
            $orderItemData['station_id'] = $overrides['station_id'];
        }
        OrderItem::factory()->create($orderItemData);

        $payment = Payment::factory()->create([
            'order_id' => $order->id, 'amount_paid' => $unitPrice, 'change_amount' => 0,
            'method' => 'cash', 'paid_at' => $paidAt, 'paid_by' => $cashierId,
        ]);

        $historyData = [
            'outlet_id' => $otherOutlet->id, 'order_id' => $order->id,
            'payment_id' => $payment->id, 'status' => 'paid',
            'invoice_number' => 'INV-OTHER-001',
            'subtotal_price' => $unitPrice, 'total_price' => $unitPrice, 'paid_amount' => $unitPrice,
            'payment_method' => 'cash', 'paid_at' => $paidAt,
        ];
        if ($cashierId !== $otherOwner->id) {
            $historyData['cashier_id'] = $cashierId;
        }
        HistoryTransaction::factory()->create($historyData);

        return (object) [
            'owner' => $otherOwner,
            'outlet' => $otherOutlet,
            'table' => $otherTable,
            'order' => $order,
        ];
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

        $this->createOrderOnDate('2026-01-15', [['product_id' => $this->product1->id, 'qty' => 2]]);

        $response = $this->actingAs($this->owner)->get(
            '/api/v1/reports/export?report_type=products&outlet_id=' . $this->outlet->id
            . '&start_date=' . $startDate . '&end_date=' . $endDate
        );

        $response->assertStatus(200);
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('20260101', $disposition);
        $this->assertStringContainsString('20260131', $disposition);
    }

    /**
     * Export with report_type=shifts returns valid Excel file with shift data.
     */
    public function test_export_shifts_returns_excel(): void
    {
        ShiftKaryawan::factory()->create([
            'outlet_id' => $this->outlet->id,
            'user_id' => $this->owner->id,
            'opening_balance' => 100000,
            'closing_balance_system' => 300000,
            'difference' => 5000,
            'started_at' => Carbon::now()->subDays(1)->setTime(8, 0, 0),
            'ended_at' => Carbon::now()->subDays(1)->setTime(17, 0, 0),
            'status' => 'closed',
        ]);

        $response = $this->actingAs($this->owner)->get(
            '/api/v1/reports/export?report_type=shifts&outlet_id=' . $this->outlet->id
        );

        $response->assertStatus(200);
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('.xlsx', $disposition);
        $this->assertStringContainsString('Laporan_Shifts', $disposition);
    }

    /**
     * Export with report_type=staff returns valid Excel file with cashier performance data.
     */
    public function test_export_staff_returns_excel(): void
    {
        $cashier = User::factory()->create(['name' => 'Kasir Test', 'role' => 'karyawan', 'outlet_id' => $this->outlet->id]);
        $this->createPaidOrder(
            [['product_id' => $this->product1->id, 'qty' => 2, 'price' => 25000]],
            null, 'cash', $cashier->id
        );

        $response = $this->actingAs($this->owner)->get(
            '/api/v1/reports/export?report_type=staff&outlet_id=' . $this->outlet->id
        );

        $response->assertStatus(200);
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('.xlsx', $disposition);
        $this->assertStringContainsString('Laporan_Staff', $disposition);
    }

    /**
     * Export with report_type=sales returns valid Excel file with daily sales data.
     */
    public function test_export_sales_type_returns_excel(): void
    {
        $this->createOrderOnDate('2026-07-07', [['product_id' => $this->product1->id, 'qty' => 3, 'price' => 25000]]);

        $response = $this->actingAs($this->owner)->get(
            '/api/v1/reports/export?report_type=sales&outlet_id=' . $this->outlet->id
            . '&start_date=2026-07-01&end_date=2026-07-31'
        );

        $response->assertStatus(200);
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('.xlsx', $disposition);
        $this->assertStringContainsString('Laporan_Sales', $disposition);
    }

    /**
     * Export with format=pdf returns a PDF file download.
     */
    public function test_export_pdf_format_returns_pdf(): void
    {
        $this->createOrderOnDate('2026-07-07', [['product_id' => $this->product1->id, 'qty' => 2, 'price' => 25000]]);

        $response = $this->actingAs($this->owner)->get(
            '/api/v1/reports/export?report_type=summary&outlet_id=' . $this->outlet->id
            . '&format=pdf'
        );

        $response->assertStatus(200);
        $contentType = $response->headers->get('Content-Type');
        $this->assertStringContainsString('pdf', strtolower($contentType));
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('.pdf', $disposition);
        $this->assertStringContainsString('Laporan_Summary', $disposition);
    }

    /**
     * Export with report_type=shifts returns valid file even when no shift data.
     */
    public function test_export_shifts_empty_data_returns_excel(): void
    {
        $response = $this->actingAs($this->owner)->get(
            '/api/v1/reports/export?report_type=shifts&outlet_id=' . $this->outlet->id
        );

        $response->assertStatus(200);
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('.xlsx', $disposition);
    }

    /**
     * Export with report_type=staff returns valid file even when no transaction data.
     */
    public function test_export_staff_empty_data_returns_excel(): void
    {
        $response = $this->actingAs($this->owner)->get(
            '/api/v1/reports/export?report_type=staff&outlet_id=' . $this->outlet->id
        );

        $response->assertStatus(200);
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('.xlsx', $disposition);
    }

    /**
     * Export with report_type=sales returns valid file even when no transaction data.
     */
    public function test_export_sales_empty_data_returns_excel(): void
    {
        $response = $this->actingAs($this->owner)->get(
            '/api/v1/reports/export?report_type=sales&outlet_id=' . $this->outlet->id
            . '&start_date=2026-07-01&end_date=2026-07-31'
        );

        $response->assertStatus(200);
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('.xlsx', $disposition);
    }

    /**
     * Export with report_type=sales rejects invalid date format.
     */
    public function test_export_sales_rejects_invalid_date(): void
    {
        $response = $this->actingAs($this->owner)->get(
            '/api/v1/reports/export?report_type=sales&outlet_id=' . $this->outlet->id
            . '&start_date=invalid-date'
        );

        $response->assertStatus(400);
        $response->assertJson(['message' => 'Format tanggal tidak valid']);
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
        $otherOutlet->products()->attach($otherProduct->id, ['price' => 50000, 'stock' => 10, 'is_active' => true]);

        $this->createOtherOutletOrder(['product_id' => $otherProduct->id]);

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
        $this->createOtherOutletOrder();

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

    // =========================================================================
    // HOURLY SALES (report index)
    // =========================================================================

    /**
     * Hourly sales always returns 24 entries (hours 0-23).
     */
    public function test_hourly_sales_always_returns_24_hours(): void
    {
        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $hourlySales = $response->json('hourly_sales');

        $this->assertCount(24, $hourlySales);
        // Verify all hours 0-23 are present
        $hours = collect($hourlySales)->pluck('hour')->toArray();
        $this->assertEquals(range(0, 23), $hours);
    }

    /**
     * Hourly sales has transactions and revenue at the specific hour.
     */
    public function test_hourly_sales_counts_transactions_at_correct_hour(): void
    {
        $baseDate = Carbon::now()->subDays(1);
        $hour10 = (clone $baseDate)->setTime(10, 0, 0);

        $this->createPaidOrder(
            [['product_id' => $this->product1->id, 'qty' => 2, 'price' => 25000]],
            $hour10
        );

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $hourlySales = $response->json('hourly_sales');

        $hour10Data = collect($hourlySales)->firstWhere('hour', 10);
        $this->assertNotNull($hour10Data);
        $this->assertEquals(1, $hour10Data['transactions']);
        $this->assertEquals(50000, $hour10Data['revenue']);
    }

    /**
     * Multiple transactions at the same hour are aggregated.
     */
    public function test_hourly_sales_aggregates_multiple_orders_in_same_hour(): void
    {
        $baseDate = Carbon::now()->subDays(1);
        $hour15 = (clone $baseDate)->setTime(15, 10, 0);
        $hour15b = (clone $baseDate)->setTime(15, 45, 0);

        $this->createPaidOrder([['product_id' => $this->product1->id, 'qty' => 2, 'price' => 25000]], $hour15);
        $this->createPaidOrder([['product_id' => $this->product2->id, 'qty' => 3, 'price' => 10000]], $hour15b);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $hourlySales = $response->json('hourly_sales');

        $hour15Data = collect($hourlySales)->firstWhere('hour', 15);
        $this->assertNotNull($hour15Data);
        $this->assertEquals(2, $hour15Data['transactions']);
        $this->assertEquals(80000, $hour15Data['revenue']);  // 50000 + 30000
    }

    /**
     * Hours without any transactions show 0 transactions and 0 revenue.
     */
    public function test_hourly_sales_empty_hours_show_zero(): void
    {
        $baseDate = Carbon::now()->subDays(1);
        $hour8 = (clone $baseDate)->setTime(8, 0, 0);

        $this->createPaidOrder([['product_id' => $this->product1->id, 'qty' => 1]], $hour8);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $hourlySales = $response->json('hourly_sales');

        // Hour 7 should have no data
        $hour7Data = collect($hourlySales)->firstWhere('hour', 7);
        $this->assertEquals(0, $hour7Data['transactions']);
        $this->assertEquals(0, $hour7Data['revenue']);

        // Hour 8 should have data
        $hour8Data = collect($hourlySales)->firstWhere('hour', 8);
        $this->assertEquals(1, $hour8Data['transactions']);
    }

    /**
     * Hourly sales response structure has correct keys.
     */
    public function test_hourly_sales_response_structure(): void
    {
        $baseDate = Carbon::now()->subDays(1);
        $this->createPaidOrder([['product_id' => $this->product1->id, 'qty' => 1]], $baseDate);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'hourly_sales' => [
                '*' => [
                    'hour',
                    'transactions',
                    'revenue',
                ],
            ],
        ]);
    }

    /**
     * Hourly sales from different days but same hour are grouped together.
     */
    public function test_hourly_sales_groups_same_hour_across_days(): void
    {
        $today = Carbon::now();
        $hour12_day1 = (clone $today)->subDays(2)->setTime(12, 0, 0);
        $hour12_day2 = (clone $today)->subDays(1)->setTime(12, 30, 0);

        $this->createPaidOrder([['product_id' => $this->product1->id, 'qty' => 1, 'price' => 50000]], $hour12_day1);
        $this->createPaidOrder([['product_id' => $this->product1->id, 'qty' => 2, 'price' => 25000]], $hour12_day2);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $hourlySales = $response->json('hourly_sales');

        $hour12Data = collect($hourlySales)->firstWhere('hour', 12);
        $this->assertEquals(2, $hour12Data['transactions']);
        $this->assertEquals(100000, $hour12Data['revenue']);  // 50000 + 50000
    }

    /**
     * Hourly sales respects outlet isolation.
     */
    public function test_hourly_sales_respects_outlet_isolation(): void
    {
        $this->createOtherOutletOrder();

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $hourlySales = $response->json('hourly_sales');

        // All hours should be 0 since there's no data for this outlet
        collect($hourlySales)->each(function ($hour) {
            $this->assertEquals(0, $hour['transactions']);
            $this->assertEquals(0, $hour['revenue']);
        });
    }

    // =========================================================================
    // CASHIER PERFORMANCE (report index)
    // =========================================================================

    /**
     * Cashier performance groups by cashier and shows transactions, revenue, avg.
     */
    public function test_cashier_performance_returns_data_grouped_by_cashier(): void
    {
        $cashierA = User::factory()->create(['name' => 'Kasir A', 'role' => 'karyawan', 'outlet_id' => $this->outlet->id]);
        $cashierB = User::factory()->create(['name' => 'Kasir B', 'role' => 'karyawan', 'outlet_id' => $this->outlet->id]);

        $this->createPaidOrder([['product_id' => $this->product1->id, 'qty' => 2, 'price' => 25000]], null, 'cash', $cashierA->id);
        $this->createPaidOrder([['product_id' => $this->product2->id, 'qty' => 3, 'price' => 20000]], null, 'cash', $cashierB->id);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $cashierPerf = $response->json('cashier_performance');

        $this->assertCount(2, $cashierPerf);

        $kasirA = collect($cashierPerf)->firstWhere('name', 'Kasir A');
        $this->assertNotNull($kasirA);
        $this->assertEquals(1, $kasirA['transactions']);
        $this->assertEquals(50000, $kasirA['revenue']);
        $this->assertEquals(50000, $kasirA['avg_trx']);
        $this->assertEquals($this->outlet->name, $kasirA['outlet_name']);

        $kasirB = collect($cashierPerf)->firstWhere('name', 'Kasir B');
        $this->assertNotNull($kasirB);
        $this->assertEquals(1, $kasirB['transactions']);
        $this->assertEquals(60000, $kasirB['revenue']);
        $this->assertEquals(60000, $kasirB['avg_trx']);
    }

    /**
     * Cashier performance is sorted by revenue descending.
     */
    public function test_cashier_performance_sorted_by_revenue_descending(): void
    {
        $cashierLow = User::factory()->create(['name' => 'Rendah', 'role' => 'karyawan', 'outlet_id' => $this->outlet->id]);
        $cashierHigh = User::factory()->create(['name' => 'Tinggi', 'role' => 'karyawan', 'outlet_id' => $this->outlet->id]);

        $this->createPaidOrder([['product_id' => $this->product1->id, 'qty' => 1, 'price' => 5000]], null, 'cash', $cashierLow->id);
        $this->createPaidOrder([['product_id' => $this->product2->id, 'qty' => 1, 'price' => 100000]], null, 'cash', $cashierHigh->id);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $cashierPerf = $response->json('cashier_performance');

        $this->assertEquals('Tinggi', $cashierPerf[0]['name']);
        $this->assertEquals('Rendah', $cashierPerf[1]['name']);
    }

    /**
     * Cashier performance calculates avg_trx correctly for multiple transactions.
     */
    public function test_cashier_performance_avg_trx_is_calculated(): void
    {
        $cashier = User::factory()->create(['name' => 'Rajin', 'role' => 'karyawan', 'outlet_id' => $this->outlet->id]);

        $this->createPaidOrder([['product_id' => $this->product1->id, 'qty' => 1, 'price' => 10000]], null, 'cash', $cashier->id);
        $this->createPaidOrder([['product_id' => $this->product1->id, 'qty' => 1, 'price' => 20000]], null, 'cash', $cashier->id);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $cashierPerf = $response->json('cashier_performance');

        $this->assertCount(1, $cashierPerf);
        $this->assertEquals('Rajin', $cashierPerf[0]['name']);
        $this->assertEquals(2, $cashierPerf[0]['transactions']);
        $this->assertEquals(30000, $cashierPerf[0]['revenue']);
        $this->assertEquals(15000, $cashierPerf[0]['avg_trx']);  // (10000 + 20000) / 2
    }

    /**
     * Cashier performance returns empty array when no transactions.
     */
    public function test_cashier_performance_empty_when_no_transactions(): void
    {
        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $this->assertEquals([], $response->json('cashier_performance'));
    }

    /**
     * Cashier performance response structure has correct keys.
     */
    public function test_cashier_performance_response_structure(): void
    {
        $cashier = User::factory()->create(['name' => 'Test Kasir', 'role' => 'karyawan', 'outlet_id' => $this->outlet->id]);
        $this->createPaidOrder([['product_id' => $this->product1->id, 'qty' => 1]], null, 'cash', $cashier->id);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'cashier_performance' => [
                '*' => [
                    'name',
                    'outlet_name',
                    'transactions',
                    'revenue',
                    'avg_trx',
                ],
            ],
        ]);
    }

    /**
     * Cashier performance shows 'Kasir Terhapus' when cashier is deleted.
     */
    public function test_cashier_performance_shows_kasir_terhapus_when_cashier_deleted(): void
    {
        $cashier = User::factory()->create(['name' => 'Akan Dihapus', 'role' => 'karyawan', 'outlet_id' => $this->outlet->id]);
        $this->createPaidOrder([['product_id' => $this->product1->id, 'qty' => 1, 'price' => 25000]], null, 'cash', $cashier->id);

        // Delete the cashier user but keep the history transaction
        $cashier->delete();

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $cashierPerf = $response->json('cashier_performance');

        $this->assertCount(1, $cashierPerf);
        $this->assertEquals('Kasir Terhapus', $cashierPerf[0]['name']);
        $this->assertEquals(25000, $cashierPerf[0]['revenue']);
    }

    /**
     * Cashier performance respects outlet isolation.
     */
    public function test_cashier_performance_respects_outlet_isolation(): void
    {
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $otherOutlet = Outlet::factory()->create(['owner_id' => $otherOwner->id]);
        $otherCashier = User::factory()->create(['name' => 'Other Kasir', 'role' => 'karyawan', 'outlet_id' => $otherOutlet->id]);

        $this->createOtherOutletOrder(['cashier_id' => $otherCashier->id]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);
        $response->assertStatus(200);
        $this->assertEquals([], $response->json('cashier_performance'));
    }

    // =========================================================================
    // TABLE PERFORMANCE (report index)
    // =========================================================================

    /**
     * Table performance shows orders, revenue, and avg_check per table.
     */
    public function test_table_performance_returns_orders_revenue_and_avg_check(): void
    {
        $tableA = Table::factory()->create(['outlet_id' => $this->outlet->id, 'name' => 'Meja A']);
        $tableB = Table::factory()->create(['outlet_id' => $this->outlet->id, 'name' => 'Meja B']);

        $this->createPaidOrder([['product_id' => $this->product1->id, 'qty' => 2, 'price' => 25000]], null, 'cash', null, $tableA->id);
        $this->createPaidOrder([['product_id' => $this->product2->id, 'qty' => 3, 'price' => 15000]], null, 'cash', null, $tableB->id);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $tablePerf = $response->json('table_performance');

        $this->assertCount(2, $tablePerf);

        $mejaA = collect($tablePerf)->firstWhere('name', 'Meja A');
        $this->assertNotNull($mejaA);
        $this->assertEquals(1, $mejaA['orders']);
        $this->assertEquals(50000, $mejaA['revenue']);
        $this->assertEquals(50000, $mejaA['avg_check']);

        $mejaB = collect($tablePerf)->firstWhere('name', 'Meja B');
        $this->assertNotNull($mejaB);
        $this->assertEquals(1, $mejaB['orders']);
        $this->assertEquals(45000, $mejaB['revenue']);
        $this->assertEquals(45000, $mejaB['avg_check']);
    }

    /**
     * Table performance is sorted by revenue descending.
     */
    public function test_table_performance_sorted_by_revenue_descending(): void
    {
        $tableLow = Table::factory()->create(['outlet_id' => $this->outlet->id, 'name' => 'Meja Low']);
        $tableHigh = Table::factory()->create(['outlet_id' => $this->outlet->id, 'name' => 'Meja High']);

        $this->createPaidOrder([['product_id' => $this->product1->id, 'qty' => 1, 'price' => 5000]], null, 'cash', null, $tableLow->id);
        $this->createPaidOrder([['product_id' => $this->product2->id, 'qty' => 1, 'price' => 100000]], null, 'cash', null, $tableHigh->id);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $tablePerf = $response->json('table_performance');

        $this->assertEquals('Meja High', $tablePerf[0]['name']);
        $this->assertEquals('Meja Low', $tablePerf[1]['name']);
    }

    /**
     * Table performance avg_check is calculated as revenue / orders_count.
     */
    public function test_table_performance_avg_check_is_calculated(): void
    {
        $table = Table::factory()->create(['outlet_id' => $this->outlet->id, 'name' => 'Meja Sering']);

        $this->createPaidOrder([['product_id' => $this->product1->id, 'qty' => 1, 'price' => 10000]], null, 'cash', null, $table->id);
        $this->createPaidOrder([['product_id' => $this->product1->id, 'qty' => 1, 'price' => 20000]], null, 'cash', null, $table->id);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $tablePerf = $response->json('table_performance');

        $meja = collect($tablePerf)->firstWhere('name', 'Meja Sering');
        $this->assertNotNull($meja);
        $this->assertEquals(2, $meja['orders']);
        $this->assertEquals(30000, $meja['revenue']);
        $this->assertEquals(15000, $meja['avg_check']);  // 30000 / 2
    }

    /**
     * Orders without a table show as 'Takeaway'.
     */
    public function test_table_performance_shows_takeaway_for_orders_without_table(): void
    {
        // Create order with null table_id via direct DB
        $paidAt = Carbon::now()->subDays(1);
        $invoiceNum = 'INV-TAKEAWAY-001';

        $order = Order::factory()->create([
            'outlet_id' => $this->outlet->id,
            'table_id' => null,
            'user_id' => $this->owner->id,
            'status' => 'paid',
            'invoice_number' => $invoiceNum,
            'subtotal_price' => 50000,
            'total_price' => 50000,
            'payment_method' => 'cash',
            'created_at' => $paidAt,
            'updated_at' => $paidAt,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id, 'product_id' => $this->product1->id,
            'qty' => 2, 'price' => 25000, 'total_price' => 50000,
        ]);
        $payment = Payment::factory()->create([
            'order_id' => $order->id, 'amount_paid' => 50000, 'change_amount' => 0,
            'method' => 'cash', 'paid_at' => $paidAt, 'paid_by' => $this->owner->id,
        ]);
        HistoryTransaction::factory()->create([
            'outlet_id' => $this->outlet->id, 'order_id' => $order->id,
            'payment_id' => $payment->id, 'status' => 'paid',
            'invoice_number' => $invoiceNum,
            'subtotal_price' => 50000, 'total_price' => 50000, 'paid_amount' => 50000,
            'payment_method' => 'cash', 'paid_at' => $paidAt,
        ]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $tablePerf = $response->json('table_performance');

        $takeaway = collect($tablePerf)->firstWhere('name', 'Takeaway');
        $this->assertNotNull($takeaway);
        $this->assertEquals(1, $takeaway['orders']);
        $this->assertEquals(50000, $takeaway['revenue']);
    }

    /**
     * Table performance returns empty array when no transactions.
     */
    public function test_table_performance_empty_when_no_transactions(): void
    {
        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $this->assertEquals([], $response->json('table_performance'));
    }

    /**
     * Table performance response structure has correct keys.
     */
    public function test_table_performance_response_structure(): void
    {
        $this->createPaidOrder([['product_id' => $this->product1->id, 'qty' => 1]]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'table_performance' => [
                '*' => [
                    'name',
                    'orders',
                    'revenue',
                    'avg_check',
                ],
            ],
        ]);
    }

    /**
     * Table performance respects outlet isolation.
     */
    public function test_table_performance_respects_outlet_isolation(): void
    {
        $this->createOtherOutletOrder();

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);
        $response->assertStatus(200);
        $this->assertEquals([], $response->json('table_performance'));
    }

    // =========================================================================
    // STATION PERFORMANCE (report index)
    // =========================================================================

    /**
     * Station performance returns items_prepared, orders, and revenue by station.
     */
    public function test_station_performance_returns_items_orders_and_revenue_by_station(): void
    {
        $dapur = Station::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Dapur Utama']);
        $bar = Station::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Bar Minuman']);

        $this->createPaidOrder([
            ['product_id' => $this->product1->id, 'qty' => 3, 'price' => 25000, 'station_id' => $dapur->id],
            ['product_id' => $this->product2->id, 'qty' => 2, 'price' => 15000, 'station_id' => $bar->id],
        ]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $stationPerf = $response->json('station_performance');

        $this->assertCount(2, $stationPerf);

        $dapurData = collect($stationPerf)->firstWhere('name', 'Dapur Utama');
        $this->assertNotNull($dapurData);
        $this->assertEquals(3, $dapurData['items_prepared']);
        $this->assertEquals(1, $dapurData['orders']);
        $this->assertEquals(75000, $dapurData['revenue']);  // 3 × 25000

        $barData = collect($stationPerf)->firstWhere('name', 'Bar Minuman');
        $this->assertNotNull($barData);
        $this->assertEquals(2, $barData['items_prepared']);
        $this->assertEquals(1, $barData['orders']);
        $this->assertEquals(30000, $barData['revenue']);  // 2 × 15000
    }

    /**
     * Station performance is sorted by items_prepared descending.
     */
    public function test_station_performance_sorted_by_items_prepared_descending(): void
    {
        $dapur = Station::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Dapur Utama']);
        $bar = Station::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Bar Minuman']);

        $this->createPaidOrder([
            ['product_id' => $this->product1->id, 'qty' => 10, 'price' => 10000, 'station_id' => $dapur->id],
        ]);
        $this->createPaidOrder([
            ['product_id' => $this->product2->id, 'qty' => 3, 'price' => 10000, 'station_id' => $bar->id],
        ]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $stationPerf = $response->json('station_performance');

        $this->assertCount(2, $stationPerf);
        $this->assertEquals('Dapur Utama', $stationPerf[0]['name']);
        $this->assertEquals(10, $stationPerf[0]['items_prepared']);
        $this->assertEquals('Bar Minuman', $stationPerf[1]['name']);
        $this->assertEquals(3, $stationPerf[1]['items_prepared']);
    }

    /**
     * Items without station show as 'Tanpa Station'.
     */
    public function test_station_performance_shows_tanpa_station_for_items_without_station(): void
    {
        $this->createPaidOrder([
            ['product_id' => $this->product1->id, 'qty' => 5, 'price' => 25000],
        ]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $stationPerf = $response->json('station_performance');

        $this->assertCount(1, $stationPerf);
        $this->assertEquals('Tanpa Station', $stationPerf[0]['name']);
        $this->assertEquals(5, $stationPerf[0]['items_prepared']);
        $this->assertEquals(1, $stationPerf[0]['orders']);
        $this->assertEquals(125000, $stationPerf[0]['revenue']);
    }

    /**
     * Station performance aggregates multiple orders for the same station.
     */
    public function test_station_performance_aggregates_multiple_orders_for_same_station(): void
    {
        $dapur = Station::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Dapur Utama']);

        // First order: 3 items for Dapur
        $this->createPaidOrder([
            ['product_id' => $this->product1->id, 'qty' => 3, 'price' => 20000, 'station_id' => $dapur->id],
        ]);
        // Second order: 2 items for Dapur
        $this->createPaidOrder([
            ['product_id' => $this->product2->id, 'qty' => 2, 'price' => 15000, 'station_id' => $dapur->id],
        ]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $stationPerf = $response->json('station_performance');

        $dapurData = collect($stationPerf)->firstWhere('name', 'Dapur Utama');
        $this->assertNotNull($dapurData);
        $this->assertEquals(5, $dapurData['items_prepared']);  // 3 + 2
        $this->assertEquals(2, $dapurData['orders']);          // 2 distinct orders
        $this->assertEquals(90000, $dapurData['revenue']);     // 60000 + 30000
    }

    /**
     * Station performance returns empty array when no transactions.
     */
    public function test_station_performance_empty_when_no_transactions(): void
    {
        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $this->assertEquals([], $response->json('station_performance'));
    }

    /**
     * Station performance response structure has correct keys.
     */
    public function test_station_performance_response_structure(): void
    {
        $dapur = Station::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Dapur Utama']);
        $this->createPaidOrder([
            ['product_id' => $this->product1->id, 'qty' => 1, 'station_id' => $dapur->id],
        ]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'station_performance' => [
                '*' => [
                    'name',
                    'items_prepared',
                    'orders',
                    'revenue',
                ],
            ],
        ]);
    }

    /**
     * Station performance respects outlet isolation.
     */
    public function test_station_performance_respects_outlet_isolation(): void
    {
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $otherOutlet = Outlet::factory()->create(['owner_id' => $otherOwner->id]);
        $otherStation = Station::factory()->create(['owner_id' => $otherOwner->id, 'name' => 'Other Station']);
        $otherProduct = Product::factory()->create(['category_id' => $this->category->id, 'owner_id' => $otherOwner->id]);
        $otherOutlet->products()->attach($otherProduct->id, ['price' => 50000, 'stock' => 10, 'is_active' => true]);

        $this->createOtherOutletOrder(['product_id' => $otherProduct->id, 'station_id' => $otherStation->id]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);
        $response->assertStatus(200);
        $this->assertEquals([], $response->json('station_performance'));
    }

    // =========================================================================
    // SHIFT SUMMARY (report index)
    // =========================================================================

    /**
     * Shift summary returns avg_shift_revenue, total_shift_revenue, total_shifts,
     * avg_variance, and total_variance from closed shifts.
     */
    public function test_shift_summary_returns_calculated_values(): void
    {
        $endedAt = Carbon::now()->subDays(1)->setTime(17, 0, 0);

        ShiftKaryawan::factory()->create([
            'outlet_id' => $this->outlet->id,
            'user_id' => $this->owner->id,
            'opening_balance' => 100000,
            'closing_balance_system' => 300000,
            'difference' => 5000,
            'started_at' => Carbon::now()->subDays(1)->setTime(8, 0, 0),
            'ended_at' => $endedAt,
            'status' => 'closed',
        ]);

        ShiftKaryawan::factory()->create([
            'outlet_id' => $this->outlet->id,
            'user_id' => $this->owner->id,
            'opening_balance' => 200000,
            'closing_balance_system' => 500000,
            'difference' => -3000,
            'started_at' => Carbon::now()->subDays(1)->setTime(10, 0, 0),
            'ended_at' => Carbon::now()->subDays(1)->setTime(22, 0, 0),
            'status' => 'closed',
        ]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $shiftSummary = $response->json('shift_summary');

        // Shift 1: 300000 - 100000 = 200000, Shift 2: 500000 - 200000 = 300000
        // total = 500000, avg = 250000
        $this->assertEquals(250000, $shiftSummary['avg_shift_revenue']);
        $this->assertEquals(500000, $shiftSummary['total_shift_revenue']);
        $this->assertEquals(2, $shiftSummary['total_shifts']);
        // avg_variance = (5000 + (-3000)) / 2 = 1000
        $this->assertEquals(1000, $shiftSummary['avg_variance']);
        // total_variance = 5000 + (-3000) = 2000
        $this->assertEquals(2000, $shiftSummary['total_variance']);
    }

    /**
     * Active shifts (where ended_at is null) are excluded from the summary.
     */
    public function test_shift_summary_excludes_active_shifts(): void
    {
        // Active shift — should NOT be counted
        ShiftKaryawan::factory()->create([
            'outlet_id' => $this->outlet->id,
            'user_id' => $this->owner->id,
            'opening_balance' => 100000,
            'closing_balance_system' => 500000,
            'difference' => 0,
            'started_at' => Carbon::now()->subDays(1)->setTime(8, 0, 0),
            'ended_at' => null,
            'status' => 'active',
        ]);

        // Closed shift — should be counted
        ShiftKaryawan::factory()->create([
            'outlet_id' => $this->outlet->id,
            'user_id' => $this->owner->id,
            'opening_balance' => 200000,
            'closing_balance_system' => 500000,
            'difference' => 10000,
            'started_at' => Carbon::now()->subDays(1)->setTime(10, 0, 0),
            'ended_at' => Carbon::now()->subDays(1)->setTime(22, 0, 0),
            'status' => 'closed',
        ]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $shiftSummary = $response->json('shift_summary');

        // Only closed shift counted: 500000 - 200000 = 300000
        $this->assertEquals(300000, $shiftSummary['total_shift_revenue']);
        $this->assertEquals(1, $shiftSummary['total_shifts']);
        $this->assertEquals(300000, $shiftSummary['avg_shift_revenue']);  // avg = total for single shift
        $this->assertEquals(10000, $shiftSummary['total_variance']);
    }

    /**
     * Shift summary returns all zeros when no shifts exist.
     */
    public function test_shift_summary_zeros_when_no_shifts(): void
    {
        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $shiftSummary = $response->json('shift_summary');

        $this->assertEquals(0, $shiftSummary['avg_shift_revenue']);
        $this->assertEquals(0, $shiftSummary['total_shift_revenue']);
        $this->assertEquals(0, $shiftSummary['total_shifts']);
        $this->assertEquals(0, $shiftSummary['avg_variance']);
        $this->assertEquals(0, $shiftSummary['total_variance']);
    }

    /**
     * Shift summary response structure has correct keys.
     */
    public function test_shift_summary_response_structure(): void
    {
        ShiftKaryawan::factory()->create([
            'outlet_id' => $this->outlet->id,
            'user_id' => $this->owner->id,
            'opening_balance' => 100000,
            'closing_balance_system' => 200000,
            'difference' => 0,
            'started_at' => Carbon::now()->subDays(1)->setTime(8, 0, 0),
            'ended_at' => Carbon::now()->subDays(1)->setTime(17, 0, 0),
            'status' => 'closed',
        ]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'shift_summary' => [
                'avg_shift_revenue',
                'total_shift_revenue',
                'total_shifts',
                'avg_variance',
                'total_variance',
            ],
        ]);
    }

    /**
     * Shift summary respects outlet isolation.
     */
    public function test_shift_summary_respects_outlet_isolation(): void
    {
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $otherOutlet = Outlet::factory()->create(['owner_id' => $otherOwner->id]);
        $otherCashier = User::factory()->create(['role' => 'karyawan', 'outlet_id' => $otherOutlet->id]);

        // Create closed shift for other outlet
        ShiftKaryawan::factory()->create([
            'outlet_id' => $otherOutlet->id,
            'user_id' => $otherCashier->id,
            'opening_balance' => 100000,
            'closing_balance_system' => 500000,
            'difference' => 5000,
            'started_at' => Carbon::now()->subDays(1)->setTime(8, 0, 0),
            'ended_at' => Carbon::now()->subDays(1)->setTime(17, 0, 0),
            'status' => 'closed',
        ]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $shiftSummary = $response->json('shift_summary');

        $this->assertEquals(0, $shiftSummary['total_shifts']);
        $this->assertEquals(0, $shiftSummary['total_shift_revenue']);
    }

    /**
     * Shift summary only includes shifts that ended within the date range.
     */
    public function test_shift_summary_filters_by_date_range(): void
    {
        // Shift ended 60 days ago — outside default date range
        ShiftKaryawan::factory()->create([
            'outlet_id' => $this->outlet->id,
            'user_id' => $this->owner->id,
            'opening_balance' => 100000,
            'closing_balance_system' => 500000,
            'difference' => 5000,
            'started_at' => Carbon::now()->subDays(61)->setTime(8, 0, 0),
            'ended_at' => Carbon::now()->subDays(60)->setTime(17, 0, 0),
            'status' => 'closed',
        ]);

        // Shift ended yesterday — within default date range (start of month)
        ShiftKaryawan::factory()->create([
            'outlet_id' => $this->outlet->id,
            'user_id' => $this->owner->id,
            'opening_balance' => 200000,
            'closing_balance_system' => 400000,
            'difference' => 2000,
            'started_at' => Carbon::now()->subDays(1)->setTime(8, 0, 0),
            'ended_at' => Carbon::now()->subDays(1)->setTime(17, 0, 0),
            'status' => 'closed',
        ]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $shiftSummary = $response->json('shift_summary');

        // Only the yesterday shift counted: 400000 - 200000 = 200000
        $this->assertEquals(1, $shiftSummary['total_shifts']);
        $this->assertEquals(200000, $shiftSummary['total_shift_revenue']);
        $this->assertEquals(200000, $shiftSummary['avg_shift_revenue']);
        $this->assertEquals(2000, $shiftSummary['total_variance']);
    }

    /**
     * Single closed shift: avg and total should be equal.
     */
    public function test_shift_summary_single_shift(): void
    {
        ShiftKaryawan::factory()->create([
            'outlet_id' => $this->outlet->id,
            'user_id' => $this->owner->id,
            'opening_balance' => 150000,
            'closing_balance_system' => 350000,
            'difference' => 10000,
            'started_at' => Carbon::now()->subDays(1)->setTime(8, 0, 0),
            'ended_at' => Carbon::now()->subDays(1)->setTime(17, 0, 0),
            'status' => 'closed',
        ]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $shiftSummary = $response->json('shift_summary');

        // Revenue: 350000 - 150000 = 200000
        $this->assertEquals(200000, $shiftSummary['total_shift_revenue']);
        $this->assertEquals(200000, $shiftSummary['avg_shift_revenue']);
        $this->assertEquals(1, $shiftSummary['total_shifts']);
        $this->assertEquals(10000, $shiftSummary['total_variance']);
        $this->assertEquals(10000, $shiftSummary['avg_variance']);
    }

    // =========================================================================
    // REVENUE CHART (report index)
    // =========================================================================

    /**
     * Revenue chart returns entries with date, revenue, and transactions.
     */
    public function test_revenue_chart_returns_date_revenue_transactions(): void
    {
        $this->createOrderOnDate('2026-07-07', [['product_id' => $this->product1->id, 'qty' => 2, 'price' => 25000]]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?start_date=2026-07-01&end_date=2026-07-31&outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $chart = $response->json('revenue_chart');

        $this->assertCount(1, $chart);
        $this->assertEquals('2026-07-07', $chart[0]['date']);
        $this->assertEquals(50000, $chart[0]['revenue']);
        $this->assertEquals(1, $chart[0]['transactions']);
    }

    /**
     * Revenue chart aggregates multiple orders on the same day.
     */
    public function test_revenue_chart_aggregates_multiple_orders_same_day(): void
    {
        $this->createOrderOnDate('2026-07-07', [['product_id' => $this->product1->id, 'qty' => 2, 'price' => 25000]], '10:00');
        $this->createOrderOnDate('2026-07-07', [['product_id' => $this->product2->id, 'qty' => 3, 'price' => 10000]], '15:00');

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?start_date=2026-07-01&end_date=2026-07-31&outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $chart = $response->json('revenue_chart');

        $this->assertCount(1, $chart);
        $this->assertEquals('2026-07-07', $chart[0]['date']);
        $this->assertEquals(80000, $chart[0]['revenue']);  // 50000 + 30000
        $this->assertEquals(2, $chart[0]['transactions']);
    }

    /**
     * Revenue chart has separate entries for different days.
     */
    public function test_revenue_chart_separate_entries_for_different_days(): void
    {
        $this->createOrderOnDate('2026-07-07', [['product_id' => $this->product1->id, 'qty' => 1, 'price' => 50000]]);
        $this->createOrderOnDate('2026-07-09', [['product_id' => $this->product2->id, 'qty' => 1, 'price' => 30000]]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?start_date=2026-07-01&end_date=2026-07-31&outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $chart = $response->json('revenue_chart');

        $this->assertCount(2, $chart);
        $this->assertEquals('2026-07-07', $chart[0]['date']);
        $this->assertEquals(50000, $chart[0]['revenue']);
        $this->assertEquals('2026-07-09', $chart[1]['date']);
        $this->assertEquals(30000, $chart[1]['revenue']);
    }

    /**
     * Revenue chart is empty when no transactions exist.
     */
    public function test_revenue_chart_empty_when_no_transactions(): void
    {
        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $this->assertEquals([], $response->json('revenue_chart'));
    }

    /**
     * Revenue chart respects outlet isolation.
     */
    public function test_revenue_chart_respects_outlet_isolation(): void
    {
        $this->createOtherOutletOrder();

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);
        $response->assertStatus(200);
        $this->assertEquals([], $response->json('revenue_chart'));
    }

    // =========================================================================
    // SALES REPORT (report index)
    // =========================================================================

    /**
     * Sales report returns date, transactions, gross, discount, tax, net.
     */
    public function test_sales_report_returns_full_structure(): void
    {
        $this->createOrderOnDate('2026-07-07', [['product_id' => $this->product1->id, 'qty' => 2, 'price' => 25000]]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?start_date=2026-07-01&end_date=2026-07-31&outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $salesReport = $response->json('sales_report');

        $this->assertCount(1, $salesReport);
        $this->assertEquals('2026-07-07', $salesReport[0]['date']);
        $this->assertEquals(1, $salesReport[0]['transactions']);
        $this->assertArrayHasKey('gross', $salesReport[0]);
        $this->assertArrayHasKey('discount', $salesReport[0]);
        $this->assertArrayHasKey('tax', $salesReport[0]);
        $this->assertArrayHasKey('net', $salesReport[0]);
    }

    /**
     * Sales report gross matches sum of order_items total_price.
     */
    public function test_sales_report_gross_from_order_items(): void
    {
        // Create manually with explicit discount=0 and tax=0 (factory defaults are random)
        $paidAt = Carbon::parse('2026-07-07');
        $invoiceNum = 'INV-GROSS-001';

        $order = Order::factory()->create([
            'outlet_id' => $this->outlet->id, 'table_id' => $this->table->id,
            'user_id' => $this->owner->id, 'status' => 'paid',
            'invoice_number' => $invoiceNum,
            'subtotal_price' => 75000, 'total_price' => 75000,
            'payment_method' => 'cash',
            'created_at' => $paidAt, 'updated_at' => $paidAt,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id, 'product_id' => $this->product1->id,
            'qty' => 3, 'price' => 25000, 'total_price' => 75000,
        ]);
        $payment = Payment::factory()->create([
            'order_id' => $order->id, 'amount_paid' => 75000, 'change_amount' => 0,
            'method' => 'cash', 'paid_at' => $paidAt, 'paid_by' => $this->owner->id,
        ]);
        HistoryTransaction::factory()->create([
            'outlet_id' => $this->outlet->id, 'order_id' => $order->id,
            'payment_id' => $payment->id, 'status' => 'paid',
            'invoice_number' => $invoiceNum,
            'subtotal_price' => 75000, 'discount_amount' => 0, 'tax_amount' => 0,
            'total_price' => 75000, 'paid_amount' => 75000,
            'payment_method' => 'cash', 'paid_at' => $paidAt,
        ]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?start_date=2026-07-01&end_date=2026-07-31&outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $salesReport = $response->json('sales_report');

        // gross = sum of order_items.total_price = 3 × 25000 = 75000
        // net = sum of history_transactions.total_price = 75000
        $this->assertEquals(75000, $salesReport[0]['gross']);
        $this->assertEquals(75000, $salesReport[0]['net']);
        $this->assertEquals(0, $salesReport[0]['discount']);
        $this->assertEquals(0, $salesReport[0]['tax']);
    }

    /**
     * Sales report includes discount and tax when set on history_transactions.
     */
    public function test_sales_report_includes_discount_and_tax(): void
    {
        $paidAt = Carbon::parse('2026-07-07');
        $invoiceNum = 'INV-DISC-001';

        $order = Order::factory()->create([
            'outlet_id' => $this->outlet->id,
            'table_id' => $this->table->id,
            'user_id' => $this->owner->id,
            'status' => 'paid',
            'invoice_number' => $invoiceNum,
            'subtotal_price' => 50000,
            'discount_amount' => 5000,
            'tax_amount' => 2000,
            'total_price' => 47000,
            'payment_method' => 'cash',
            'created_at' => $paidAt,
            'updated_at' => $paidAt,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id, 'product_id' => $this->product1->id,
            'qty' => 2, 'price' => 25000, 'total_price' => 50000,
        ]);
        $payment = Payment::factory()->create([
            'order_id' => $order->id, 'amount_paid' => 47000, 'change_amount' => 0,
            'method' => 'cash', 'paid_at' => $paidAt, 'paid_by' => $this->owner->id,
        ]);
        HistoryTransaction::factory()->create([
            'outlet_id' => $this->outlet->id, 'order_id' => $order->id,
            'payment_id' => $payment->id, 'status' => 'paid',
            'invoice_number' => $invoiceNum,
            'subtotal_price' => 50000, 'discount_amount' => 5000, 'tax_amount' => 2000,
            'total_price' => 47000, 'paid_amount' => 47000,
            'payment_method' => 'cash', 'paid_at' => $paidAt,
        ]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?start_date=2026-07-01&end_date=2026-07-31&outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $salesReport = $response->json('sales_report');

        $this->assertCount(1, $salesReport);
        $this->assertEquals(50000, $salesReport[0]['gross']);
        $this->assertEquals(5000, $salesReport[0]['discount']);
        $this->assertEquals(2000, $salesReport[0]['tax']);
        $this->assertEquals(47000, $salesReport[0]['net']);
    }

    /**
     * Sales report aggregates multiple transactions on the same day.
     */
    public function test_sales_report_aggregates_multiple_same_day(): void
    {
        $this->createOrderOnDate('2026-07-07', [['product_id' => $this->product1->id, 'qty' => 2, 'price' => 25000]], '10:00');
        $this->createOrderOnDate('2026-07-07', [['product_id' => $this->product2->id, 'qty' => 1, 'price' => 30000]], '15:00');

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?start_date=2026-07-01&end_date=2026-07-31&outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $salesReport = $response->json('sales_report');

        $this->assertCount(1, $salesReport);
        $this->assertEquals('2026-07-07', $salesReport[0]['date']);
        $this->assertEquals(2, $salesReport[0]['transactions']);
        $this->assertEquals(80000, $salesReport[0]['gross']);  // 50000 + 30000
        $this->assertEquals(80000, $salesReport[0]['net']);
    }

    /**
     * Sales report is empty when no transactions exist.
     */
    public function test_sales_report_empty_when_no_transactions(): void
    {
        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $this->assertEquals([], $response->json('sales_report'));
    }

    /**
     * Sales report respects outlet isolation.
     */
    public function test_sales_report_respects_outlet_isolation(): void
    {
        $this->createOtherOutletOrder();

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);
        $response->assertStatus(200);
        $this->assertEquals([], $response->json('sales_report'));
    }

    // =========================================================================
    // TOP PRODUCTS (report index - not public endpoint)
    // =========================================================================

    /**
     * Top products returns name, category, sold, revenue, avg_price.
     */
    public function test_top_products_returns_full_product_data(): void
    {
        $this->createPaidOrder([
            ['product_id' => $this->product1->id, 'qty' => 3, 'price' => 25000],
            ['product_id' => $this->product2->id, 'qty' => 2, 'price' => 15000],
        ]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $topProducts = $response->json('top_products');

        $this->assertCount(2, $topProducts);

        $prodA = collect($topProducts)->firstWhere('name', 'Product A');
        $this->assertNotNull($prodA);
        $this->assertEquals($this->category->name, $prodA['category']);
        $this->assertEquals(3, $prodA['sold']);
        $this->assertEquals(75000, $prodA['revenue']);
        $this->assertEquals(25000, $prodA['avg_price']);

        $prodB = collect($topProducts)->firstWhere('name', 'Product B');
        $this->assertNotNull($prodB);
        $this->assertEquals($this->category->name, $prodB['category']);
        $this->assertEquals(2, $prodB['sold']);
        $this->assertEquals(30000, $prodB['revenue']);
        $this->assertEquals(15000, $prodB['avg_price']);
    }

    /**
     * Top products sorted by sold quantity descending.
     */
    public function test_top_products_sorted_by_sold_descending(): void
    {
        $this->createPaidOrder([['product_id' => $this->product1->id, 'qty' => 10, 'price' => 10000]]);
        $this->createPaidOrder([['product_id' => $this->product2->id, 'qty' => 2, 'price' => 10000]]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $topProducts = $response->json('top_products');

        $this->assertEquals('Product A', $topProducts[0]['name']);
        $this->assertEquals(10, $topProducts[0]['sold']);
        $this->assertEquals('Product B', $topProducts[1]['name']);
        $this->assertEquals(2, $topProducts[1]['sold']);
    }

    /**
     * Top products returns category name associated with product.
     */
    public function test_top_products_returns_category_name(): void
    {
        $this->createPaidOrder([['product_id' => $this->product1->id, 'qty' => 3]]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $topProducts = $response->json('top_products');

        $this->assertCount(1, $topProducts);
        $this->assertEquals($this->category->name, $topProducts[0]['category']);
    }

    /**
     * Top products only counts paid transactions.
     */
    public function test_top_products_only_paid_transactions(): void
    {
        $this->createPaidOrder([['product_id' => $this->product1->id, 'qty' => 5]]);

        // Pending order — should NOT be counted
        $order = Order::factory()->create([
            'outlet_id' => $this->outlet->id, 'table_id' => $this->table->id,
            'user_id' => $this->owner->id, 'status' => 'pending',
            'total_price' => 250000, 'payment_method' => 'cash',
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id, 'product_id' => $this->product2->id,
            'qty' => 10, 'price' => 25000, 'total_price' => 250000,
        ]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $topProducts = $response->json('top_products');

        // Only Product A should appear, Product B is from pending order
        $this->assertCount(1, $topProducts);
        $this->assertEquals('Product A', $topProducts[0]['name']);
    }

    /**
     * Top products is empty when no transactions exist.
     */
    public function test_top_products_empty_when_no_transactions(): void
    {
        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $this->assertEquals([], $response->json('top_products'));
    }

    /**
     * Top products respects outlet isolation.
     */
    public function test_top_products_respects_outlet_isolation(): void
    {
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $otherOutlet = Outlet::factory()->create(['owner_id' => $otherOwner->id]);
        $otherProduct = Product::factory()->create(['category_id' => $this->category->id, 'owner_id' => $otherOwner->id]);
        $otherOutlet->products()->attach($otherProduct->id, ['price' => 50000, 'stock' => 10, 'is_active' => true]);

        $this->createOtherOutletOrder(['product_id' => $otherProduct->id]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);
        $response->assertStatus(200);
        $this->assertEquals([], $response->json('top_products'));
    }

    // =========================================================================
    // PERIOD INFO (report index)
    // =========================================================================

    /**
     * Period info returns current period (startOfMonth to today) by default.
     */
    public function test_period_info_returns_default_period(): void
    {
        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $period = $response->json('period_info');

        $today = Carbon::today();
        $startOfMonth = Carbon::today()->startOfMonth()->toDateString();

        $this->assertEquals($startOfMonth, $period['current']['start']);
        $this->assertEquals($today->toDateString(), $period['current']['end']);
    }

    /**
     * Period info returns custom date range when start_date and end_date provided.
     */
    public function test_period_info_custom_date_range(): void
    {
        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?start_date=2026-07-05&end_date=2026-07-09&outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $period = $response->json('period_info');

        $this->assertEquals('2026-07-05', $period['current']['start']);
        $this->assertEquals('2026-07-09', $period['current']['end']);
    }

    /**
     * Period info returns correct Y-m-d date format for both periods.
     */
    public function test_period_info_date_format(): void
    {
        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?start_date=2026-07-05&end_date=2026-07-09&outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $period = $response->json('period_info');

        // Assert Y-m-d format (10 chars: XXXX-XX-XX)
        $this->assertEquals(10, strlen($period['current']['start']));
        $this->assertEquals(10, strlen($period['current']['end']));
        $this->assertEquals(10, strlen($period['previous']['start']));
        $this->assertEquals(10, strlen($period['previous']['end']));

        // Assert ISO date pattern
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $period['current']['start']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $period['current']['end']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $period['previous']['start']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $period['previous']['end']);

        // Assert current period matches request
        $this->assertEquals('2026-07-05', $period['current']['start']);
        $this->assertEquals('2026-07-09', $period['current']['end']);
    }

    // =========================================================================
    // SUMMARY CALCULATIONS (report index)
    // =========================================================================

    /**
     * Summary avg_order is revenue divided by transactions.
     */
    public function test_summary_calculates_avg_order(): void
    {
        $this->createOrderOnDate('2026-07-07', [['product_id' => $this->product1->id, 'qty' => 2, 'price' => 25000]]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?start_date=2026-07-01&end_date=2026-07-31&outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $summary = $response->json('summary');

        $this->assertEquals(50000, $summary['revenue']);
        $this->assertEquals(1, $summary['transactions']);
        $this->assertEquals(50000, $summary['avg_order']);  // 50000 / 1
    }

    /**
     * Revenue growth is calculated when previous period has data.
     */
    public function test_summary_growth_null_when_no_previous_data(): void
    {
        $this->createOrderOnDate('2026-07-07', [['product_id' => $this->product1->id, 'qty' => 1, 'price' => 50000]]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?start_date=2026-07-05&end_date=2026-07-09&outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $summary = $response->json('summary');

        $this->assertEquals(50000, $summary['revenue']);
        $this->assertNull($summary['revenue_growth']);
        $this->assertNull($summary['trx_growth']);
    }

    /**
     * Summary avg_check is the average of total_price across all transactions.
     */
    public function test_summary_avg_check_reflects_average_transaction(): void
    {
        // 2 transactions: 20000 + 40000 → avg = 30000
        $this->createOrderOnDate('2026-07-07', [['product_id' => $this->product1->id, 'qty' => 1, 'price' => 20000]]);
        $this->createOrderOnDate('2026-07-08', [['product_id' => $this->product2->id, 'qty' => 1, 'price' => 40000]]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?start_date=2026-07-01&end_date=2026-07-31&outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $summary = $response->json('summary');

        $this->assertEquals(60000, $summary['revenue']);
        $this->assertEquals(2, $summary['transactions']);
        $this->assertEquals(30000, $summary['avg_order']);   // 60000 / 2
        $this->assertEquals(30000, $summary['avg_check']);    // AVG of total_price
    }

    /**
     * Summary unique_customers counts distinct customer names from orders.
     */
    public function test_summary_unique_customers_count(): void
    {
        $paidAt = Carbon::parse('2026-07-07');

        // Order 1: customer 'Budi'
        $order1 = Order::factory()->create([
            'outlet_id' => $this->outlet->id, 'table_id' => $this->table->id,
            'user_id' => $this->owner->id, 'status' => 'paid',
            'customer_name' => 'Budi',
            'total_price' => 50000, 'payment_method' => 'cash',
            'created_at' => $paidAt, 'updated_at' => $paidAt,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order1->id, 'product_id' => $this->product1->id,
            'qty' => 2, 'price' => 25000, 'total_price' => 50000,
        ]);
        $payment1 = Payment::factory()->create([
            'order_id' => $order1->id, 'amount_paid' => 50000, 'change_amount' => 0,
            'method' => 'cash', 'paid_at' => $paidAt, 'paid_by' => $this->owner->id,
        ]);
        HistoryTransaction::factory()->create([
            'outlet_id' => $this->outlet->id, 'order_id' => $order1->id,
            'payment_id' => $payment1->id, 'status' => 'paid',
            'invoice_number' => 'INV-CUST-001',
            'subtotal_price' => 50000, 'total_price' => 50000, 'paid_amount' => 50000,
            'payment_method' => 'cash', 'paid_at' => $paidAt,
        ]);

        // Order 2: customer 'Ani'
        $order2 = Order::factory()->create([
            'outlet_id' => $this->outlet->id, 'table_id' => $this->table->id,
            'user_id' => $this->owner->id, 'status' => 'paid',
            'customer_name' => 'Ani',
            'total_price' => 30000, 'payment_method' => 'cash',
            'created_at' => $paidAt, 'updated_at' => $paidAt,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order2->id, 'product_id' => $this->product2->id,
            'qty' => 1, 'price' => 30000, 'total_price' => 30000,
        ]);
        $payment2 = Payment::factory()->create([
            'order_id' => $order2->id, 'amount_paid' => 30000, 'change_amount' => 0,
            'method' => 'cash', 'paid_at' => $paidAt, 'paid_by' => $this->owner->id,
        ]);
        HistoryTransaction::factory()->create([
            'outlet_id' => $this->outlet->id, 'order_id' => $order2->id,
            'payment_id' => $payment2->id, 'status' => 'paid',
            'invoice_number' => 'INV-CUST-002',
            'subtotal_price' => 30000, 'total_price' => 30000, 'paid_amount' => 30000,
            'payment_method' => 'cash', 'paid_at' => $paidAt,
        ]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports?start_date=2026-07-01&end_date=2026-07-31&outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $summary = $response->json('summary');

        $this->assertEquals(2, $summary['unique_customers']);
    }
}
