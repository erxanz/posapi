<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\Schedule;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ShiftControllerTest extends TestCase
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
    public function manager_can_list_shifts()
    {
        Shift::factory()->count(2)->create(['outlet_id' => $this->outlet->id]);

        $this->actingAs($this->owner);
        $response = $this->getJson('/api/v1/shifts');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    #[Test]
    public function manager_only_sees_own_outlet_shifts()
    {
        Shift::factory()->create(['outlet_id' => $this->outlet->id, 'name' => 'My Shift']);
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $otherOutlet = Outlet::factory()->create(['owner_id' => $otherOwner->id]);
        Shift::factory()->create(['outlet_id' => $otherOutlet->id, 'name' => 'Other Shift']);

        $this->actingAs($this->owner);
        $response = $this->getJson('/api/v1/shifts');

        $this->assertCount(1, $response->json('data'));
    }

    #[Test]
    public function karyawan_sees_own_outlet_shifts()
    {
        Shift::factory()->create(['outlet_id' => $this->outlet->id]);

        $this->actingAs($this->karyawan);
        $response = $this->getJson('/api/v1/shifts');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    // =========================================================================
    // STORE
    // =========================================================================

    #[Test]
    public function manager_can_create_shift()
    {
        $this->actingAs($this->owner);
        $response = $this->postJson('/api/v1/shifts', [
            'outlet_id' => $this->outlet->id,
            'name' => 'Shift Pagi',
            'start_time' => '07:00',
            'end_time' => '15:00',
            'color' => '#FF0000',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('shifts', [
            'name' => 'Shift Pagi',
            'outlet_id' => $this->outlet->id,
        ]);
    }

    #[Test]
    public function karyawan_cannot_create_shift()
    {
        $this->actingAs($this->karyawan);
        $response = $this->postJson('/api/v1/shifts', [
            'outlet_id' => $this->outlet->id,
            'name' => 'Hacked Shift',
            'start_time' => '07:00',
            'end_time' => '15:00',
        ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function create_shift_requires_valid_time_format()
    {
        $this->actingAs($this->owner);
        $response = $this->postJson('/api/v1/shifts', [
            'outlet_id' => $this->outlet->id,
            'name' => 'Bad Format',
            'start_time' => 'invalid',
            'end_time' => '15:00',
        ]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // UPDATE
    // =========================================================================

    #[Test]
    public function manager_can_update_shift()
    {
        $shift = Shift::factory()->create([
            'outlet_id' => $this->outlet->id,
            'name' => 'Old Shift',
            'start_time' => '07:00',
            'end_time' => '15:00',
        ]);

        $this->actingAs($this->owner);
        $response = $this->putJson("/api/v1/shifts/{$shift->id}", [
            'outlet_id' => $this->outlet->id,
            'name' => 'New Shift',
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('New Shift', $shift->fresh()->name);
    }

    #[Test]
    public function manager_cannot_update_other_outlet_shift()
    {
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $otherOutlet = Outlet::factory()->create(['owner_id' => $otherOwner->id]);
        $shift = Shift::factory()->create(['outlet_id' => $otherOutlet->id]);

        $this->actingAs($this->owner);
        $response = $this->putJson("/api/v1/shifts/{$shift->id}", [
            'outlet_id' => $otherOutlet->id,
            'name' => 'Hacked',
            'start_time' => '07:00',
            'end_time' => '15:00',
        ]);

        $response->assertStatus(403);
    }

    // =========================================================================
    // DESTROY
    // =========================================================================

    #[Test]
    public function manager_can_delete_shift()
    {
        $shift = Shift::factory()->create(['outlet_id' => $this->outlet->id]);

        $this->actingAs($this->owner);
        $response = $this->deleteJson("/api/v1/shifts/{$shift->id}");

        $response->assertStatus(200);
        $this->assertModelMissing($shift);
    }

    #[Test]
    public function karyawan_cannot_delete_shift()
    {
        $shift = Shift::factory()->create(['outlet_id' => $this->outlet->id]);

        $this->actingAs($this->karyawan);
        $response = $this->deleteJson("/api/v1/shifts/{$shift->id}");

        $response->assertStatus(403);
    }

    // =========================================================================
    // AUTO GENERATE
    // =========================================================================

    #[Test]
    public function auto_generate_requires_shifts_and_karyawans()
    {
        $this->actingAs($this->owner);

        // No shifts created yet
        $response = $this->postJson('/api/v1/shifts/auto-generate', [
            'outlet_id' => $this->outlet->id,
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('message', 'Belum ada master shift');
    }

    #[Test]
    public function auto_generate_creates_schedules()
    {
        Shift::factory()->pagi()->create(['outlet_id' => $this->outlet->id]);
        Shift::factory()->malam()->create(['outlet_id' => $this->outlet->id]);

        User::factory()->count(3)->create([
            'role' => 'karyawan',
            'outlet_id' => $this->outlet->id,
        ]);

        $this->actingAs($this->owner);
        $response = $this->postJson('/api/v1/shifts/auto-generate', [
            'outlet_id' => $this->outlet->id,
            'month' => now()->month,
            'year' => now()->year,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Jadwal berhasil digenerate');

        $scheduleCount = Schedule::where('outlet_id', $this->outlet->id)->count();
        $this->assertGreaterThan(0, $scheduleCount);
    }

    // =========================================================================
    // MY SCHEDULE
    // =========================================================================

    #[Test]
    public function karyawan_can_view_my_schedule()
    {
        $shift = Shift::factory()->pagi()->create(['outlet_id' => $this->outlet->id]);
        Schedule::create([
            'outlet_id' => $this->outlet->id,
            'shift_id' => $shift->id,
            'user_id' => $this->karyawan->id,
            'date' => now()->toDateString(),
        ]);

        $this->actingAs($this->karyawan);
        $response = $this->getJson('/api/v1/shifts/my-schedule');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    #[Test]
    public function my_schedule_returns_empty_when_no_schedule()
    {
        $this->actingAs($this->karyawan);
        $response = $this->getJson('/api/v1/shifts/my-schedule');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }
}
