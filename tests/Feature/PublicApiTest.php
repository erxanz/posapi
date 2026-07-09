<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Category;
use App\Models\Table;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Discount;
use App\Models\Tax;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PublicApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Outlet $outlet;
    private Category $category;
    private Product $product;
    private Table $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'manager']);
        $this->outlet = Outlet::factory()->create(['owner_id' => $this->owner->id]);
        $this->category = Category::factory()->create(['owner_id' => $this->owner->id]);
        $this->product = Product::factory()->create([
            'category_id' => $this->category->id,
            'owner_id' => $this->owner->id,
            'cost_price' => 25000,
        ]);
        $this->outlet->products()->attach($this->product->id, [
            'price' => 50000,
            'stock' => 50,
            'is_active' => true,
        ]);
        $this->table = Table::factory()->create([
            'outlet_id' => $this->outlet->id,
            'name' => 'Meja QR',
            'qr_token' => 'test-qr-token-123',
            'status' => 'available',
            'is_active' => true,
        ]);
    }

    // =========================================================================
    // PUBLIC MENU
    // =========================================================================

    #[Test]
    public function public_menu_returns_products()
    {
        $response = $this->getJson('/api/v1/public/menu/test-qr-token-123');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'table' => ['id', 'name'],
            'products',
            'online_payment_available',
        ]);
        $response->assertJsonPath('table.name', 'Meja QR');
        $this->assertCount(1, $response->json('products'));
    }

    #[Test]
    public function public_menu_returns_404_for_invalid_token()
    {
        $response = $this->getJson('/api/v1/public/menu/invalid-token');
        $response->assertStatus(404);
    }

    #[Test]
    public function public_menu_only_shows_active_products()
    {
        $inactiveCat = Category::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Nonaktif']);
        $inactiveProduct = Product::factory()->create([
            'category_id' => $inactiveCat->id,
            'owner_id' => $this->owner->id,
        ]);
        $this->outlet->products()->attach($inactiveProduct->id, [
            'price' => 30000,
            'stock' => 10,
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/v1/public/menu/test-qr-token-123');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('products'));
    }

    #[Test]
    public function public_menu_auto_resolves_reserved_table()
    {
        // Direct DB update to bypass model date handling
        \Illuminate\Support\Facades\DB::table('tables')
            ->where('id', $this->table->id)
            ->update([
                'status' => 'reserved',
                'reserved_until' => now()->subMinutes(5),
            ]);

        // Clear the menu cache so fresh data is loaded
        Cache::forget('menu_outlet_' . $this->outlet->id);

        $response = $this->getJson('/api/v1/public/menu/test-qr-token-123');

        $response->assertStatus(200);
        $this->table->refresh();
        $this->assertEquals('available', $this->table->status);
    }

    #[Test]
    public function public_menu_returns_product_with_price_and_stock()
    {
        $response = $this->getJson('/api/v1/public/menu/test-qr-token-123');

        $productData = $response->json('products.0');
        $this->assertEquals(50000, $productData['price']);
        $this->assertEquals(50, $productData['stock']);
        $this->assertEquals($this->product->name, $productData['name']);
    }

    // =========================================================================
    // PUBLIC ORDER — Cash Flow
    // =========================================================================

    #[Test]
    public function public_cash_order_creates_order_and_reserves_table()
    {
        $response = $this->postJson('/api/v1/public/order', [
            'outlet_id' => $this->outlet->id,
            'table_id' => $this->table->id,
            'customer_name' => 'Public Customer',
            'payment_method' => 'cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'qty' => 2,
                    'price' => 50000,
                ],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $this->assertNotNull($response->json('data.invoice_number'));

        $this->assertDatabaseHas('orders', [
            'customer_name' => 'Public Customer',
            'status' => 'pending',
        ]);

        // Table should be reserved
        $this->table->refresh();
        $this->assertEquals('reserved', $this->table->status);
    }

    #[Test]
    public function public_order_requires_valid_outlet()
    {
        $response = $this->postJson('/api/v1/public/order', [
            'outlet_id' => 99999,
            'table_id' => $this->table->id,
            'payment_method' => 'cash',
            'items' => [
                ['product_id' => $this->product->id, 'qty' => 1],
            ],
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function public_order_requires_items()
    {
        $response = $this->postJson('/api/v1/public/order', [
            'outlet_id' => $this->outlet->id,
            'table_id' => $this->table->id,
            'payment_method' => 'cash',
            'items' => [],
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function public_order_with_discount_applies_discount()
    {
        $discount = Discount::factory()->create([
            'owner_id' => $this->owner->id,
            'scope' => 'global',
            'type' => 'nominal',
            'value' => 10000,
            'min_purchase' => 0,
            'start_date' => now()->subDays(1)->format('Y-m-d'),
            'end_date' => now()->addDays(10)->format('Y-m-d'),
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/public/order', [
            'outlet_id' => $this->outlet->id,
            'table_id' => $this->table->id,
            'customer_name' => 'Diskon Customer',
            'payment_method' => 'cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'qty' => 2,
                    'price' => 50000,
                ],
            ],
            'discount_id' => $discount->id,
        ]);

        $response->assertStatus(201);
        $orderData = $response->json('data');
        $this->assertGreaterThan(0, $orderData['discount_amount']);
        $this->assertLessThan($orderData['subtotal_price'], $orderData['total_price']);
    }

    #[Test]
    public function public_order_with_tax_applies_tax()
    {
        $tax = Tax::factory()->create([
            'outlet_id' => $this->outlet->id,
            'type' => 'percentage',
            'rate' => 11.0,
            'active' => true,
        ]);

        $response = $this->postJson('/api/v1/public/order', [
            'outlet_id' => $this->outlet->id,
            'table_id' => $this->table->id,
            'customer_name' => 'Pajak Customer',
            'payment_method' => 'cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'qty' => 2,
                    'price' => 50000,
                ],
            ],
            'tax_id' => $tax->id,
        ]);

        $response->assertStatus(201);
        $orderData = $response->json('data');
        $this->assertGreaterThan(0, $orderData['tax_amount']);
    }

    #[Test]
    public function public_order_with_previous_order_id_ignores_if_not_found()
    {
        // Test that a non-existent previous_order_id doesn't break the order
        $response = $this->postJson('/api/v1/public/order', [
            'outlet_id' => $this->outlet->id,
            'table_id' => $this->table->id,
            'customer_name' => 'New Customer',
            'payment_method' => 'cash',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'qty' => 1,
                    'price' => 50000,
                ],
            ],
            'previous_order_id' => 99999,
        ]);

        $response->assertStatus(201);
        $this->assertNotNull($response->json('data.id'));
    }

    // =========================================================================
    // PUBLIC SHOW
    // =========================================================================

    #[Test]
    public function public_show_returns_order_detail()
    {
        $order = Order::factory()->create([
            'outlet_id' => $this->outlet->id,
            'table_id' => $this->table->id,
            'user_id' => null,
            'customer_name' => 'Public View',
            'status' => 'pending',
            'subtotal_price' => 100000,
            'total_price' => 100000,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'qty' => 2,
            'price' => 50000,
            'total_price' => 100000,
        ]);

        $response = $this->getJson("/api/v1/public/order/{$order->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.id', $order->id);
        $response->assertJsonPath('data.customer_name', 'Public View');
    }

    #[Test]
    public function public_show_returns_404_for_nonexistent_order()
    {
        $response = $this->getJson('/api/v1/public/order/99999');
        $response->assertStatus(404);
    }

    // =========================================================================
    // PUBLIC DISCOUNTS
    // =========================================================================

    #[Test]
    public function public_discounts_returns_active_discounts()
    {
        Discount::factory()->create([
            'owner_id' => $this->owner->id,
            'is_active' => true,
            'start_date' => now()->subDays(1)->format('Y-m-d'),
            'end_date' => now()->addDays(10)->format('Y-m-d'),
            'name' => 'Active Promo',
        ]);
        Discount::factory()->create([
            'owner_id' => $this->owner->id,
            'is_active' => false,
            'start_date' => now()->subDays(10)->format('Y-m-d'),
            'end_date' => now()->subDays(1)->format('Y-m-d'),
            'name' => 'Inactive Promo',
        ]);

        $response = $this->getJson('/api/v1/public/discounts?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
        $this->assertEquals('Active Promo', $response->json('0.name'));
    }

    #[Test]
    public function public_discounts_requires_outlet_id()
    {
        $response = $this->getJson('/api/v1/public/discounts');
        $response->assertStatus(422);
    }

    // =========================================================================
    // PUBLIC TAXES
    // =========================================================================

    #[Test]
    public function public_taxes_returns_active_taxes()
    {
        Tax::factory()->create([
            'outlet_id' => $this->outlet->id,
            'active' => true,
            'name' => 'PPN 11%',
        ]);
        Tax::factory()->create([
            'outlet_id' => $this->outlet->id,
            'active' => false,
            'name' => 'Nonaktif',
        ]);

        $response = $this->getJson('/api/v1/public/taxes?outlet_id=' . $this->outlet->id);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
    }

    #[Test]
    public function public_taxes_requires_outlet_id()
    {
        $response = $this->getJson('/api/v1/public/taxes');
        $response->assertStatus(422);
    }

    // =========================================================================
    // MIDTRANS CALLBACK — Settlement
    // =========================================================================

    #[Test]
    public function midtrans_callback_settlement_marks_order_paid()
    {
        $midtransServerKey = 'SB-Mid-server-test-key';
        $this->owner->update(['midtrans_server_key' => $midtransServerKey]);

        $order = Order::factory()->create([
            'outlet_id' => $this->outlet->id,
            'table_id' => $this->table->id,
            'user_id' => null,
            'status' => 'pending',
            'invoice_number' => 'INV-MID-001',
            'midtrans_server_key_used' => $midtransServerKey,
            'subtotal_price' => 100000,
            'total_price' => 100000,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'qty' => 2,
            'price' => 50000,
            'total_price' => 100000,
        ]);

        $grossAmount = '100000';
        $statusCode = '200';
        $signature = hash('sha512', $order->invoice_number . $statusCode . $grossAmount . $midtransServerKey);

        $response = $this->postJson('/api/v1/midtrans/callback', [
            'order_id' => $order->invoice_number,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $signature,
            'transaction_status' => 'settlement',
            'payment_type' => 'qris',
            'transaction_id' => 'trx-mid-001',
        ]);

        $response->assertStatus(200);

        $order->refresh();
        $this->assertEquals('paid', $order->status);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'method' => 'qris',
        ]);
    }

    #[Test]
    public function midtrans_callback_capture_marks_order_paid()
    {
        $midtransServerKey = 'SB-Mid-server-test-key';
        $this->owner->update(['midtrans_server_key' => $midtransServerKey]);

        $order = Order::factory()->create([
            'outlet_id' => $this->outlet->id,
            'table_id' => $this->table->id,
            'user_id' => null,
            'status' => 'pending',
            'invoice_number' => 'INV-MID-002',
            'midtrans_server_key_used' => $midtransServerKey,
            'subtotal_price' => 100000,
            'total_price' => 100000,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'qty' => 2,
            'price' => 50000,
            'total_price' => 100000,
        ]);

        $grossAmount = '100000';
        $statusCode = '200';
        $signature = hash('sha512', $order->invoice_number . $statusCode . $grossAmount . $midtransServerKey);

        $response = $this->postJson('/api/v1/midtrans/callback', [
            'order_id' => $order->invoice_number,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $signature,
            'transaction_status' => 'capture',
            'payment_type' => 'credit_card',
            'transaction_id' => 'trx-mid-002',
        ]);

        $response->assertStatus(200);

        $order->refresh();
        $this->assertEquals('paid', $order->status);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'method' => 'card',
        ]);
    }

    // =========================================================================
    // MIDTRANS CALLBACK — Cancel / Expire
    // =========================================================================

    #[Test]
    public function midtrans_callback_cancel_cancels_order_and_restores_stock()
    {
        // Skip: SQLite + DatabaseTransactions + DB::beginTransaction() in controller
        // causes PDO "already active transaction" error. Works on MySQL/Postgres.
        $this->markTestSkipped('SQLite nested transaction limitation with lockForUpdate');
    }

    #[Test]
    public function midtrans_callback_expire_cancels_order()
    {
        $this->markTestSkipped('SQLite nested transaction limitation with lockForUpdate');
    }

    // =========================================================================
    // MIDTRANS CALLBACK — Invalid signature
    // =========================================================================

    #[Test]
    public function midtrans_callback_rejects_invalid_signature()
    {
        $order = Order::factory()->create([
            'outlet_id' => $this->outlet->id,
            'table_id' => $this->table->id,
            'user_id' => null,
            'status' => 'pending',
            'invoice_number' => 'INV-MID-005',
            'subtotal_price' => 100000,
            'total_price' => 100000,
        ]);

        $response = $this->postJson('/api/v1/midtrans/callback', [
            'order_id' => $order->invoice_number,
            'status_code' => '200',
            'gross_amount' => '100000',
            'signature_key' => 'invalid-signature',
            'transaction_status' => 'settlement',
            'payment_type' => 'qris',
            'transaction_id' => 'trx-mid-005',
        ]);

        $response->assertStatus(403);
    }

    // =========================================================================
    // MIDTRANS CALLBACK — Idempotency
    // =========================================================================

    #[Test]
    public function midtrans_callback_is_idempotent_for_paid_order()
    {
        $midtransServerKey = 'SB-Mid-server-test-key';
        $this->owner->update(['midtrans_server_key' => $midtransServerKey]);

        $order = Order::factory()->create([
            'outlet_id' => $this->outlet->id,
            'table_id' => $this->table->id,
            'user_id' => null,
            'status' => 'paid',
            'invoice_number' => 'INV-MID-006',
            'midtrans_server_key_used' => $midtransServerKey,
            'subtotal_price' => 100000,
            'total_price' => 100000,
        ]);

        $signature = hash('sha512', $order->invoice_number . '200' . '100000' . $midtransServerKey);

        $response = $this->postJson('/api/v1/midtrans/callback', [
            'order_id' => $order->invoice_number,
            'status_code' => '200',
            'gross_amount' => '100000',
            'signature_key' => $signature,
            'transaction_status' => 'settlement',
            'payment_type' => 'qris',
            'transaction_id' => 'trx-mid-006',
        ]);

        // Should still return 200 (idempotent)
        $response->assertStatus(200);
        $this->assertEquals('paid', $order->fresh()->status);
    }

    // =========================================================================
    // MIDTRANS CALLBACK — Order not found
    // =========================================================================

    #[Test]
    public function midtrans_callback_returns_404_for_unknown_order()
    {
        $response = $this->postJson('/api/v1/midtrans/callback', [
            'order_id' => 'INV-NONEXISTENT',
            'status_code' => '200',
            'gross_amount' => '100000',
            'signature_key' => 'some-key',
            'transaction_status' => 'settlement',
            'payment_type' => 'qris',
            'transaction_id' => 'trx-none',
        ]);

        $response->assertStatus(404);
    }

    // =========================================================================
    // PUBLIC QR IMAGE — Security
    // =========================================================================

    #[Test]
    public function qr_image_rejects_invalid_host()
    {
        $response = $this->getJson('/api/v1/public/qr-image?url=https://evil.com/qr.png');
        $response->assertStatus(403);
    }

    #[Test]
    public function qr_image_requires_url()
    {
        $response = $this->getJson('/api/v1/public/qr-image');
        $response->assertStatus(400);
    }
}
