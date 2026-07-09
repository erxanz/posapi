<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Station;
use App\Models\Product;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Category;

class StationControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'manager']);
        $this->outlet = Outlet::factory()->create(['owner_id' => $this->owner->id]);
    }

    // =========================================================================
    // INDEX
    // =========================================================================

    #[Test]
    public function manager_can_list_stations()
    {
        Station::factory()->count(3)->create(['owner_id' => $this->owner->id]);

        $this->actingAs($this->owner);
        $response = $this->getJson('/api/v1/stations');

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data.data');
    }

    #[Test]
    public function manager_only_sees_own_stations()
    {
        Station::factory()->create(['owner_id' => $this->owner->id, 'name' => 'My Station']);
        $otherOwner = User::factory()->create(['role' => 'manager']);
        Station::factory()->create(['owner_id' => $otherOwner->id, 'name' => 'Other Station']);

        $this->actingAs($this->owner);
        $response = $this->getJson('/api/v1/stations');

        $response->assertJsonCount(1, 'data.data');
    }

    // =========================================================================
    // STORE
    // =========================================================================

    #[Test]
    public function manager_can_create_station()
    {
        $this->actingAs($this->owner);
        $response = $this->postJson('/api/v1/stations', [
            'name' => 'Dapur Utama',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('stations', [
            'name' => 'Dapur Utama',
            'owner_id' => $this->owner->id,
        ]);
    }

    #[Test]
    public function create_station_requires_name()
    {
        $this->actingAs($this->owner);
        $response = $this->postJson('/api/v1/stations', []);

        $response->assertStatus(422);
    }

    // =========================================================================
    // SHOW
    // =========================================================================

    #[Test]
    public function manager_can_view_station()
    {
        $station = Station::factory()->create(['owner_id' => $this->owner->id]);

        $this->actingAs($this->owner);
        $response = $this->getJson("/api/v1/stations/{$station->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $station->id);
    }

    #[Test]
    public function manager_cannot_view_other_owner_station()
    {
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $station = Station::factory()->create(['owner_id' => $otherOwner->id]);

        $this->actingAs($this->owner);
        $response = $this->getJson("/api/v1/stations/{$station->id}");

        $response->assertStatus(403);
    }

    // =========================================================================
    // UPDATE
    // =========================================================================

    #[Test]
    public function manager_can_update_station()
    {
        $station = Station::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Old Name']);

        $this->actingAs($this->owner);
        $response = $this->putJson("/api/v1/stations/{$station->id}", [
            'name' => 'New Name',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('New Name', $station->fresh()->name);
    }

    // =========================================================================
    // DESTROY
    // =========================================================================

    #[Test]
    public function manager_can_delete_station()
    {
        $station = Station::factory()->create(['owner_id' => $this->owner->id]);

        $this->actingAs($this->owner);
        $response = $this->deleteJson("/api/v1/stations/{$station->id}");

        $response->assertStatus(200);
        $this->assertModelMissing($station);
    }

    #[Test]
    public function cannot_delete_station_with_products()
    {
        $station = Station::factory()->create(['owner_id' => $this->owner->id]);
        Product::factory()->create([
            'station_id' => $station->id,
            'owner_id' => $this->owner->id,                    'category_id' => Category::factory()->create(['owner_id' => $this->owner->id])->id,
        ]);

        $this->actingAs($this->owner);
        $response = $this->deleteJson("/api/v1/stations/{$station->id}");

        $response->assertStatus(422);
    }

    // =========================================================================
    // STATION PRODUCTS
    // =========================================================================

    #[Test]
    public function can_get_products_by_station()
    {
        $station = Station::factory()->create(['owner_id' => $this->owner->id]);
        $category = Category::factory()->create(['owner_id' => $this->owner->id]);
        $product = Product::factory()->create([
            'station_id' => $station->id,
            'owner_id' => $this->owner->id,
            'category_id' => $category->id,
        ]);
        $this->outlet->products()->attach($product->id, [
            'price' => 25000,
            'stock' => 10,
            'is_active' => true,
        ]);

        $this->actingAs($this->owner);
        $response = $this->getJson("/api/v1/stations/{$station->id}/products?outlet_id={$this->outlet->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.name', $station->name);
        $this->assertCount(1, $response->json('data.products'));
    }
}
