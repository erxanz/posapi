<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Tax;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TaxCrudTest extends TestCase
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
    public function manager_can_list_taxes()
    {
        foreach (['PPN 11%', 'Service Charge', 'PPh 21'] as $name) {
            Tax::factory()->create(['outlet_id' => $this->outlet->id, 'name' => $name]);
        }

        $this->actingAs($this->owner);
        $response = $this->getJson('/api/v1/taxes');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json());
    }

    #[Test]
    public function manager_only_sees_own_outlet_taxes()
    {
        Tax::factory()->create(['outlet_id' => $this->outlet->id, 'name' => 'My Tax']);
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $otherOutlet = Outlet::factory()->create(['owner_id' => $otherOwner->id]);
        Tax::factory()->create(['outlet_id' => $otherOutlet->id, 'name' => 'Other Tax']);

        $this->actingAs($this->owner);
        $response = $this->getJson('/api/v1/taxes');

        $this->assertCount(1, $response->json());
        $this->assertEquals('My Tax', $response->json('0.name'));
    }

    #[Test]
    public function karyawan_sees_own_outlet_taxes()
    {
        Tax::factory()->create(['outlet_id' => $this->outlet->id, 'name' => 'My Tax']);
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $otherOutlet = Outlet::factory()->create(['owner_id' => $otherOwner->id]);
        Tax::factory()->create(['outlet_id' => $otherOutlet->id, 'name' => 'Other Tax']);

        $this->actingAs($this->karyawan);
        $response = $this->getJson('/api/v1/taxes');

        $this->assertCount(1, $response->json());
    }

    // =========================================================================
    // STORE
    // =========================================================================

    #[Test]
    public function manager_can_create_tax()
    {
        $this->actingAs($this->owner);
        $response = $this->postJson('/api/v1/taxes', [
            'name' => 'PPN 11%',
            'rate' => 11.0,
            'type' => 'percentage',
            'outlet_id' => $this->outlet->id,
            'active' => true,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('taxes', [
            'name' => 'PPN 11%',
            'outlet_id' => $this->outlet->id,
        ]);
    }

    #[Test]
    public function karyawan_cannot_create_tax()
    {
        $this->actingAs($this->karyawan);
        $response = $this->postJson('/api/v1/taxes', [
            'name' => 'Unauthorized Tax',
            'rate' => 5.0,
            'type' => 'percentage',
            'outlet_id' => $this->outlet->id,
            'active' => true,
        ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function create_tax_rejects_duplicate_name_in_same_outlet()
    {
        Tax::factory()->create(['outlet_id' => $this->outlet->id, 'name' => 'PPN 11%']);

        $this->actingAs($this->owner);
        $response = $this->postJson('/api/v1/taxes', [
            'name' => 'PPN 11%',
            'rate' => 11.0,
            'type' => 'percentage',
            'outlet_id' => $this->outlet->id,
            'active' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.name.0',
            'Pajak / Biaya dengan nama ini sudah ada di cabang tersebut.'
        );
    }

    // =========================================================================
    // SHOW
    // =========================================================================

    #[Test]
    public function manager_can_view_tax()
    {
        $tax = Tax::factory()->create(['outlet_id' => $this->outlet->id]);

        $this->actingAs($this->owner);
        $response = $this->getJson("/api/v1/taxes/{$tax->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('id', $tax->id);
    }

    #[Test]
    public function manager_cannot_view_other_outlet_tax()
    {
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $otherOutlet = Outlet::factory()->create(['owner_id' => $otherOwner->id]);
        $tax = Tax::factory()->create(['outlet_id' => $otherOutlet->id]);

        $this->actingAs($this->owner);
        $response = $this->getJson("/api/v1/taxes/{$tax->id}");

        $response->assertStatus(403);
    }

    // =========================================================================
    // UPDATE
    // =========================================================================

    #[Test]
    public function manager_can_update_tax()
    {
        $tax = Tax::factory()->create([
            'outlet_id' => $this->outlet->id,
            'name' => 'Old Tax',
            'rate' => 5.0,
            'type' => 'percentage',
        ]);

        $this->actingAs($this->owner);
        $response = $this->putJson("/api/v1/taxes/{$tax->id}", [
            'name' => 'New Tax',
            'rate' => 11.0,
            'type' => 'percentage',
            'outlet_id' => $this->outlet->id,
            'active' => true,
        ]);

        $response->assertStatus(200);
        $this->assertEquals('New Tax', $tax->fresh()->name);
        $this->assertEquals(11.0, (float) $tax->fresh()->rate);
    }

    // =========================================================================
    // DESTROY
    // =========================================================================

    #[Test]
    public function manager_can_delete_tax()
    {
        $tax = Tax::factory()->create(['outlet_id' => $this->outlet->id]);

        $this->actingAs($this->owner);
        $response = $this->deleteJson("/api/v1/taxes/{$tax->id}");

        $response->assertStatus(204);
        $this->assertModelMissing($tax);
    }

    #[Test]
    public function manager_cannot_delete_other_outlet_tax()
    {
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $otherOutlet = Outlet::factory()->create(['owner_id' => $otherOwner->id]);
        $tax = Tax::factory()->create(['outlet_id' => $otherOutlet->id]);

        $this->actingAs($this->owner);
        $response = $this->deleteJson("/api/v1/taxes/{$tax->id}");

        $response->assertStatus(403);
    }

    #[Test]
    public function karyawan_cannot_delete_tax()
    {
        $tax = Tax::factory()->create(['outlet_id' => $this->outlet->id]);

        $this->actingAs($this->karyawan);
        $response = $this->deleteJson("/api/v1/taxes/{$tax->id}");

        $response->assertStatus(403);
    }
}
