<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\Schedule;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ScheduleControllerTest extends TestCase
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
        $this->shift = Shift::factory()->pagi()->create(['outlet_id' => $this->outlet->id]);
        $this->karyawan = User::factory()->create([
            'role' => 'karyawan',
            'outlet_id' => $this->outlet->id,
        ]);
    }

    // =========================================================================
    // INDEX
    // =========================================================================

    #[Test]
    public function manager_can_list_schedules()
    {
        Schedule::create([
            'outlet_id' => $this->outlet->id,
            'shift_id' => $this->shift->id,
            'user_id' => $this->karyawan->id,
            'date' => now()->toDateString(),
        ]);

        $this->actingAs($this->owner);
        $response = $this->getJson('/api/v1/schedules?' . http_build_query([
            'start_date' => now()->subDays(7)->format('Y-m-d'),
            'end_date' => now()->addDays(7)->format('Y-m-d'),
        ]));

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('period.start', now()->subDays(7)->format('Y-m-d'));
    }

    #[Test]
    public function karyawan_only_sees_own_schedules()
    {
        Schedule::create([
            'outlet_id' => $this->outlet->id,
            'shift_id' => $this->shift->id,
            'user_id' => $this->karyawan->id,
            'date' => now()->toDateString(),
        ]);

        $otherKaryawan = User::factory()->create(['role' => 'karyawan', 'outlet_id' => $this->outlet->id]);
        Schedule::create([
            'outlet_id' => $this->outlet->id,
            'shift_id' => $this->shift->id,
            'user_id' => $otherKaryawan->id,
            'date' => now()->toDateString(),
        ]);

        $this->actingAs($this->karyawan);
        $response = $this->getJson('/api/v1/schedules?' . http_build_query([
            'start_date' => now()->subDays(7)->format('Y-m-d'),
            'end_date' => now()->addDays(7)->format('Y-m-d'),
        ]));

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    #[Test]
    public function schedule_index_requires_valid_date_range()
    {
        $this->actingAs($this->owner);
        $response = $this->getJson('/api/v1/schedules');

        $response->assertStatus(422);
    }

    #[Test]
    public function schedule_index_can_filter_by_outlet()
    {
        $otherOutlet = Outlet::factory()->create(['owner_id' => $this->owner->id]);
        $otherShift = Shift::factory()->pagi()->create(['outlet_id' => $otherOutlet->id]);

        Schedule::create([
            'outlet_id' => $this->outlet->id,
            'shift_id' => $this->shift->id,
            'user_id' => $this->karyawan->id,
            'date' => now()->toDateString(),
        ]);
        Schedule::create([
            'outlet_id' => $otherOutlet->id,
            'shift_id' => $otherShift->id,
            'user_id' => $this->karyawan->id,
            'date' => now()->toDateString(),
        ]);

        $this->actingAs($this->owner);
        $response = $this->getJson('/api/v1/schedules?' . http_build_query([
            'start_date' => now()->subDays(7)->format('Y-m-d'),
            'end_date' => now()->addDays(7)->format('Y-m-d'),
            'outlet_id' => $this->outlet->id,
        ]));

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    // =========================================================================
    // STORE
    // =========================================================================

    #[Test]
    public function manager_can_create_schedule()
    {
        $this->actingAs($this->owner);
        $response = $this->postJson('/api/v1/schedules', [
            'outlet_id' => $this->outlet->id,
            'shift_id' => $this->shift->id,
            'date' => now()->addDays(1)->format('Y-m-d'),
            'user_ids' => [$this->karyawan->id],
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('shift_schedules', [
            'outlet_id' => $this->outlet->id,
            'user_id' => $this->karyawan->id,
        ]);
    }

    #[Test]
    public function karyawan_cannot_create_schedule()
    {
        $this->actingAs($this->karyawan);
        $response = $this->postJson('/api/v1/schedules', [
            'outlet_id' => $this->outlet->id,
            'shift_id' => $this->shift->id,
            'date' => now()->addDays(1)->format('Y-m-d'),
            'user_ids' => [$this->karyawan->id],
        ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function create_schedule_rejects_conflicting_entries()
    {
        Schedule::create([
            'outlet_id' => $this->outlet->id,
            'shift_id' => $this->shift->id,
            'user_id' => $this->karyawan->id,
            'date' => now()->addDays(1)->format('Y-m-d'),
        ]);

        $this->actingAs($this->owner);
        $response = $this->postJson('/api/v1/schedules', [
            'outlet_id' => $this->outlet->id,
            'shift_id' => $this->shift->id,
            'date' => now()->addDays(1)->format('Y-m-d'),
            'user_ids' => [$this->karyawan->id],
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('message', 'Karyawan sudah memiliki jadwal lain di tanggal ini.');
    }

    // =========================================================================
    // DESTROY
    // =========================================================================

    #[Test]
    public function manager_can_delete_schedule()
    {
        $schedule = Schedule::create([
            'outlet_id' => $this->outlet->id,
            'shift_id' => $this->shift->id,
            'user_id' => $this->karyawan->id,
            'date' => now()->toDateString(),
        ]);

        $this->actingAs($this->owner);
        $response = $this->deleteJson("/api/v1/schedules/{$schedule->id}");

        $response->assertStatus(200);
        $this->assertModelMissing($schedule);
    }

    #[Test]
    public function manager_cannot_delete_other_outlet_schedule()
    {
        $otherOwner = User::factory()->create(['role' => 'manager']);
        $otherOutlet = Outlet::factory()->create(['owner_id' => $otherOwner->id]);
        $otherShift = Shift::factory()->pagi()->create(['outlet_id' => $otherOutlet->id]);
        $schedule = Schedule::create([
            'outlet_id' => $otherOutlet->id,
            'shift_id' => $otherShift->id,
            'user_id' => $this->karyawan->id,
            'date' => now()->toDateString(),
        ]);

        $this->actingAs($this->owner);
        $response = $this->deleteJson("/api/v1/schedules/{$schedule->id}");

        $response->assertStatus(403);
    }

    #[Test]
    public function karyawan_cannot_delete_other_schedule()
    {
        $otherKaryawan = User::factory()->create([
            'role' => 'karyawan',
            'outlet_id' => $this->outlet->id,
        ]);

        $schedule = Schedule::create([
            'outlet_id' => $this->outlet->id,
            'shift_id' => $this->shift->id,
            'user_id' => $otherKaryawan->id,
            'date' => now()->toDateString(),
        ]);

        $this->actingAs($this->karyawan);
        $response = $this->deleteJson("/api/v1/schedules/{$schedule->id}");

        $response->assertStatus(403);
    }
}
