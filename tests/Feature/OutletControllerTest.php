<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Category;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class OutletControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $dev;
    private User $karyawan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'manager', 'outlet_id' => null]);
        $this->dev = User::factory()->create(['role' => 'developer']);
        $this->karyawan = User::factory()->create(['role' => 'karyawan', 'outlet_id' => null]);
    }

    // =========================================================================
    // INDEX
    // =========================================================================

    #[Test]
    public function manager_can_list_own_outlets()
    {
        Outlet::factory()->create(['owner_id' => $this->owner->id, 'name' => 'My Outlet']);
        $otherOwner = User::factory()->create(['role' => 'manager']);
        Outlet::factory()->create(['owner_id' => $otherOwner->id, 'name' => 'Other Outlet']);

        $this->actingAs($this->owner);
        $response = $this->getJson('/api/v1/outlets');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    #[Test]
    public function developer_sees_all_outlets()
    {
        Outlet::factory()->create(['owner_id' => $this->owner->id]);
        Outlet::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Second Outlet']);

        $this->actingAs($this->dev);
        $response = $this->getJson('/api/v1/outlets');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.data'));
    }

    #[Test]
    public function karyawan_sees_own_outlet()
    {
        $outlet = Outlet::factory()->create(['owner_id' => $this->owner->id]);
        $this->karyawan->outlet_id = $outlet->id;
        $this->karyawan->save();

        Outlet::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Hidden Outlet']);

        $this->actingAs($this->karyawan);
        $response = $this->getJson('/api/v1/outlets');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    // =========================================================================
    // CREATE (STORE)
    // =========================================================================

    #[Test]
    public function manager_can_create_outlet()
    {
        $this->actingAs($this->owner);
        $response = $this->postJson('/api/v1/outlets', [
            'name' => 'Cabang Baru',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'Cabang Baru');
        $response->assertJsonPath('data.owner_id', $this->owner->id);
    }

    #[Test]
    public function manager_gets_default_outlet_assigned_on_first_create()
    {
        $this->assertNull($this->owner->outlet_id);

        $this->actingAs($this->owner);
        $response = $this->postJson('/api/v1/outlets', [
            'name' => 'Outlet Pertama',
        ]);

        $response->assertStatus(201);
        $this->owner->refresh();
        $this->assertNotNull($this->owner->outlet_id);
    }

    #[Test]
    public function karyawan_cannot_create_outlet()
    {
        $this->actingAs($this->karyawan);
        $response = $this->postJson('/api/v1/outlets', [
            'name' => 'Hacked Outlet',
        ]);

        $response->assertStatus(403);
    }

    // =========================================================================
    // SHOW
    // =========================================================================

    #[Test]
    public function manager_can_view_outlet()
    {
        $outlet = Outlet::factory()->create(['owner_id' => $this->owner->id]);

        $this->actingAs($this->owner);
        $response = $this->getJson("/api/v1/outlets/{$outlet->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('id', $outlet->id);
    }

    #[Test]
    public function manager_cannot_view_other_outlet()
    {
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $outlet = Outlet::factory()->create(['owner_id' => $otherOwner->id]);

        $this->actingAs($this->owner);
        $response = $this->getJson("/api/v1/outlets/{$outlet->id}");

        $response->assertStatus(403);
    }

    // =========================================================================
    // UPDATE
    // =========================================================================

    #[Test]
    public function manager_can_update_outlet()
    {
        $outlet = Outlet::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Old Name']);

        $this->actingAs($this->owner);
        $response = $this->putJson("/api/v1/outlets/{$outlet->id}", [
            'name' => 'New Name',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('New Name', $outlet->fresh()->name);
    }

    #[Test]
    public function manager_cannot_update_other_outlet()
    {
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $outlet = Outlet::factory()->create(['owner_id' => $otherOwner->id]);

        $this->actingAs($this->owner);
        $response = $this->putJson("/api/v1/outlets/{$outlet->id}", [
            'name' => 'Hacked',
        ]);

        $response->assertStatus(403);
    }

    // =========================================================================
    // GET PRODUCTS
    // =========================================================================

    #[Test]
    public function manager_can_get_outlet_products()
    {
        $outlet = Outlet::factory()->create(['owner_id' => $this->owner->id]);
        $category = Category::factory()->create(['owner_id' => $this->owner->id]);
        $product = Product::factory()->create([
            'owner_id' => $this->owner->id,
            'category_id' => $category->id,
        ]);
        $outlet->products()->attach($product->id, ['price' => 25000, 'stock' => 10, 'is_active' => true]);

        $this->actingAs($this->owner);
        $response = $this->getJson("/api/v1/outlets/{$outlet->id}/products");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    // =========================================================================
    // SYNC PRODUCTS
    // =========================================================================

    #[Test]
    public function manager_can_sync_products_to_outlet()
    {
        $outlet = Outlet::factory()->create(['owner_id' => $this->owner->id]);
        $category = Category::factory()->create(['owner_id' => $this->owner->id]);
        $product = Product::factory()->create([
            'owner_id' => $this->owner->id,
            'category_id' => $category->id,
        ]);

        $this->actingAs($this->owner);
        $response = $this->postJson("/api/v1/outlets/{$outlet->id}/sync-products", [
            'products' => [
                [
                    'product_id' => $product->id,
                    'price' => 30000,
                    'stock' => 50,
                    'is_active' => true,
                ],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Katalog outlet berhasil diperbarui.');

        $this->assertDatabaseHas('outlet_product', [
            'outlet_id' => $outlet->id,
            'product_id' => $product->id,
            'price' => 30000,
            'stock' => 50,
        ]);
    }

    #[Test]
    public function developer_cannot_sync_products()
    {
        $outlet = Outlet::factory()->create(['owner_id' => $this->owner->id]);

        $this->actingAs($this->dev);
        $response = $this->postJson("/api/v1/outlets/{$outlet->id}/sync-products", [
            'products' => [],
        ]);

        $response->assertStatus(403);
    }

    // =========================================================================
    // DESTROY
    // =========================================================================

    #[Test]
    public function manager_can_delete_outlet()
    {
        $outlet = Outlet::factory()->create(['owner_id' => $this->owner->id]);

        $this->actingAs($this->owner);
        $response = $this->deleteJson("/api/v1/outlets/{$outlet->id}");

        $response->assertStatus(200);
        $this->assertModelMissing($outlet);
    }

    #[Test]
    public function manager_cannot_delete_other_outlet()
    {
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $outlet = Outlet::factory()->create(['owner_id' => $otherOwner->id]);

        $this->actingAs($this->owner);
        $response = $this->deleteJson("/api/v1/outlets/{$outlet->id}");

        $response->assertStatus(403);
    }
}
