<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Outlet;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UserControllerTest extends TestCase
{
    use RefreshDatabase;
    private User $developer;
    private User $manager;
    private User $karyawan;
    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->developer = User::factory()->create(['role' => 'developer']);
        $this->manager = User::factory()->create(['role' => 'manager']);
        $this->outlet = Outlet::factory()->create(['owner_id' => $this->manager->id]);
        $this->karyawan = User::factory()->create([
            'role' => 'karyawan',
            'outlet_id' => $this->outlet->id,
        ]);
    }

    // =========================================================================
    // LIST USERS
    // =========================================================================

    #[Test]
    public function manager_can_list_their_karyawan()
    {
        // Create another karyawan in the same outlet
        User::factory()->create([
            'role' => 'karyawan',
            'outlet_id' => $this->outlet->id,
        ]);

        $this->actingAs($this->manager);
        $response = $this->getJson('/api/v1/users');

        $response->assertStatus(200);
        $response->assertJsonStructure(['success', 'data']);

        // Manager should see only karyawan
        $users = $response->json('data.data');
        foreach ($users as $u) {
            $this->assertEquals('karyawan', $u['role']);
        }
    }

    #[Test]
    public function developer_can_list_all_users()
    {
        $this->actingAs($this->developer);
        $response = $this->getJson('/api/v1/users');

        $response->assertStatus(200);
        $response->assertJsonStructure(['success', 'data']);
    }

    #[Test]
    public function karyawan_cannot_list_users()
    {
        $this->actingAs($this->karyawan);
        $response = $this->getJson('/api/v1/users');

        $response->assertStatus(403);
    }

    #[Test]
    public function users_can_be_filtered_by_search()
    {
        User::factory()->create([
            'name' => 'Ahmad Karyawan',
            'role' => 'karyawan',
            'outlet_id' => $this->outlet->id,
        ]);

        $this->actingAs($this->manager);
        $response = $this->getJson('/api/v1/users?search=Ahmad');

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, count($response->json('data.data')));
    }

    // =========================================================================
    // SHOW USER
    // =========================================================================

    #[Test]
    public function manager_can_view_karyawan_detail()
    {
        $this->actingAs($this->manager);
        $response = $this->getJson("/api/v1/users/{$this->karyawan->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $this->karyawan->id);
    }

    #[Test]
    public function manager_cannot_view_other_owner_karyawan()
    {
        $otherManager = User::factory()->create(['role' => 'manager']);
        $otherOutlet = Outlet::factory()->create(['owner_id' => $otherManager->id]);
        $otherKaryawan = User::factory()->create([
            'role' => 'karyawan',
            'outlet_id' => $otherOutlet->id,
        ]);

        $this->actingAs($this->manager);
        $response = $this->getJson("/api/v1/users/{$otherKaryawan->id}");

        $response->assertStatus(403);
    }

    #[Test]
    public function developer_can_view_any_user()
    {
        $this->actingAs($this->developer);
        $response = $this->getJson("/api/v1/users/{$this->karyawan->id}");

        $response->assertStatus(200);
    }

    // =========================================================================
    // CREATE USER
    // =========================================================================

    #[Test]
    public function manager_can_create_karyawan()
    {
        $this->actingAs($this->manager);

        $response = $this->postJson('/api/v1/users', [
            'name' => 'Karyawan Baru',
            'email' => 'karyawan.baru@example.com',
            'role' => 'karyawan',
            'outlet_id' => $this->outlet->id,
            'pin' => '654321',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'User berhasil dibuat');

        $this->assertDatabaseHas('users', ['email' => 'karyawan.baru@example.com']);
    }

    #[Test]
    public function manager_cannot_create_user_in_unowned_outlet()
    {
        $otherManager = User::factory()->create(['role' => 'manager']);
        $otherOutlet = Outlet::factory()->create(['owner_id' => $otherManager->id]);

        $this->actingAs($this->manager);
        $response = $this->postJson('/api/v1/users', [
            'name' => 'Karyawan',
            'email' => 'karyawan@example.com',
            'role' => 'karyawan',
            'outlet_id' => $otherOutlet->id,
            'pin' => '654321',
        ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function developer_can_create_any_role()
    {
        $this->actingAs($this->developer);

        $response = $this->postJson('/api/v1/users', [
            'name' => 'New Manager',
            'email' => 'manager.baru@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'manager',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', ['email' => 'manager.baru@example.com']);
    }

    #[Test]
    public function create_user_requires_valid_email()
    {
        $this->actingAs($this->developer);

        $response = $this->postJson('/api/v1/users', [
            'name' => 'Test',
            'email' => 'invalid',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'manager',
        ]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // UPDATE USER
    // =========================================================================

    #[Test]
    public function manager_can_update_karyawan()
    {
        $this->actingAs($this->manager);

        $response = $this->putJson("/api/v1/users/{$this->karyawan->id}", [
            'name' => 'Updated Name',
            'email' => $this->karyawan->email,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Data User berhasil diperbarui');

        $this->assertEquals('Updated Name', $this->karyawan->fresh()->name);
    }

    #[Test]
    public function manager_cannot_update_non_karyawan()
    {
        $otherManager = User::factory()->create(['role' => 'manager']);

        $this->actingAs($this->manager);
        $response = $this->putJson("/api/v1/users/{$otherManager->id}", [
            'name' => 'Hacked',
            'email' => $otherManager->email,
        ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function developer_can_update_any_user()
    {
        $this->actingAs($this->developer);

        $response = $this->putJson("/api/v1/users/{$this->karyawan->id}", [
            'name' => 'Dev Updated',
            'email' => $this->karyawan->email,
            'role' => 'karyawan',
            'outlet_id' => $this->karyawan->outlet_id,
        ]);

        $response->assertStatus(200);
        $this->assertEquals('Dev Updated', $this->karyawan->fresh()->name);
    }

    #[Test]
    public function update_user_can_change_pin()
    {
        $this->actingAs($this->manager);

        $response = $this->putJson("/api/v1/users/{$this->karyawan->id}", [
            'name' => $this->karyawan->name,
            'email' => $this->karyawan->email,
            'pin' => '999999',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('999999', $this->karyawan->fresh()->pin);
    }

    // =========================================================================
    // DELETE USER
    // =========================================================================

    #[Test]
    public function manager_can_delete_karyawan()
    {
        $this->actingAs($this->manager);
        $response = $this->deleteJson("/api/v1/users/{$this->karyawan->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'User berhasil dihapus');

        $this->assertDatabaseMissing('users', ['id' => $this->karyawan->id]);
    }

    #[Test]
    public function manager_cannot_delete_non_karyawan()
    {
        $this->actingAs($this->manager);
        $response = $this->deleteJson("/api/v1/users/{$this->developer->id}");

        $response->assertStatus(403);
    }

    #[Test]
    public function karyawan_cannot_delete_users()
    {
        $this->actingAs($this->karyawan);
        $response = $this->deleteJson("/api/v1/users/{$this->karyawan->id}");

        $response->assertStatus(403);
    }
}
