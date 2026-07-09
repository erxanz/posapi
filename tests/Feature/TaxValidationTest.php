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
use App\Models\Tax;
use App\Models\Table;
use App\Services\OrderService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TaxValidationTest extends TestCase
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
    // ACTIVE = FALSE — Pajak nonaktif tidak boleh dipakai
    // =========================================================================

    #[Test]
    public function inactive_tax_rejected_via_handle_adjustments_path1()
    {
        $tax = Tax::factory()->create([
            'outlet_id' => $this->outlet->id,
            'type' => 'percentage',
            'rate' => 11.0,
            'active' => false,
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

        $refMethod->invoke($service, $order, ['tax_id' => $tax->id]);
        $order->refresh();

        $this->assertNull($order->tax_id, 'Inactive tax should NOT be set via handleAdjustments Path 1');
        $this->assertEquals(0, $order->tax_amount, 'Tax amount should be 0 for inactive tax');
    }

    #[Test]
    public function inactive_tax_nullified_in_recalculate_totals()
    {
        $tax = Tax::factory()->create([
            'outlet_id' => $this->outlet->id,
            'type' => 'percentage',
            'rate' => 11.0,
            'active' => false,
        ]);

        $order = Order::factory()->create([
            'outlet_id' => $this->outlet->id,
            'user_id' => $this->owner->id,
            'table_id' => $this->table->id,
            'status' => 'pending',
            'tax_id' => $tax->id,
            'tax_amount' => 5500,
            'subtotal_price' => 50000,
            'total_price' => 55500,
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

        $this->assertNull($order->tax_id, 'Inactive tax should be nullified in recalculateTotals');
        $this->assertEquals(0, $order->tax_amount, 'Tax amount should be 0 after nullification');
    }

    #[Test]
    public function active_tax_still_applied_via_handle_adjustments_path1()
    {
        $tax = Tax::factory()->create([
            'outlet_id' => $this->outlet->id,
            'type' => 'percentage',
            'rate' => 11.0,
            'active' => true,
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

        $refMethod->invoke($service, $order, ['tax_id' => $tax->id]);
        $order->refresh();

        $this->assertEquals($tax->id, $order->tax_id, 'Active tax should be set via handleAdjustments');
    }

    // =========================================================================
    // TAX_TYPE + TAX_VALUE MATCHING (fix rate * 100)
    // =========================================================================

    #[Test]
    public function tax_type_value_matches_percentage_tax()
    {
        Tax::factory()->create([
            'outlet_id' => $this->outlet->id,
            'type' => 'percentage',
            'rate' => 11.0,
            'active' => true,
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

        // Before fix: rate=11.0, expectedValue=(int)round(11.0*100)=1100, taxValue=11 — no match
        // After fix: rate=11.0, expectedValue=(int)round(11.0)=11, taxValue=11 — MATCH!
        $refMethod->invoke($service, $order, [
            'tax_type' => 'percentage',
            'tax_value' => 11,
        ]);
        $order->refresh();

        $this->assertNotNull($order->tax_id, 'Percentage tax should match via tax_type + tax_value');
    }

    #[Test]
    public function tax_type_value_matches_multiple_percentage_rates()
    {
        Tax::factory()->create([
            'outlet_id' => $this->outlet->id,
            'name' => 'PPN 10%',
            'type' => 'percentage',
            'rate' => 10.0,
            'active' => true,
        ]);

        Tax::factory()->create([
            'outlet_id' => $this->outlet->id,
            'name' => 'PPN 11%',
            'type' => 'percentage',
            'rate' => 11.0,
            'active' => true,
        ]);

        Tax::factory()->create([
            'outlet_id' => $this->outlet->id,
            'name' => 'PPN 12%',
            'type' => 'percentage',
            'rate' => 12.0,
            'active' => true,
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

        $refMethod->invoke($service, $order, [
            'tax_type' => 'percentage',
            'tax_value' => 11,
        ]);
        $order->refresh();

        $this->assertNotNull($order->tax_id, 'Should match the 11% tax');

        $matchedTax = Tax::find($order->tax_id);
        $this->assertEquals(11.0, (float) $matchedTax->rate, 'Should match the exact 11% tax');
    }

    #[Test]
    public function tax_type_value_matches_fixed_tax()
    {
        Tax::factory()->create([
            'outlet_id' => $this->outlet->id,
            'type' => 'fixed',
            'rate' => 2500,
            'active' => true,
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

        $refMethod->invoke($service, $order, [
            'tax_type' => 'fixed',
            'tax_value' => 2500,
        ]);
        $order->refresh();

        $this->assertNotNull($order->tax_id, 'Fixed tax should match via tax_type + tax_value');
    }

    // =========================================================================
    // VALIDATION — tax_type hanya menerima percentage/fixed (tidak nominal)
    // =========================================================================

    #[Test]
    public function tax_type_rejects_nominal_in_validation()
    {
        $validator = Validator::make(
            ['tax_type' => 'nominal'],
            ['tax_type' => 'nullable|in:percentage,fixed']
        );

        $this->assertTrue($validator->fails(), 'nominal should fail validation');
        $this->assertArrayHasKey('tax_type', $validator->errors()->toArray());
    }

    #[Test]
    public function tax_type_accepts_percentage()
    {
        $validator = Validator::make(
            ['tax_type' => 'percentage'],
            ['tax_type' => 'nullable|in:percentage,fixed']
        );

        $this->assertFalse($validator->fails(), 'percentage should pass validation');
    }

    #[Test]
    public function tax_type_accepts_fixed()
    {
        $validator = Validator::make(
            ['tax_type' => 'fixed'],
            ['tax_type' => 'nullable|in:percentage,fixed']
        );

        $this->assertFalse($validator->fails(), 'fixed should pass validation');
    }

    // =========================================================================
    // CROSS-TENANT — Pajak outlet lain tidak boleh dipakai
    // =========================================================================

    #[Test]
    public function tax_from_other_outlet_not_applied()
    {
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $otherOutlet = Outlet::factory()->create(['owner_id' => $otherOwner->id]);
        $otherTax = Tax::factory()->create([
            'outlet_id' => $otherOutlet->id,
            'type' => 'percentage',
            'rate' => 11.0,
            'active' => true,
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

        $refMethod->invoke($service, $order, ['tax_id' => $otherTax->id]);
        $order->refresh();

        $this->assertNull($order->tax_id, 'Tax from other outlet should NOT be applied');
    }
}
