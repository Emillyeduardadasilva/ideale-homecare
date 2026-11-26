<?php

namespace Tests\Feature;

use App\Models\CareMembership;
use App\Models\Elder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoleViewsTest extends TestCase
{
    use RefreshDatabase;

    private function familyWithElder(string $name): array
    {
        $family = User::factory()->create(['role' => 'family']);
        $elder = Elder::create([
            'name' => $name,
            'birth_date' => '1940-01-01',
            'timezone' => 'America/Cuiaba',
            'created_by' => $family->id,
        ]);
        CareMembership::create(['elder_id' => $elder->id, 'user_id' => $family->id, 'role' => 'manager']);

        return [$family, $elder];
    }

    public function test_system_admin_can_consult_all_networks_manage_account_status_and_cannot_write_care_records(): void
    {
        [$family, $firstElder] = $this->familyWithElder('Idoso A');
        [, $secondElder] = $this->familyWithElder('Idoso B');
        $admin = User::factory()->create(['role' => 'system_admin']);

        $this->actingAs($admin)->getJson('/api/v1/elders')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/elders/$secondElder->id/daily-view")->assertOk();
        $this->getJson('/api/v1/admin/overview')->assertOk()->assertJsonPath('data.elders', 2);
        $this->getJson('/api/v1/admin/users')->assertOk()->assertJsonCount(3, 'data');
        $this->patchJson("/api/v1/admin/users/$family->id/active", ['active' => false])
            ->assertOk()->assertJsonPath('data.active', false);
        $this->patchJson("/api/v1/admin/users/$admin->id/active", ['active' => false])->assertUnprocessable();

        $this->postJson("/api/v1/elders/$firstElder->id/tasks", [
            'title' => 'Tarefa bloqueada para administrador',
            'due_at' => now()->addHour()->toIso8601String(),
            'assignees' => [$family->id],
        ])->assertForbidden();

        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'action' => 'system_admin.care_record_viewed', 'elder_id' => $secondElder->id]);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'action' => 'system_admin.account_status_changed', 'entity_id' => $family->id]);
        $this->assertSame(0, DB::table('tasks')->count());
    }

    public function test_family_cannot_open_global_admin_and_caregiver_sees_only_invited_elder(): void
    {
        $family = User::factory()->create(['role' => 'family']);
        [$ownerA, $elderA] = $this->familyWithElder('Idoso vinculado');
        [, $elderB] = $this->familyWithElder('Idoso de outra rede');
        $caregiver = User::factory()->create(['role' => 'ideale_professional']);
        CareMembership::create(['elder_id' => $elderA->id, 'user_id' => $caregiver->id, 'role' => 'team']);

        $this->actingAs($family)->getJson('/api/v1/admin/overview')->assertForbidden();
        $this->actingAs($caregiver)->getJson('/api/v1/admin/users')->assertForbidden();
        $this->getJson('/api/v1/elders')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $elderA->id);
        $this->getJson("/api/v1/elders/$elderA->id/daily-view")->assertOk();
        $this->getJson("/api/v1/elders/$elderB->id/daily-view")->assertForbidden();

        $this->actingAs($ownerA)->getJson('/api/v1/elders')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $elderA->id);
    }
}
