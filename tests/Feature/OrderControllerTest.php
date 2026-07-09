<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Category;
use App\Models\Table;
use App\Models\Discount;
use App\Models\Tax;

use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class OrderControllerTest extends TestCase
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
    // LIST ORDERS (index)
    // =========================================================================

    #[Test]
    public function manager_can_list_orders()
    {
        Order::factory()->count(3)->create([
            'outlet_id' => $this->outlet->id,
            'user_id' => $this->owner->id,
            'table_id' => $this->table->id,
            'status' => 'pending',
        ]);

        $this->actingAs($this->owner);
        $response = $this->getJson('/api/v1/orders');

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data');
    }

    #[Test]
    public function karyawan_can_list_own_outlet_orders()
    {
        Order::factory()->count(2)->create([
            'outlet_id' => $this->outlet->id,
            'user_id' => $this->owner->id,
            'table_id' => $this->table->id,
            'status' => 'pending',
        ]);

        $this->actingAs($this->karyawan);
        $response = $this->getJson('/api/v1/orders');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }

    #[Test]
    public function orders_can_be_filtered_by_status()
    {
        Order::factory()->create([
            'outlet_id' => $this->outlet->id,
            'user_id' => $this->owner->id,
            'table_id' => $this->table->id,
            'status' => 'pending',
        ]);
        Order::factory()->create([
            'outlet_id' => $this->outlet->id,
            'user_id' => $this->owner->id,
            'table_id' => $this->table->id,
            'status' => 'paid',
        ]);

        $this->actingAs($this->owner);

        $pending = $this->getJson('/api/v1/orders?status=pending');
        $pending->assertStatus(200);
        $this->assertCount(1, $pending->json('data'));

        $paid = $this->getJson('/api/v1/orders?status=paid');
        $paid->assertStatus(200);
        $this->assertCount(1, $paid->json('data'));
    }

    #[Test]
    public function orders_can_be_searched_by_invoice()
    {
        Order::factory()->create([
            'outlet_id' => $this->outlet->id,
            'user_id' => $this->owner->id,
            'table_id' => $this->table->id,
            'invoice_number' => 'INV-001',
            'status' => 'pending',
        ]);
        Order::factory()->create([
            'outlet_id' => $this->outlet->id,
            'user_id' => $this->owner->id,
            'table_id' => $this->table->id,
            'invoice_number' => 'INV-002',
            'status' => 'pending',
        ]);

        $this->actingAs($this->owner);
        $response = $this->getJson('/api/v1/orders?search=INV-001');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    // =========================================================================
    // SHOW ORDER
    // =========================================================================

    #[Test]
    public function manager_can_view_order_detail()
    {
        $order = Order::factory()->create([
            'outlet_id' => $this->outlet->id,
            'user_id' => $this->owner->id,
            'table_id' => $this->table->id,
            'status' => 'pending',
            'subtotal_price' => 50000,
            'total_price' => 50000,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'qty' => 1,
            'price' => 50000,
            'total_price' => 50000,
        ]);

        $this->actingAs($this->owner);
        $response = $this->getJson("/api/v1/orders/{$order->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'id' => $order->id,
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function karyawan_cannot_view_other_outlet_order()
    {
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $otherOutlet = Outlet::factory()->create(['owner_id' => $otherOwner->id]);
        $otherTable = Table::factory()->create(['outlet_id' => $otherOutlet->id]);

        $order = Order::factory()->create([
            'outlet_id' => $otherOutlet->id,
            'user_id' => $otherOwner->id,
            'table_id' => $otherTable->id,
        ]);

        $this->actingAs($this->karyawan);
        $response = $this->getJson("/api/v1/orders/{$order->id}");

        $response->assertStatus(403);
    }

    // =========================================================================
    // CHECKOUT ORDER (Cash flow)
    // =========================================================================

    #[Test]
    public function cash_checkout_creates_order_and_payment()
    {
        $this->actingAs($this->owner);

        $response = $this->postJson('/api/v1/orders/checkout', [
            'outlet_id' => $this->outlet->id,
            'table_id' => $this->table->id,
            'customer_name' => 'Test Customer',
            'payment_method' => 'cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'qty' => 2,
                    'price' => 50000,
                ],
            ],
            'amount_paid' => 100000,
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'message' => 'Checkout dan pembayaran berhasil',
        ]);

        $this->assertDatabaseHas('orders', [
            'status' => 'paid',
            'subtotal_price' => 100000,
            'total_price' => 100000,
        ]);

        $this->assertDatabaseHas('payments', [
            'amount_paid' => 100000,
            'change_amount' => 0,
        ]);

        // Table should be available after payment
        $this->assertDatabaseHas('tables', [
            'id' => $this->table->id,
            'status' => 'available',
        ]);
    }

    #[Test]
    public function checkout_fails_when_amount_less_than_total()
    {
        $this->actingAs($this->owner);

        $response = $this->postJson('/api/v1/orders/checkout', [
            'outlet_id' => $this->outlet->id,
            'table_id' => $this->table->id,
            'customer_name' => 'Test',
            'payment_method' => 'cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'qty' => 2,
                    'price' => 50000,
                ],
            ],
            'amount_paid' => 50000, // kurang dari total 100000
        ]);

        $response->assertStatus(500);
    }

    #[Test]
    public function checkout_with_discount_and_tax()
    {
        $discount = Discount::factory()->create([
            'owner_id' => $this->owner->id,
            'scope' => 'global',
            'type' => 'nominal',
            'value' => 10000,
            'min_purchase' => 0,
            'start_date' => Carbon::now()->subDays(1)->format('Y-m-d'),
            'end_date' => Carbon::now()->addDays(10)->format('Y-m-d'),
            'is_active' => true,
        ]);

        $tax = Tax::factory()->create([
            'outlet_id' => $this->outlet->id,
            'type' => 'percentage',
            'rate' => 11.0,
            'active' => true,
        ]);

        $this->actingAs($this->owner);

        $response = $this->postJson('/api/v1/orders/checkout', [
            'outlet_id' => $this->outlet->id,
            'table_id' => $this->table->id,
            'customer_name' => 'Test',
            'payment_method' => 'cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'qty' => 2,
                    'price' => 50000,
                ],
            ],
            'discount_id' => $discount->id,
            'tax_id' => $tax->id,
            'amount_paid' => 120000,
        ]);

        $response->assertStatus(201);

        $order = $response->json('order');

        $this->assertNotNull($order['discount_id']);
        $this->assertNotNull($order['tax_id']);
        $this->assertGreaterThan(0, $order['discount_amount']);
        $this->assertGreaterThan(0, $order['tax_amount']);
    }

    // =========================================================================
    // CANCEL ITEM
    // =========================================================================

    #[Test]
    public function manager_can_cancel_item_partially()
    {
        $this->actingAs($this->owner);

        $order = Order::factory()->create([
            'outlet_id' => $this->outlet->id,
            'user_id' => $this->owner->id,
            'table_id' => $this->table->id,
            'status' => 'pending',
            'subtotal_price' => 100000,
            'total_price' => 100000,
        ]);

        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'qty' => 4,
            'price' => 25000,
            'total_price' => 100000,
            'cancelled_qty' => 0,
        ]);

        $response = $this->postJson("/api/v1/orders/{$order->id}/items/{$item->id}/cancel", [
            'cancel_qty' => 2,
            'reason' => 'Pelanggan mengurangi pesanan',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Item cancelled');

        $item->refresh();
        $this->assertEquals(2, $item->cancelled_qty);
    }

    #[Test]
    public function cancel_item_fails_when_exceeds_remaining()
    {
        $this->actingAs($this->owner);

        $order = Order::factory()->create([
            'outlet_id' => $this->outlet->id,
            'user_id' => $this->owner->id,
            'table_id' => $this->table->id,
            'status' => 'pending',
        ]);

        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'qty' => 2,
            'price' => 25000,
            'total_price' => 50000,
        ]);

        $response = $this->postJson("/api/v1/orders/{$order->id}/items/{$item->id}/cancel", [
            'cancel_qty' => 5, // lebih dari 2
            'reason' => 'Test',
        ]);

        $response->assertStatus(400);
    }


}
