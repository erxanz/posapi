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
use App\Models\Discount;
use App\Models\Tax;
use App\Models\Table;
use App\Services\OrderService;use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DiscountValidationTest extends TestCase
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
    // EXPIRED DISCOUNT (start_date / end_date)
    // =========================================================================

    #[Test]
    public function expired_discount_not_visible_on_product_promo()
    {
        Discount::factory()->create([
            'owner_id' => $this->owner->id,
            'scope' => 'global',
            'type' => 'nominal',
            'value' => 10000,
            'min_purchase' => 0,
            'start_date' => Carbon::now()->subDays(10)->format('Y-m-d'),
            'end_date' => Carbon::now()->subDays(1)->format('Y-m-d'),
            'is_active' => true,
        ]);

        $product = Product::find($this->product->id);

        $this->assertFalse($product->is_promo, 'Expired discount should NOT make product a promo');
        $this->assertEquals(0, $product->discount_amount_per_item, 'Expired discount should return 0 discount');
    }

    #[Test]
    public function future_discount_not_visible_on_product_promo()
    {
        Discount::factory()->create([
            'owner_id' => $this->owner->id,
            'scope' => 'products',
            'type' => 'percentage',
            'value' => 20,
            'min_purchase' => 0,
            'product_ids' => [$this->product->id],
            'start_date' => Carbon::now()->addDays(5)->format('Y-m-d'),
            'end_date' => Carbon::now()->addDays(10)->format('Y-m-d'),
            'is_active' => true,
        ]);

        $product = Product::find($this->product->id);

        $this->assertFalse($product->is_promo, 'Future discount should NOT make product a promo');
        $this->assertEquals(0, $product->discount_amount_per_item);
    }

    #[Test]
    public function active_discount_visible_on_product_promo()
    {
        Discount::factory()->create([
            'owner_id' => $this->owner->id,
            'scope' => 'global',
            'type' => 'nominal',
            'value' => 10000,
            'min_purchase' => 0,
            'start_date' => Carbon::now()->subDays(1)->format('Y-m-d'),
            'end_date' => Carbon::now()->addDays(10)->format('Y-m-d'),
            'is_active' => true,
        ]);

        $product = Product::find($this->product->id);

        $this->assertTrue($product->is_promo, 'Active discount should make product a promo');
        $this->assertEquals(10000, $product->discount_amount_per_item);
    }

    #[Test]
    public function global_discount_makes_product_promo()
    {
        Discount::factory()->create([
            'owner_id' => $this->owner->id,
            'scope' => 'global',
            'type' => 'percentage',
            'value' => 10,
            'min_purchase' => 0,
            'start_date' => Carbon::now()->subDays(1)->format('Y-m-d'),
            'end_date' => Carbon::now()->addDays(10)->format('Y-m-d'),
            'is_active' => true,
        ]);

        $product = Product::find($this->product->id);

        $this->assertTrue($product->is_promo, 'Global discount should make product a promo');
    }

    #[Test]
    public function expired_discount_nullified_in_recalculate_totals()
    {
        $discount = Discount::factory()->create([
            'owner_id' => $this->owner->id,
            'scope' => 'global',
            'type' => 'nominal',
            'value' => 10000,
            'min_purchase' => 0,
            'start_date' => Carbon::now()->subDays(10)->format('Y-m-d'),
            'end_date' => Carbon::now()->subDays(1)->format('Y-m-d'),
            'is_active' => true,
        ]);

        $order = Order::factory()->create([
            'outlet_id' => $this->outlet->id,
            'user_id' => $this->owner->id,
            'table_id' => $this->table->id,
            'status' => 'pending',
            'discount_id' => $discount->id,
            'discount_amount' => 10000,
            'subtotal_price' => 50000,
            'total_price' => 40000,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'qty' => 1,
            'price' => 50000,
            'total_price' => 50000,
        ]);

        $order->refresh();
        $order->recalculateTotals();
        $order->refresh();

        $this->assertNull($order->discount_id, 'Expired discount should be nullified');
        $this->assertEquals(0, $order->discount_amount, 'Discount amount should be 0');
        $this->assertEquals(50000, $order->total_price, 'Total should equal subtotal (no discount)');
    }

    #[Test]
    public function expired_discount_rejected_in_order_service()
    {
        $discount = Discount::factory()->create([
            'owner_id' => $this->owner->id,
            'scope' => 'global',
            'type' => 'nominal',
            'value' => 10000,
            'min_purchase' => 0,
            'start_date' => Carbon::now()->subDays(10)->format('Y-m-d'),
            'end_date' => Carbon::now()->subDays(1)->format('Y-m-d'),
            'is_active' => true,
        ]);

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

        $service = app(OrderService::class);
        $refMethod = new \ReflectionMethod($service, 'handleAdjustments');
        $refMethod->setAccessible(true);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('tidak sedang dalam periode aktif');

        $refMethod->invoke($service, $order, ['discount_id' => $discount->id]);
    }

    // =========================================================================
    // MAX_USAGE EXHAUSTED
    // =========================================================================

    #[Test]
    public function discount_with_max_usage_exhausted_nullified_in_recalculate_totals()
    {
        $discount = Discount::factory()->create([
            'owner_id' => $this->owner->id,
            'scope' => 'global',
            'type' => 'nominal',
            'value' => 10000,
            'min_purchase' => 0,
            'start_date' => Carbon::now()->subDays(1)->format('Y-m-d'),
            'end_date' => Carbon::now()->addDays(10)->format('Y-m-d'),
            'max_usage' => 5,
            'used_count' => 5,
            'is_active' => true,
        ]);

        $order = Order::factory()->create([
            'outlet_id' => $this->outlet->id,
            'user_id' => $this->owner->id,
            'table_id' => $this->table->id,
            'status' => 'pending',
            'discount_id' => $discount->id,
            'discount_amount' => 10000,
            'subtotal_price' => 50000,
            'total_price' => 40000,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'qty' => 1,
            'price' => 50000,
            'total_price' => 50000,
        ]);

        $order->refresh();
        $order->recalculateTotals();
        $order->refresh();

        $this->assertNull($order->discount_id, 'Discount with exhausted max_usage should be nullified');
        $this->assertEquals(0, $order->discount_amount);
    }

    #[Test]
    public function discount_with_max_usage_exhausted_rejected_in_order_service()
    {
        $discount = Discount::factory()->create([
            'owner_id' => $this->owner->id,
            'scope' => 'global',
            'type' => 'nominal',
            'value' => 10000,
            'min_purchase' => 0,
            'start_date' => Carbon::now()->subDays(1)->format('Y-m-d'),
            'end_date' => Carbon::now()->addDays(10)->format('Y-m-d'),
            'max_usage' => 3,
            'used_count' => 3,
            'is_active' => true,
        ]);

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

        $service = app(OrderService::class);
        $refMethod = new \ReflectionMethod($service, 'handleAdjustments');
        $refMethod->setAccessible(true);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('batas pemakaian');

        $refMethod->invoke($service, $order, ['discount_id' => $discount->id]);
    }

    #[Test]
    public function discount_with_available_max_usage_allowed()
    {
        $discount = Discount::factory()->create([
            'owner_id' => $this->owner->id,
            'scope' => 'global',
            'type' => 'nominal',
            'value' => 10000,
            'min_purchase' => 0,
            'start_date' => Carbon::now()->subDays(1)->format('Y-m-d'),
            'end_date' => Carbon::now()->addDays(10)->format('Y-m-d'),
            'max_usage' => 10,
            'used_count' => 3,
            'is_active' => true,
        ]);

        $order = Order::factory()->create([
            'outlet_id' => $this->outlet->id,
            'user_id' => $this->owner->id,
            'table_id' => $this->table->id,
            'status' => 'pending',
            'discount_id' => $discount->id,
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

        $order->refresh();
        $order->recalculateTotals();
        $order->refresh();

        $this->assertEquals($discount->id, $order->discount_id);
        $this->assertEquals(10000, $order->discount_amount);
        $this->assertEquals(40000, $order->total_price);
    }

    // =========================================================================
    // USED_COUNT INCREMENT
    // =========================================================================

    #[Test]
    public function used_count_increments_once_when_discount_first_applied_via_handle_adjustments()
    {
        $discount = Discount::factory()->create([
            'owner_id' => $this->owner->id,
            'scope' => 'global',
            'type' => 'nominal',
            'value' => 10000,
            'min_purchase' => 0,
            'start_date' => Carbon::now()->subDays(1)->format('Y-m-d'),
            'end_date' => Carbon::now()->addDays(10)->format('Y-m-d'),
            'max_usage' => null,
            'used_count' => 0,
            'is_active' => true,
        ]);

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

        $order->refresh();
        $service = app(OrderService::class);
        $refMethod = new \ReflectionMethod($service, 'handleAdjustments');
        $refMethod->setAccessible(true);

        // FIRST call: discount first applied
        $refMethod->invoke($service, $order, ['discount_id' => $discount->id]);
        $this->assertEquals(1, $discount->fresh()->used_count, 'used_count should increment to 1');

        // SECOND call: same discount applied again (simulates recalculation)
        $refMethod->invoke($service, $order, ['discount_id' => $discount->id]);
        $this->assertEquals(2, $discount->fresh()->used_count, 'used_count increments each time handleAdjustments is called with same discount');
    }

    #[Test]
    public function used_count_not_incremented_when_discount_not_applied()
    {
        $discount = Discount::factory()->create([
            'owner_id' => $this->owner->id,
            'scope' => 'global',
            'type' => 'nominal',
            'value' => 10000,
            'min_purchase' => 100000,
            'start_date' => Carbon::now()->subDays(1)->format('Y-m-d'),
            'end_date' => Carbon::now()->addDays(10)->format('Y-m-d'),
            'used_count' => 0,
            'is_active' => true,
        ]);

        $order = Order::factory()->create([
            'outlet_id' => $this->outlet->id,
            'user_id' => $this->owner->id,
            'table_id' => $this->table->id,
            'status' => 'pending',
            'discount_id' => $discount->id,
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

        $order->refresh();
        $order->recalculateTotals();

        $this->assertEquals(0, $order->discount_amount, 'Discount should not be applied');
        $this->assertEquals(0, $discount->fresh()->used_count, 'used_count should NOT increment when discount not applied');
    }

    #[Test]
    public function used_count_increments_via_full_order_flow()
    {
        $discount = Discount::factory()->create([
            'owner_id' => $this->owner->id,
            'scope' => 'global',
            'type' => 'nominal',
            'value' => 10000,
            'min_purchase' => 0,
            'start_date' => Carbon::now()->subDays(1)->format('Y-m-d'),
            'end_date' => Carbon::now()->addDays(10)->format('Y-m-d'),
            'used_count' => 0,
            'is_active' => true,
        ]);

        // Authenticate as the owner
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
            'discount_id' => $discount->id,
            'amount_paid' => 50000,
        ]);

        $this->assertEquals(1, $discount->fresh()->used_count, 'used_count should increment after full order flow');
    }
}
