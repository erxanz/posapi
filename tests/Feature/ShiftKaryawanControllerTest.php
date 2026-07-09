<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\ShiftKaryawan;
use App\Models\Schedule;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ShiftKaryawanControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $karyawan;
    private Outlet $outlet;
    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'manager']);
        $this->outlet = Outlet::factory()->create(['owner_id' => $this->owner->id]);
        $this->karyawan = User::factory()->create([
            'role' => 'karyawan',
            'outlet_id' => $this->outlet->id,
        ]);
        $this->shift = Shift::factory()->pagi()->create(['outlet_id' => $this->outlet->id]);

        // Create schedule for today so karyawan can start shift
        Schedule::create([
            'outlet_id' => $this->outlet->id,
            'shift_id' => $this->shift->id,
            'user_id' => $this->karyawan->id,
            'date' => now()->toDateString(),
        ]);
    }

    // =========================================================================
    // CHECK STATUS
    // =========================================================================

    #[Test]
    public function check_status_returns_false_when_no_active_shift()
    {
        $this->actingAs($this->karyawan);
        $response = $this->getJson('/api/v1/shift-karyawans/check-status');

        $response->assertStatus(200);
        $response->assertJsonPath('success', false);
    }

    // =========================================================================
    // START SHIFT
    // =========================================================================

    #[Test]
    public function karyawan_can_start_shift()
    {
        $this->actingAs($this->karyawan);
        $response = $this->postJson('/api/v1/shift-karyawans/start', [
            'outlet_id' => $this->outlet->id,
            'opening_balance' => 200000,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('shift_karyawans', [
            'user_id' => $this->karyawan->id,
            'status' => 'active',
            'opening_balance' => 200000,
        ]);
    }

    #[Test]
    public function cannot_start_shift_when_already_active()
    {
        ShiftKaryawan::factory()->create([
            'user_id' => $this->karyawan->id,
            'outlet_id' => $this->outlet->id,
            'shift_id' => $this->shift->id,
            'status' => 'active',
        ]);

        $this->actingAs($this->karyawan);
        $response = $this->postJson('/api/v1/shift-karyawans/start', [
            'outlet_id' => $this->outlet->id,
            'opening_balance' => 150000,
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('message', 'Anda memiliki shift aktif yang belum ditutup');
    }

    #[Test]
    public function start_shift_requires_opening_balance()
    {
        $this->actingAs($this->karyawan);
        $response = $this->postJson('/api/v1/shift-karyawans/start', [
            'outlet_id' => $this->outlet->id,
        ]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // END SHIFT
    // =========================================================================

    #[Test]
    public function karyawan_can_end_shift()
    {
        ShiftKaryawan::factory()->create([
            'user_id' => $this->karyawan->id,
            'outlet_id' => $this->outlet->id,
            'shift_id' => $this->shift->id,
            'status' => 'active',
            'opening_balance' => 200000,
        ]);

        $this->actingAs($this->karyawan);
        $response = $this->postJson('/api/v1/shift-karyawans/end', [
            'actual_closing_balance' => 250000,
            'notes' => 'Shift selesai',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('shift_karyawans', [
            'user_id' => $this->karyawan->id,
            'status' => 'closed',
        ]);
    }

    #[Test]
    public function end_shift_fails_when_no_active_shift()
    {
        $this->actingAs($this->karyawan);
        $response = $this->postJson('/api/v1/shift-karyawans/end', [
            'actual_closing_balance' => 250000,
        ]);

        $response->assertStatus(404);
    }

    // =========================================================================
    // INDEX (Manager sees all, Karyawan sees own)
    // =========================================================================

    #[Test]
    public function manager_can_list_shift_karyawans()
    {
        ShiftKaryawan::factory()->count(2)->create([
            'outlet_id' => $this->outlet->id,
            'user_id' => $this->karyawan->id,
            'shift_id' => $this->shift->id,
        ]);

        $this->actingAs($this->owner);
        $response = $this->getJson('/api/v1/shift-karyawans');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    #[Test]
    public function karyawan_only_sees_own_shifts()
    {
        ShiftKaryawan::factory()->create([
            'user_id' => $this->karyawan->id,
            'outlet_id' => $this->outlet->id,
            'shift_id' => $this->shift->id,
        ]);

        $otherKaryawan = User::factory()->create(['role' => 'karyawan', 'outlet_id' => $this->outlet->id]);
        ShiftKaryawan::factory()->create([
            'user_id' => $otherKaryawan->id,
            'outlet_id' => $this->outlet->id,
            'shift_id' => $this->shift->id,
        ]);

        $this->actingAs($this->karyawan);
        $response = $this->getJson('/api/v1/shift-karyawans');

        $this->assertCount(1, $response->json('data'));
    }

    // =========================================================================
    // SHOW
    // =========================================================================

    #[Test]
    public function karyawan_can_view_own_shift()
    {
        $sk = ShiftKaryawan::factory()->create([
            'user_id' => $this->karyawan->id,
            'outlet_id' => $this->outlet->id,
            'shift_id' => $this->shift->id,
        ]);

        $this->actingAs($this->karyawan);
        $response = $this->getJson("/api/v1/shift-karyawans/{$sk->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.user_id', $this->karyawan->id);
    }

    #[Test]
    public function karyawan_cannot_view_other_shift()
    {
        $otherKaryawan = User::factory()->create(['role' => 'karyawan', 'outlet_id' => $this->outlet->id]);
        $sk = ShiftKaryawan::factory()->create([
            'user_id' => $otherKaryawan->id,
            'outlet_id' => $this->outlet->id,
            'shift_id' => $this->shift->id,
        ]);

        $this->actingAs($this->karyawan);
        $response = $this->getJson("/api/v1/shift-karyawans/{$sk->id}");

        $response->assertStatus(403);
    }

    // =========================================================================
    // RESOLVE (Manager verification)
    // =========================================================================

    #[Test]
    public function manager_can_resolve_auto_closed_shift()
    {
        $sk = ShiftKaryawan::factory()->create([
            'user_id' => $this->karyawan->id,
            'outlet_id' => $this->outlet->id,
            'shift_id' => $this->shift->id,
            'status' => 'closed',
            'opening_balance' => 200000,
            'closing_balance_system' => null,
            'closing_balance_actual' => null,
        ]);

        $this->actingAs($this->owner);
        $response = $this->putJson("/api/v1/shift-karyawans/{$sk->id}/resolve", [
            'actual_closing_balance' => 300000,
        ]);

        $response->assertStatus(200);
        $sk->refresh();
        $this->assertEquals(300000, $sk->closing_balance_actual);
        $this->assertNotNull($sk->closing_balance_system);
        $this->assertNotNull($sk->difference);
    }

    #[Test]
    public function karyawan_cannot_resolve_shift()
    {
        $sk = ShiftKaryawan::factory()->create([
            'user_id' => $this->karyawan->id,
            'outlet_id' => $this->outlet->id,
            'shift_id' => $this->shift->id,
            'status' => 'closed',
        ]);

        $this->actingAs($this->karyawan);
        $response = $this->putJson("/api/v1/shift-karyawans/{$sk->id}/resolve", [
            'actual_closing_balance' => 300000,
        ]);

        $response->assertStatus(403);
    }

    // =========================================================================
    // DESTROY
    // =========================================================================

    #[Test]
    public function manager_can_delete_shift_karyawan()
    {
        $sk = ShiftKaryawan::factory()->create([
            'user_id' => $this->karyawan->id,
            'outlet_id' => $this->outlet->id,
            'shift_id' => $this->shift->id,
        ]);

        $this->actingAs($this->owner);
        $response = $this->deleteJson("/api/v1/shift-karyawans/{$sk->id}");

        $response->assertStatus(200);
        $this->assertModelMissing($sk);
    }

    #[Test]
    public function karyawan_cannot_delete_shift_karyawan()
    {
        $sk = ShiftKaryawan::factory()->create([
            'user_id' => $this->karyawan->id,
            'outlet_id' => $this->outlet->id,
            'shift_id' => $this->shift->id,
        ]);

        $this->actingAs($this->karyawan);
        $response = $this->deleteJson("/api/v1/shift-karyawans/{$sk->id}");

        $response->assertStatus(403);
    }
}
