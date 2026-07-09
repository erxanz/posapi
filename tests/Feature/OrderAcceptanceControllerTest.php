<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderAcceptance;
use App\Models\Product;
use App\Models\Category;
use App\Models\Table;
use App\Models\Payment;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class OrderAcceptanceControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $karyawan;
    private Outlet $outlet;
    private Product $product;
    private Table $table;
    private Order $paidOrder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'manager']);
        $this->outlet = Outlet::factory()->create(['owner_id' => $this->owner->id]);
        $category = Category::factory()->create(['owner_id' => $this->owner->id]);
        $this->product = Product::factory()->create([
            'category_id' => $category->id,
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
            'name' => 'Meja 1',
            'status' => 'available',
        ]);
        $this->karyawan = User::factory()->create([
            'role' => 'karyawan',
            'outlet_id' => $this->outlet->id,
        ]);

        // Create a paid cash order ready for acceptance
        $this->paidOrder = Order::factory()->create([
            'outlet_id' => $this->outlet->id,
            'table_id' => $this->table->id,
            'user_id' => $this->owner->id,
            'status' => 'paid',
            'payment_method' => 'cash',
            'subtotal_price' => 100000,
            'total_price' => 100000,
            'customer_name' => 'Test Customer',
        ]);
        OrderItem::factory()->create([
            'order_id' => $this->paidOrder->id,
            'product_id' => $this->product->id,
            'qty' => 2,
            'price' => 50000,
            'total_price' => 100000,
        ]);
        Payment::factory()->create([
            'order_id' => $this->paidOrder->id,
            'amount_paid' => 100000,
            'change_amount' => 0,
            'method' => 'cash',
        ]);
    }

    // =========================================================================
    // ACCEPT — Cashier scope
    // =========================================================================

    #[Test]
    public function manager_can_accept_order()
    {
        $this->actingAs($this->owner);

        $response = $this->postJson("/api/v1/orders/{$this->paidOrder->id}/accept", [
            'scope' => 'cashier',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Order accepted');
        $response->assertJsonPath('acceptance.scope', 'cashier');

        $this->assertDatabaseHas('order_acceptances', [
            'order_id' => $this->paidOrder->id,
            'scope' => 'cashier',
        ]);
    }

    // =========================================================================
    // ACCEPT — Kitchen scope
    // =========================================================================

    #[Test]
    public function manager_can_accept_order_with_kitchen_scope()
    {
        $this->actingAs($this->owner);

        $response = $this->postJson("/api/v1/orders/{$this->paidOrder->id}/accept", [
            'scope' => 'kitchen',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('acceptance.scope', 'kitchen');

        $this->assertDatabaseHas('order_acceptances', [
            'order_id' => $this->paidOrder->id,
            'scope' => 'kitchen',
        ]);
    }

    // =========================================================================
    // ACCEPT — Karyawan can accept in own outlet
    // =========================================================================

    #[Test]
    public function karyawan_can_accept_order_in_own_outlet()
    {
        $this->actingAs($this->karyawan);

        $response = $this->postJson("/api/v1/orders/{$this->paidOrder->id}/accept");

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Order accepted');
    }

    // =========================================================================
    // ACCEPT — Unauthenticated
    // =========================================================================

    #[Test]
    public function unauthenticated_user_cannot_accept_order()
    {
        $response = $this->postJson("/api/v1/orders/{$this->paidOrder->id}/accept");

        $response->assertStatus(401);
    }

    // =========================================================================
    // ACCEPT — Cancelled order
    // =========================================================================

    #[Test]
    public function cannot_accept_cancelled_order()
    {
        $cancelledOrder = Order::factory()->create([
            'outlet_id' => $this->outlet->id,
            'table_id' => $this->table->id,
            'user_id' => $this->owner->id,
            'status' => 'cancelled',
            'payment_method' => 'cash',
            'subtotal_price' => 50000,
            'total_price' => 50000,
        ]);

        $this->actingAs($this->owner);
        $response = $this->postJson("/api/v1/orders/{$cancelledOrder->id}/accept");

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Order sudah dibatalkan.');
    }

    // =========================================================================
    // ACCEPT — Unpaid QRIS order
    // =========================================================================

    #[Test]
    public function cannot_accept_unpaid_qris_order()
    {
        $qrisOrder = Order::factory()->create([
            'outlet_id' => $this->outlet->id,
            'table_id' => $this->table->id,
            'user_id' => null,
            'status' => 'pending',
            'payment_method' => 'qris',
            'subtotal_price' => 50000,
            'total_price' => 50000,
        ]);

        $this->actingAs($this->owner);
        $response = $this->postJson("/api/v1/orders/{$qrisOrder->id}/accept");

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Gagal! Pelanggan belum menyelesaikan pembayaran.');
    }

    // =========================================================================
    // ACCEPT — Unpaid cash order
    // =========================================================================

    #[Test]
    public function cannot_accept_unpaid_cash_order()
    {
        $pendingOrder = Order::factory()->create([
            'outlet_id' => $this->outlet->id,
            'table_id' => $this->table->id,
            'user_id' => $this->owner->id,
            'status' => 'pending',
            'payment_method' => 'cash',
            'subtotal_price' => 50000,
            'total_price' => 50000,
        ]);

        $this->actingAs($this->owner);
        $response = $this->postJson("/api/v1/orders/{$pendingOrder->id}/accept");

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Order cash harus paid sebelum diterima.');
    }

    // =========================================================================
    // ACCEPT — Cross-tenant rejection
    // =========================================================================

    #[Test]
    public function karyawan_cannot_accept_other_outlet_order()
    {
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $otherOutlet = Outlet::factory()->create(['owner_id' => $otherOwner->id]);
        $otherTable = Table::factory()->create(['outlet_id' => $otherOutlet->id]);
        $otherOrder = Order::factory()->create([
            'outlet_id' => $otherOutlet->id,
            'table_id' => $otherTable->id,
            'user_id' => $otherOwner->id,
            'status' => 'paid',
            'payment_method' => 'cash',
            'subtotal_price' => 50000,
            'total_price' => 50000,
        ]);

        $this->actingAs($this->karyawan);
        $response = $this->postJson("/api/v1/orders/{$otherOrder->id}/accept");

        $response->assertStatus(403);
    }

    // =========================================================================
    // ACCEPT — Idempotent (double accept)
    // =========================================================================

    #[Test]
    public function accepting_twice_updates_existing_record()
    {
        $this->actingAs($this->owner);

        // First accept
        $this->postJson("/api/v1/orders/{$this->paidOrder->id}/accept", [
            'scope' => 'cashier',
        ])->assertStatus(200);

        // Second accept - should update the existing record, not create a new one
        $response = $this->postJson("/api/v1/orders/{$this->paidOrder->id}/accept", [
            'scope' => 'cashier',
        ]);

        $response->assertStatus(200);

        $count = OrderAcceptance::where('order_id', $this->paidOrder->id)->count();
        $this->assertEquals(1, $count, 'Should only have one acceptance record per order-scope');
    }

    // =========================================================================
    // ACCEPT — Invalid scope
    // =========================================================================

    #[Test]
    public function accept_rejects_invalid_scope()
    {
        $this->actingAs($this->owner);
        $response = $this->postJson("/api/v1/orders/{$this->paidOrder->id}/accept", [
            'scope' => 'invalid',
        ]);
        $response->assertStatus(422);
    }

    // =========================================================================
    // ACCEPT — Creates acceptance with correct data
    // =========================================================================

    #[Test]
    public function accept_creates_acceptance_with_accepted_by()
    {
        $this->actingAs($this->owner);

        $response = $this->postJson("/api/v1/orders/{$this->paidOrder->id}/accept", [
            'scope' => 'cashier',
        ]);

        $response->assertStatus(200);

        $acceptance = OrderAcceptance::where('order_id', $this->paidOrder->id)->first();
        $this->assertNotNull($acceptance);
        $this->assertEquals($this->owner->id, $acceptance->accepted_by);
        $this->assertEquals('cashier', $acceptance->scope);
        $this->assertNotNull($acceptance->accepted_at);
    }

    // =========================================================================
    // ACCEPT — Includes order in response
    // =========================================================================

    #[Test]
    public function accept_response_includes_order()
    {
        $this->actingAs($this->owner);

        $response = $this->postJson("/api/v1/orders/{$this->paidOrder->id}/accept");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'message',
            'order' => ['id', 'items', 'table', 'status'],
            'acceptance',
        ]);
        $response->assertJsonPath('order.id', $this->paidOrder->id);
    }
}
