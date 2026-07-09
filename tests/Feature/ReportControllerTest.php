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

    /**
     * Test the report index endpoint returns a successful response with correct structure.
     *
     * This test validates all database-agnostic SQL expressions (dateExpr, hourExpr, etc.) work.
     */
    public function test_report_index_returns_valid_response(): void
    {
        // Create a developer user
        $user = User::factory()->create([
            'role' => 'developer',
            'outlet_id' => null,
        ]);

        // Create an outlet owned by the user
        $outlet = Outlet::factory()->create([
            'owner_id' => $user->id,
        ]);

        // Create a category and product
        $category = Category::factory()->create(['owner_id' => $user->id]);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'owner_id' => $user->id,
        ]);

        // Link product to outlet
        $outlet->products()->attach($product->id, [
            'price' => 25000,
            'stock' => 100,
            'is_active' => true,
        ]);

        // Create a table
        $table = Table::factory()->create([
            'outlet_id' => $outlet->id,
            'name' => 'Meja 1',
            'status' => 'available',
        ]);

        // Create a paid order with history transaction
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

        // Create order item
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'qty' => 1,
            'price' => 25000,
            'total_price' => 25000,
        ]);

        // Create payment
        $payment = Payment::factory()->create([
            'order_id' => $order->id,
            'amount_paid' => 30000,
            'change_amount' => 2250,
            'method' => 'cash',
            'paid_at' => Carbon::now()->subDays(5),
            'paid_by' => $user->id,
        ]);

        // Create history transaction
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

        // Act: Call the report endpoint as the developer user
        $response = $this->actingAs($user)->getJson('/api/v1/reports');

        // Assert: 200 OK
        $response->assertStatus(200);

        // Assert: Response has expected structure
        $response->assertJsonStructure([
            'summary' => [
                'revenue',
                'transactions',
                'avg_order',
                'items_sold',
                'total_discount',
                'total_tax',
                'revenue_growth',
                'trx_growth',
                'unique_customers',
                'avg_check',
            ],
            'revenue_chart',
            'sales_report',
            'top_products',
            'cashier_performance',
            'payment_methods',
            'category_performance',
            'hourly_sales',
            'table_performance',
            'station_performance',
            'shift_summary',
            'period_info',
        ]);

        // Assert: Summary values are correct (isolated test data)
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

        // Assert: Hourly sales exists and has data for all 24 hours
        $this->assertCount(24, $response->json('hourly_sales'));
    }

    /**
     * Test that the dateExpr() helper works correctly by checking the SQL driver name.
     */
    public function test_date_expr_works_with_sqlite(): void
    {
        $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();
        $this->assertEquals('sqlite', $driver);

        // With SQLite, dateExpr should return 'DATE(column)'
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
}
