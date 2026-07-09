<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Category;
use App\Models\Table;
use App\Models\HistoryTransaction;
use App\Models\Payment;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class HistoryTransactionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $karyawan;
    private Outlet $outlet;
    private Category $category;
    private Product $product;
    private Table $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'manager']);
        $this->karyawan = User::factory()->create([
            'role' => 'karyawan',
            'outlet_id' => null,
        ]);
        $this->outlet = Outlet::factory()->create(['owner_id' => $this->owner->id]);
        $this->karyawan->outlet_id = $this->outlet->id;
        $this->karyawan->save();

        $this->category = Category::factory()->create(['owner_id' => $this->owner->id]);
        $this->product = Product::factory()->create([
            'category_id' => $this->category->id,
            'owner_id' => $this->owner->id,
            'cost_price' => 50000,
        ]);
        $this->outlet->products()->attach($this->product->id, [
            'price' => 50000,
            'stock' => 100,
            'is_active' => true,
        ]);
        $this->table = Table::factory()->create([
            'outlet_id' => $this->outlet->id,
            'name' => 'Meja Test',
            'status' => 'available',
        ]);
    }

    // =========================================================================
    // CREATION — HistoryTransaction dibuat saat order lunas
    // =========================================================================

    #[Test]
    public function history_created_when_cash_order_paid()
    {
        $this->actingAs($this->owner);

        $service = app(OrderService::class);

        $result = $service->createCheckoutOrder([
            'outlet_id' => $this->outlet->id,
            'table_id' => $this->table->id,
            'customer_name' => 'Test Customer',
            'payment_method' => 'cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'qty' => 2,
                    'price' => 50000,
                ]
            ],
            'amount_paid' => 100000,
        ]);

        $order = $result['order'];

        // HistoryTransaction should exist
        $history = HistoryTransaction::where('order_id', $order->id)->first();

        $this->assertNotNull($history, 'HistoryTransaction should be created for paid order');
        $this->assertEquals($order->id, $history->order_id);
        $this->assertEquals('paid', $history->status);
    }

    #[Test]
    public function history_contains_correct_data_snapshot()
    {
        $this->actingAs($this->owner);

        $service = app(OrderService::class);

        $result = $service->createCheckoutOrder([
            'outlet_id' => $this->outlet->id,
            'table_id' => $this->table->id,
            'customer_name' => 'Test Customer',
            'payment_method' => 'cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'qty' => 2,
                    'price' => 50000,
                ]
            ],
            'amount_paid' => 100000,
        ]);

        $order = $result['order'];
        $history = HistoryTransaction::where('order_id', $order->id)->first();

        $this->assertEquals($order->outlet_id, $history->outlet_id);
        $this->assertEquals($order->invoice_number, $history->invoice_number);
        $this->assertEquals($order->customer_name, $history->customer_name);
        $this->assertEquals($order->subtotal_price, $history->subtotal_price);
        $this->assertEquals($order->discount_amount, $history->discount_amount);
        $this->assertEquals($order->tax_amount, $history->tax_amount);
        $this->assertEquals($order->total_price, $history->total_price);
    }

    #[Test]
    public function history_created_with_discount_and_tax_included()
    {
        $discount = \App\Models\Discount::factory()->create([
            'owner_id' => $this->owner->id,
            'scope' => 'global',
            'type' => 'nominal',
            'value' => 10000,
            'min_purchase' => 0,
            'start_date' => now()->subDays(1)->format('Y-m-d'),
            'end_date' => now()->addDays(10)->format('Y-m-d'),
            'is_active' => true,
        ]);

        $tax = \App\Models\Tax::factory()->create([
            'outlet_id' => $this->outlet->id,
            'type' => 'percentage',
            'rate' => 11.0,
            'active' => true,
        ]);

        $this->actingAs($this->owner);

        $service = app(OrderService::class);

        $result = $service->createCheckoutOrder([
            'outlet_id' => $this->outlet->id,
            'table_id' => $this->table->id,
            'customer_name' => 'Test Customer',
            'payment_method' => 'cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'qty' => 2,
                    'price' => 50000,
                ]
            ],
            'discount_id' => $discount->id,
            'tax_id' => $tax->id,
            'amount_paid' => 100000,
        ]);

        $order = $result['order'];
        $history = HistoryTransaction::where('order_id', $order->id)->first();

        $this->assertEquals($order->discount_amount, $history->discount_amount);
        $this->assertEquals($order->tax_amount, $history->tax_amount);
        $this->assertGreaterThan(0, $history->discount_amount, 'Discount should be reflected in history');
        $this->assertGreaterThan(0, $history->tax_amount, 'Tax should be reflected in history');
    }

    // =========================================================================
    // UPDATE — HistoryTransaction diupdate saat order di-void
    // =========================================================================

    #[Test]
    public function history_updated_when_order_synced_again()
    {
        $this->actingAs($this->owner);

        $service = app(OrderService::class);

        $result = $service->createCheckoutOrder([
            'outlet_id' => $this->outlet->id,
            'table_id' => $this->table->id,
            'customer_name' => 'Test Customer',
            'payment_method' => 'cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'qty' => 2,
                    'price' => 50000,
                ]
            ],
            'amount_paid' => 100000,
        ]);

        $order = $result['order'];
        $historyId = HistoryTransaction::where('order_id', $order->id)->first()->id;

        // Sync again (simulates void items or other update)
        $service->syncHistoryTransaction($order->fresh());

        // Should still be the same record (updateOrCreate by order_id)
        $historyCount = HistoryTransaction::where('order_id', $order->id)->count();
        $this->assertEquals(1, $historyCount, 'Should only have one history record per order');
    }

    // =========================================================================
    // AUTHORIZATION — Akses ke HistoryTransaction
    // =========================================================================

    #[Test]
    public function karyawan_can_view_own_outlet_history()
    {
        $this->actingAs($this->owner);

        $service = app(OrderService::class);

        $result = $service->createCheckoutOrder([
            'outlet_id' => $this->outlet->id,
            'table_id' => $this->table->id,
            'customer_name' => 'Test Customer',
            'payment_method' => 'cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'qty' => 1,
                    'price' => 50000,
                ]
            ],
            'amount_paid' => 50000,
        ]);

        $history = HistoryTransaction::where('order_id', $result['order']->id)->first();

        // Karyawan dari outlet yang sama should be able to view
        $this->actingAs($this->karyawan);
        $response = $this->getJson("/api/v1/history-transactions/{$history->id}");

        $response->assertStatus(200);
    }

    #[Test]
    public function karyawan_cannot_edit_history()
    {
        $this->actingAs($this->owner);

        $service = app(OrderService::class);

        $result = $service->createCheckoutOrder([
            'outlet_id' => $this->outlet->id,
            'table_id' => $this->table->id,
            'customer_name' => 'Test Customer',
            'payment_method' => 'cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'qty' => 1,
                    'price' => 50000,
                ]
            ],
            'amount_paid' => 50000,
        ]);

        $history = HistoryTransaction::where('order_id', $result['order']->id)->first();

        // Karyawan should get 403 when trying to update
        $this->actingAs($this->karyawan);
        $response = $this->putJson("/api/v1/history-transactions/{$history->id}", [
            'subtotal_price' => 99999,
        ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function manager_can_edit_history()
    {
        $this->actingAs($this->owner);

        $service = app(OrderService::class);

        $result = $service->createCheckoutOrder([
            'outlet_id' => $this->outlet->id,
            'table_id' => $this->table->id,
            'customer_name' => 'Test Customer',
            'payment_method' => 'cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'qty' => 1,
                    'price' => 50000,
                ]
            ],
            'amount_paid' => 50000,
        ]);

        $history = HistoryTransaction::where('order_id', $result['order']->id)->first();
        $originalSubtotal = $history->subtotal_price;

        $response = $this->putJson("/api/v1/history-transactions/{$history->id}", [
            'subtotal_price' => 99999,
            'total_price' => $history->total_price,
        ]);

        $response->assertStatus(200);
        $history->refresh();
        $this->assertEquals(99999, $history->subtotal_price, 'Manager should be able to update history');
    }

    // =========================================================================
    // HISTORY ACCESS CONTROL — Cross-tenant
    // =========================================================================

    #[Test]
    public function karyawan_cannot_view_other_outlet_history()
    {
        // Create another outlet with different karyawan
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $otherOutlet = Outlet::factory()->create(['owner_id' => $otherOwner->id]);
        $otherProduct = Product::factory()->create([
            'category_id' => $this->category->id,
            'owner_id' => $otherOwner->id,
            'cost_price' => 30000,
        ]);
        $otherOutlet->products()->attach($otherProduct->id, [
            'price' => 30000,
            'stock' => 50,
            'is_active' => true,
        ]);
        $otherTable = Table::factory()->create([
            'outlet_id' => $otherOutlet->id,
            'name' => 'Meja Lain',
            'status' => 'available',
        ]);

        $this->actingAs($otherOwner);

        $service = app(OrderService::class);
        $result = $service->createCheckoutOrder([
            'outlet_id' => $otherOutlet->id,
            'table_id' => $otherTable->id,
            'customer_name' => 'Other Customer',
            'payment_method' => 'cash',
            'items' => [
                [
                    'product_id' => $otherProduct->id,
                    'qty' => 1,
                    'price' => 30000,
                ]
            ],
            'amount_paid' => 30000,
        ]);

        $otherHistory = HistoryTransaction::where('order_id', $result['order']->id)->first();

        // Karyawan from original outlet should get 403
        $this->actingAs($this->karyawan);
        $response = $this->getJson("/api/v1/history-transactions/{$otherHistory->id}");

        $response->assertStatus(403);
    }

    #[Test]
    public function manager_cannot_edit_other_owner_history()
    {
        // Create another owner
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $otherOutlet = Outlet::factory()->create(['owner_id' => $otherOwner->id]);
        $otherProduct = Product::factory()->create([
            'category_id' => Category::factory()->create(['owner_id' => $otherOwner->id])->id,
            'owner_id' => $otherOwner->id,
            'cost_price' => 30000,
        ]);
        $otherOutlet->products()->attach($otherProduct->id, [
            'price' => 30000,
            'stock' => 50,
            'is_active' => true,
        ]);
        $otherTable = Table::factory()->create([
            'outlet_id' => $otherOutlet->id,
            'name' => 'Meja Lain',
            'status' => 'available',
        ]);

        $this->actingAs($otherOwner);
        $service = app(OrderService::class);
        $result = $service->createCheckoutOrder([
            'outlet_id' => $otherOutlet->id,
            'table_id' => $otherTable->id,
            'customer_name' => 'Other Customer',
            'payment_method' => 'cash',
            'items' => [
                [
                    'product_id' => $otherProduct->id,
                    'qty' => 1,
                    'price' => 30000,
                ]
            ],
            'amount_paid' => 30000,
        ]);

        $otherHistory = HistoryTransaction::where('order_id', $result['order']->id)->first();

        // Original owner (different tenant) should get 403
        $this->actingAs($this->owner);
        $response = $this->putJson("/api/v1/history-transactions/{$otherHistory->id}", [
            'subtotal_price' => 99999,
            'total_price' => 99999,
        ]);

        $response->assertStatus(403);
    }
}
