<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Category;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProductControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $karyawan;
    private Outlet $outlet;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'manager']);
        $this->outlet = Outlet::factory()->create(['owner_id' => $this->owner->id]);
        $this->category = Category::factory()->create(['owner_id' => $this->owner->id]);
        $this->karyawan = User::factory()->create([
            'role' => 'karyawan',
            'outlet_id' => $this->outlet->id,
        ]);
    }

    // =========================================================================
    // INDEX (Manager view - all products)
    // =========================================================================

    #[Test]
    public function manager_can_list_products()
    {
        Product::factory()->count(3)->create(['owner_id' => $this->owner->id, 'category_id' => $this->category->id]);

        $this->actingAs($this->owner);
        $response = $this->getJson('/api/v1/products');

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data.data');
    }

    #[Test]
    public function manager_only_sees_own_products()
    {
        Product::factory()->create(['owner_id' => $this->owner->id, 'name' => 'My Product', 'category_id' => $this->category->id]);
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $otherCat = Category::factory()->create(['owner_id' => $otherOwner->id]);
        Product::factory()->create(['owner_id' => $otherOwner->id, 'name' => 'Other Product', 'category_id' => $otherCat->id]);

        $this->actingAs($this->owner);
        $response = $this->getJson('/api/v1/products');

        $this->assertCount(1, $response->json('data.data'));
    }

    #[Test]
    public function products_can_be_searched()
    {
        Product::factory()->create(['name' => 'Nasi Goreng', 'owner_id' => $this->owner->id, 'category_id' => $this->category->id]);
        Product::factory()->create(['name' => 'Es Teh', 'owner_id' => $this->owner->id, 'category_id' => $this->category->id]);

        $this->actingAs($this->owner);
        $response = $this->getJson('/api/v1/products?search=Nasi');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    #[Test]
    public function products_can_be_filtered_by_category()
    {
        $cat2 = Category::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Minuman']);
        Product::factory()->create(['category_id' => $this->category->id, 'owner_id' => $this->owner->id]);
        Product::factory()->create(['category_id' => $cat2->id, 'owner_id' => $this->owner->id]);

        $this->actingAs($this->owner);
        $response = $this->getJson('/api/v1/products?category_id=' . $this->category->id);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    // =========================================================================
    // INDEX (Karyawan view - outlet products with pivot data)
    // =========================================================================

    #[Test]
    public function karyawan_can_list_outlet_products()
    {
        $product = Product::factory()->create(['owner_id' => $this->owner->id, 'category_id' => $this->category->id]);
        $this->outlet->products()->attach($product->id, ['price' => 25000, 'stock' => 10, 'is_active' => true]);

        $this->actingAs($this->karyawan);
        $response = $this->getJson('/api/v1/products');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    #[Test]
    public function karyawan_product_list_includes_pivot_price_and_stock()
    {
        $product = Product::factory()->create(['owner_id' => $this->owner->id, 'category_id' => $this->category->id]);
        $this->outlet->products()->attach($product->id, ['price' => 35000, 'stock' => 20, 'is_active' => true]);

        $this->actingAs($this->karyawan);
        $response = $this->getJson('/api/v1/products');

        $this->assertEquals(35000, $response->json('data.data.0.price'));
        $this->assertEquals(20, $response->json('data.data.0.stock'));
    }

    // =========================================================================
    // STORE
    // =========================================================================

    #[Test]
    public function manager_can_create_product()
    {
        $this->actingAs($this->owner);
        $response = $this->postJson('/api/v1/products', [
            'category_id' => $this->category->id,
            'name' => 'Nasi Goreng Spesial',
            'cost_price' => 15000,
            'description' => 'Nasi goreng dengan telur',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('products', [
            'name' => 'Nasi Goreng Spesial',
            'owner_id' => $this->owner->id,
        ]);
    }

    #[Test]
    public function create_product_requires_valid_category()
    {
        $this->actingAs($this->owner);
        $response = $this->postJson('/api/v1/products', [
            'category_id' => 99999,
            'name' => 'Nasi Goreng',
            'cost_price' => 15000,
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function karyawan_cannot_create_product()
    {
        $this->actingAs($this->karyawan);
        $response = $this->postJson('/api/v1/products', [
            'category_id' => $this->category->id,
            'name' => 'Nakal',
            'cost_price' => 10000,
        ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function create_product_can_attach_to_outlets()
    {
        $this->actingAs($this->owner);
        $response = $this->postJson('/api/v1/products', [
            'category_id' => $this->category->id,
            'name' => 'Es Jeruk',
            'cost_price' => 8000,
            'outlets' => [
                [
                    'outlet_id' => $this->outlet->id,
                    'price' => 12000,
                    'stock' => 50,
                    'is_active' => true,
                ],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('outlet_product', [
            'outlet_id' => $this->outlet->id,
            'price' => 12000,
            'stock' => 50,
        ]);
    }

    // =========================================================================
    // SHOW
    // =========================================================================

    #[Test]
    public function manager_can_view_product()
    {
        $product = Product::factory()->create(['owner_id' => $this->owner->id, 'category_id' => $this->category->id]);

        $this->actingAs($this->owner);
        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $product->id);
    }

    #[Test]
    public function manager_cannot_view_other_owner_product()
    {
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $otherCat = Category::factory()->create(['owner_id' => $otherOwner->id]);
        $product = Product::factory()->create(['owner_id' => $otherOwner->id, 'category_id' => $otherCat->id]);

        $this->actingAs($this->owner);
        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertStatus(403);
    }

    // =========================================================================
    // UPDATE
    // =========================================================================

    #[Test]
    public function manager_can_update_product()
    {
        $product = Product::factory()->create([
            'owner_id' => $this->owner->id,
            'category_id' => $this->category->id,
            'name' => 'Old Name',
            'cost_price' => 10000,
        ]);

        $this->actingAs($this->owner);
        $response = $this->putJson("/api/v1/products/{$product->id}", [
            'name' => 'New Name',
            'cost_price' => 20000,
            'category_id' => $this->category->id,
        ]);

        $response->assertStatus(200);
        $this->assertEquals('New Name', $product->fresh()->name);
        $this->assertEquals(20000, (int) $product->fresh()->cost_price);
    }

    // =========================================================================
    // DESTROY
    // =========================================================================

    #[Test]
    public function manager_can_delete_product()
    {
        $product = Product::factory()->create(['owner_id' => $this->owner->id, 'category_id' => $this->category->id]);

        $this->actingAs($this->owner);
        $response = $this->deleteJson("/api/v1/products/{$product->id}");

        $response->assertStatus(200);
        $this->assertModelMissing($product);
    }

    #[Test]
    public function manager_cannot_delete_other_owner_product()
    {
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $otherCat = Category::factory()->create(['owner_id' => $otherOwner->id]);
        $product = Product::factory()->create(['owner_id' => $otherOwner->id, 'category_id' => $otherCat->id]);

        $this->actingAs($this->owner);
        $response = $this->deleteJson("/api/v1/products/{$product->id}");

        $response->assertStatus(403);
    }
}
