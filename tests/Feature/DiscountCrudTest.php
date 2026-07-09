<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Discount;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DiscountCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $karyawan;
    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'manager']);
        $this->outlet = Outlet::factory()->create(['owner_id' => $this->owner->id]);
        $this->karyawan = User::factory()->create([
            'role' => 'karyawan',
            'outlet_id' => $this->outlet->id,
        ]);
    }

    // =========================================================================
    // INDEX
    // =========================================================================

    #[Test]
    public function manager_can_list_discounts()
    {
        Discount::factory()->count(3)->create(['owner_id' => $this->owner->id]);

        $this->actingAs($this->owner);
        $response = $this->getJson('/api/v1/discounts');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json());
    }

    #[Test]
    public function manager_only_sees_own_discounts()
    {
        Discount::factory()->create(['owner_id' => $this->owner->id, 'name' => 'My Discount']);
        $otherOwner = User::factory()->create(['role' => 'manager']);
        Discount::factory()->create(['owner_id' => $otherOwner->id, 'name' => 'Other Discount']);

        $this->actingAs($this->owner);
        $response = $this->getJson('/api/v1/discounts');

        $this->assertCount(1, $response->json());
        $this->assertEquals('My Discount', $response->json('0.name'));
    }

    #[Test]
    public function karyawan_sees_active_discounts_from_outlet_owner()
    {
        Discount::factory()->create([
            'owner_id' => $this->owner->id,
            'is_active' => true,
            'start_date' => Carbon::now()->subDays(1),
            'end_date' => Carbon::now()->addDays(10),
            'name' => 'Active Discount',
        ]);
        Discount::factory()->create([
            'owner_id' => $this->owner->id,
            'is_active' => false,
            'start_date' => Carbon::now()->subDays(10),
            'end_date' => Carbon::now()->subDays(1),
            'name' => 'Inactive Discount',
        ]);

        $this->actingAs($this->karyawan);
        $response = $this->getJson('/api/v1/discounts');

        $this->assertCount(1, $response->json());
    }

    // =========================================================================
    // STORE
    // =========================================================================

    #[Test]
    public function manager_can_create_discount()
    {
        $this->actingAs($this->owner);
        $response = $this->postJson('/api/v1/discounts', [
            'name' => 'Promo Akhir Pekan',
            'scope' => 'global',
            'type' => 'percentage',
            'value' => 10,
            'min_purchase' => 50000,
            'start_date' => Carbon::now()->format('Y-m-d'),
            'end_date' => Carbon::now()->addDays(30)->format('Y-m-d'),
            'is_active' => true,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('discounts', [
            'name' => 'Promo Akhir Pekan',
            'owner_id' => $this->owner->id,
        ]);
    }

    #[Test]
    public function karyawan_cannot_create_discount()
    {
        $this->actingAs($this->karyawan);
        $response = $this->postJson('/api/v1/discounts', [
            'name' => 'Unauthorized Promo',
            'scope' => 'global',
            'type' => 'nominal',
            'value' => 10000,
            'start_date' => Carbon::now()->format('Y-m-d'),
            'end_date' => Carbon::now()->addDays(7)->format('Y-m-d'),
            'is_active' => true,
        ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function create_discount_requires_valid_scope()
    {
        $this->actingAs($this->owner);
        $response = $this->postJson('/api/v1/discounts', [
            'name' => 'Invalid',
            'scope' => 'invalid_scope',
            'type' => 'nominal',
            'value' => 10000,
            'start_date' => Carbon::now()->format('Y-m-d'),
            'end_date' => Carbon::now()->addDays(7)->format('Y-m-d'),
            'is_active' => true,
        ]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // UPDATE
    // =========================================================================

    #[Test]
    public function manager_can_update_discount()
    {
        $discount = Discount::factory()->create([
            'owner_id' => $this->owner->id,
            'name' => 'Old Name',
            'scope' => 'global',
            'type' => 'nominal',
            'value' => 5000,
            'start_date' => Carbon::now()->format('Y-m-d'),
            'end_date' => Carbon::now()->addDays(7)->format('Y-m-d'),
        ]);

        $this->actingAs($this->owner);
        $response = $this->putJson("/api/v1/discounts/{$discount->id}", [
            'name' => 'New Name',
            'scope' => 'global',
            'type' => 'nominal',
            'value' => 10000,
            'start_date' => Carbon::now()->format('Y-m-d'),
            'end_date' => Carbon::now()->addDays(14)->format('Y-m-d'),
            'is_active' => true,
        ]);

        $response->assertStatus(200);
        $this->assertEquals('New Name', $discount->fresh()->name);
        $this->assertEquals(10000, $discount->fresh()->value);
    }

    #[Test]
    public function manager_cannot_update_other_owner_discount()
    {
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $discount = Discount::factory()->create(['owner_id' => $otherOwner->id]);

        $this->actingAs($this->owner);
        $response = $this->putJson("/api/v1/discounts/{$discount->id}", [
            'name' => 'Hacked',
            'scope' => 'global',
            'type' => 'nominal',
            'value' => 1000,
            'start_date' => Carbon::now()->format('Y-m-d'),
            'end_date' => Carbon::now()->addDays(7)->format('Y-m-d'),
            'is_active' => true,
        ]);

        $response->assertStatus(403);
    }

    // =========================================================================
    // DESTROY
    // =========================================================================

    #[Test]
    public function manager_can_delete_discount()
    {
        $discount = Discount::factory()->create(['owner_id' => $this->owner->id]);

        $this->actingAs($this->owner);
        $response = $this->deleteJson("/api/v1/discounts/{$discount->id}");

        $response->assertStatus(200);
        $this->assertModelMissing($discount);
    }

    #[Test]
    public function manager_cannot_delete_other_owner_discount()
    {
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $discount = Discount::factory()->create(['owner_id' => $otherOwner->id]);

        $this->actingAs($this->owner);
        $response = $this->deleteJson("/api/v1/discounts/{$discount->id}");

        $response->assertStatus(403);
    }
}
