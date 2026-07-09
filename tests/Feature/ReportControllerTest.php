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

    private function createPaidOrder(array $items, ?Carbon $paidAt = null): void
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
            'payment_method' => 'cash',
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
            'method' => 'cash',
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
            'payment_method' => 'cash',
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
}
