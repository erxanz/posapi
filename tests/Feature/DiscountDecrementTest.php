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
use App\Models\Table;use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DiscountDecrementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Outlet $outlet;
    private Category $category;
    private Product $product;
    private Table $table;
    private Discount $discount;
    private Order $order;

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

        $this->discount = Discount::factory()->create([
            'owner_id' => $this->owner->id,
            'scope' => 'global',
            'type' => 'nominal',
            'value' => 10000,
            'min_purchase' => 0,
            'start_date' => Carbon::now()->subDays(1)->format('Y-m-d'),
            'end_date' => Carbon::now()->addDays(10)->format('Y-m-d'),
            'used_count' => 5,
            'is_active' => true,
        ]);

        $this->order = Order::factory()->create([
            'outlet_id' => $this->outlet->id,
            'user_id' => $this->owner->id,
            'table_id' => $this->table->id,
            'status' => 'pending',
            'discount_id' => $this->discount->id,
            'discount_amount' => 10000,
            'subtotal_price' => 50000,
            'total_price' => 40000,
        ]);

        OrderItem::factory()->create([
            'order_id' => $this->order->id,
            'product_id' => $this->product->id,
            'qty' => 1,
            'price' => 50000,
            'total_price' => 50000,
        ]);
    }

    #[Test]
    public function decrement_usage_when_order_cancelled_via_auto_cancel()
    {
        $this->assertEquals(5, $this->discount->fresh()->used_count);

        $this->order->decrementDiscountUsage();
        $this->order->update(['status' => 'cancelled']);

        $this->assertEquals(4, $this->discount->fresh()->used_count, 'used_count should decrement when order is cancelled');
    }

    #[Test]
    public function decrement_usage_not_negative()
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

        $order = Order::factory()->create([
            'outlet_id' => $this->outlet->id,
            'user_id' => $this->owner->id,
            'table_id' => $this->table->id,
            'status' => 'pending',
            'discount_id' => $discount->id,
            'subtotal_price' => 50000,
            'total_price' => 50000,
        ]);

        $order->decrementDiscountUsage();
        $this->assertEquals(0, $discount->fresh()->used_count, 'used_count should not go below 0');
    }

    #[Test]
    public function decrement_usage_does_nothing_when_no_discount()
    {
        $orderWithoutDiscount = Order::factory()->create([
            'outlet_id' => $this->outlet->id,
            'user_id' => $this->owner->id,
            'table_id' => $this->table->id,
            'status' => 'pending',
            'discount_id' => null,
            'subtotal_price' => 50000,
            'total_price' => 50000,
        ]);

        // Should not throw any exception
        $orderWithoutDiscount->decrementDiscountUsage();
        $this->assertTrue(true, 'decrementDiscountUsage should not throw when no discount');
    }

    #[Test]
    public function decrement_usage_when_discount_deleted_from_db()
    {
        // Because of nullOnDelete constraint, SQLite sets discount_id to null
        // when the referenced discount is deleted. But in other databases like
        // MySQL/PostgreSQL, the behavior may differ. This test verifies the
        // guard clause handles the case gracefully regardless.
        $this->discount->delete();

        $order = $this->order->fresh();

        // Should not throw any exception regardless of discount_id state
        $order->decrementDiscountUsage();
        $this->assertTrue(true, 'decrementDiscountUsage should not throw when discount was deleted');
    }
}
