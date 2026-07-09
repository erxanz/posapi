<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Category;
use App\Models\StockHistory;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class StockControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $karyawan;
    private Outlet $outlet;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'manager']);
        $this->outlet = Outlet::factory()->create(['owner_id' => $this->owner->id]);
        $category = Category::factory()->create(['owner_id' => $this->owner->id]);
        $this->product = Product::factory()->create([
            'owner_id' => $this->owner->id,
            'category_id' => $category->id,
        ]);
        $this->outlet->products()->attach($this->product->id, [
            'price' => 25000,
            'stock' => 50,
            'is_active' => true,
        ]);
        $this->karyawan = User::factory()->create([
            'role' => 'karyawan',
            'outlet_id' => $this->outlet->id,
        ]);
    }

    // =========================================================================
    // ADJUST - IN
    // =========================================================================

    #[Test]
    public function manager_can_adjust_stock_in()
    {
        $this->actingAs($this->owner);
        $response = $this->postJson('/api/v1/stocks/adjust', [
            'outlet_id' => $this->outlet->id,
            'product_id' => $this->product->id,
            'type' => 'in',
            'quantity' => 10,
            'reference' => 'Restock',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('current_stock', 60);

        $this->assertDatabaseHas('stock_histories', [
            'product_id' => $this->product->id,
            'type' => 'in',
            'quantity' => 10,
            'final_stock' => 60,
        ]);
    }

    // =========================================================================
    // ADJUST - OUT
    // =========================================================================

    #[Test]
    public function manager_can_adjust_stock_out()
    {
        $this->actingAs($this->owner);
        $response = $this->postJson('/api/v1/stocks/adjust', [
            'outlet_id' => $this->outlet->id,
            'product_id' => $this->product->id,
            'type' => 'out',
            'quantity' => 10,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('current_stock', 40);
    }

    #[Test]
    public function stock_out_fails_when_insufficient()
    {
        $this->actingAs($this->owner);
        $response = $this->postJson('/api/v1/stocks/adjust', [
            'outlet_id' => $this->outlet->id,
            'product_id' => $this->product->id,
            'type' => 'out',
            'quantity' => 999,
        ]);

        $response->assertStatus(400);
    }

    // =========================================================================
    // ADJUST - OPNAME
    // =========================================================================

    #[Test]
    public function manager_can_do_stock_opname()
    {
        $this->actingAs($this->owner);
        $response = $this->postJson('/api/v1/stocks/adjust', [
            'outlet_id' => $this->outlet->id,
            'product_id' => $this->product->id,
            'type' => 'opname',
            'quantity' => 30, // stok fisik
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('current_stock', 30);
        // Selisih: 30 - 50 = -20
        $this->assertDatabaseHas('stock_histories', [
            'product_id' => $this->product->id,
            'type' => 'opname',
            'quantity' => -20,
            'final_stock' => 30,
        ]);
    }

    // =========================================================================
    // AUTHORIZATION
    // =========================================================================

    #[Test]
    public function karyawan_can_adjust_stock_in_own_outlet()
    {
        $this->actingAs($this->karyawan);
        $response = $this->postJson('/api/v1/stocks/adjust', [
            'outlet_id' => $this->outlet->id,
            'product_id' => $this->product->id,
            'type' => 'in',
            'quantity' => 5,
        ]);

        $response->assertStatus(200);
    }

    #[Test]
    public function karyawan_cannot_adjust_stock_in_other_outlet()
    {
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $otherOutlet = Outlet::factory()->create(['owner_id' => $otherOwner->id]);

        $this->actingAs($this->karyawan);
        $response = $this->postJson('/api/v1/stocks/adjust', [
            'outlet_id' => $otherOutlet->id,
            'product_id' => $this->product->id,
            'type' => 'in',
            'quantity' => 5,
        ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function adjust_requires_valid_data()
    {
        $this->actingAs($this->owner);
        $response = $this->postJson('/api/v1/stocks/adjust', []);

        $response->assertStatus(422);
    }
}
