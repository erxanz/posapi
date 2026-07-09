<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CategoryControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $karyawan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'manager']);
        $this->karyawan = User::factory()->create(['role' => 'karyawan', 'outlet_id' => null]);
    }

    // =========================================================================
    // INDEX
    // =========================================================================

    #[Test]
    public function manager_can_list_categories()
    {
        foreach (['Makanan', 'Minuman', 'Snack'] as $name) {
            Category::factory()->create(['owner_id' => $this->owner->id, 'name' => $name]);
        }

        $this->actingAs($this->owner);
        $response = $this->getJson('/api/v1/categories');

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data.data');
    }

    #[Test]
    public function manager_only_sees_own_categories()
    {
        Category::factory()->create(['owner_id' => $this->owner->id, 'name' => 'My Category']);
        $otherOwner = User::factory()->create(['role' => 'manager']);
        Category::factory()->create(['owner_id' => $otherOwner->id, 'name' => 'Other Category']);

        $this->actingAs($this->owner);
        $response = $this->getJson('/api/v1/categories');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.data');
        $this->assertEquals('My Category', $response->json('data.data.0.name'));
    }

    #[Test]
    public function categories_can_be_searched()
    {
        Category::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Makanan']);
        Category::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Minuman']);

        $this->actingAs($this->owner);
        $response = $this->getJson('/api/v1/categories?search=Makan');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    // =========================================================================
    // STORE
    // =========================================================================

    #[Test]
    public function manager_can_create_category()
    {
        $this->actingAs($this->owner);
        $response = $this->postJson('/api/v1/categories', [
            'name' => 'Minuman Segar',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('categories', [
            'name' => 'Minuman Segar',
            'owner_id' => $this->owner->id,
        ]);
    }

    #[Test]
    public function create_category_requires_name()
    {
        $this->actingAs($this->owner);
        $response = $this->postJson('/api/v1/categories', []);

        $response->assertStatus(422);
    }

    #[Test]
    public function create_category_rejects_duplicate_name_same_owner()
    {
        Category::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Makanan']);

        $this->actingAs($this->owner);
        $response = $this->postJson('/api/v1/categories', [
            'name' => 'Makanan',
        ]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // SHOW
    // =========================================================================

    #[Test]
    public function manager_can_view_category()
    {
        $category = Category::factory()->create(['owner_id' => $this->owner->id]);

        $this->actingAs($this->owner);
        $response = $this->getJson("/api/v1/categories/{$category->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $category->id);
    }

    #[Test]
    public function manager_cannot_view_other_owner_category()
    {
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $category = Category::factory()->create(['owner_id' => $otherOwner->id]);

        $this->actingAs($this->owner);
        $response = $this->getJson("/api/v1/categories/{$category->id}");

        $response->assertStatus(403);
    }

    // =========================================================================
    // UPDATE
    // =========================================================================

    #[Test]
    public function manager_can_update_category()
    {
        $category = Category::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Old Name']);

        $this->actingAs($this->owner);
        $response = $this->putJson("/api/v1/categories/{$category->id}", [
            'name' => 'New Name',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('New Name', $category->fresh()->name);
    }

    #[Test]
    public function manager_cannot_update_other_owner_category()
    {
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $category = Category::factory()->create(['owner_id' => $otherOwner->id]);

        $this->actingAs($this->owner);
        $response = $this->putJson("/api/v1/categories/{$category->id}", [
            'name' => 'Hacked Name',
        ]);

        $response->assertStatus(403);
    }

    // =========================================================================
    // DESTROY
    // =========================================================================

    #[Test]
    public function manager_can_delete_category()
    {
        $category = Category::factory()->create(['owner_id' => $this->owner->id]);

        $this->actingAs($this->owner);
        $response = $this->deleteJson("/api/v1/categories/{$category->id}");

        $response->assertStatus(200);
        $this->assertModelMissing($category);
    }

    #[Test]
    public function cannot_delete_category_with_products()
    {
        $category = Category::factory()->create(['owner_id' => $this->owner->id]);
        Product::factory()->create(['category_id' => $category->id, 'owner_id' => $this->owner->id]);

        $this->actingAs($this->owner);
        $response = $this->deleteJson("/api/v1/categories/{$category->id}");

        $response->assertStatus(422);
        $this->assertModelExists($category);
    }

    #[Test]
    public function manager_cannot_delete_other_owner_category()
    {
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $category = Category::factory()->create(['owner_id' => $otherOwner->id]);

        $this->actingAs($this->owner);
        $response = $this->deleteJson("/api/v1/categories/{$category->id}");

        $response->assertStatus(403);
    }

    // =========================================================================
    // KARYAWAN (via outlet owner resolution)
    // =========================================================================

    #[Test]
    public function karyawan_can_list_categories_via_outlet_owner()
    {
        $outlet = Outlet::factory()->create(['owner_id' => $this->owner->id]);
        $this->karyawan->outlet_id = $outlet->id;
        $this->karyawan->save();

        Category::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Outlet Category']);

        $this->actingAs($this->karyawan);
        $response = $this->getJson('/api/v1/categories');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }
}
