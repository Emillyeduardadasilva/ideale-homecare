<?php

namespace Tests\Feature;

use App\Models\CareMembership;
use App\Models\Elder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CareWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function network(): array
    {
        $manager = User::factory()->create();
        $elder = Elder::create(['name' => 'Pessoa acompanhada', 'birth_date' => '1940-01-01', 'timezone' => 'America/Cuiaba', 'created_by' => $manager->id]);
        CareMembership::create(['elder_id' => $elder->id, 'user_id' => $manager->id, 'role' => 'manager']);

        return [$manager, $elder];
    }

    public function test_daily_view_respects_ownership_local_date_and_allowed_actions(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 6)->setTime(2, 0));
        [$manager, $elder] = $this->network();
        $owner = User::factory()->create(['role' => 'elder']);
        $elder->update(['user_id' => $owner->id]);
        $task = Task::create(['elder_id' => $elder->id, 'created_by' => $manager->id, 'title' => 'Atividade do dia', 'due_at' => now()->addMinutes(30)]);
        $task->assignees()->sync([$manager->id]);

        $this->actingAs($owner)->getJson("/api/v1/elders/$elder->id/daily-view")->assertOk()
            ->assertJsonPath('data.date', '2026-11-05')->assertJsonPath('data.items.0.allowed_actions', ['complete'])->assertJsonPath('data.quick_call', null);
        $this->postJson("/api/v1/elders/$elder->id/tasks/$task->id/complete")->assertOk();
        $this->getJson("/api/v1/elders/$elder->id/daily-view")->assertJsonPath('data.items.0.allowed_actions', []);
        $this->actingAs(User::factory()->create())->getJson("/api/v1/elders/$elder->id/daily-view")->assertForbidden();
        $this->actingAs($manager)->getJson("/api/v1/elders/$elder->id/daily-view")->assertOk();
        $elder->update(['active' => false]);
        $this->getJson("/api/v1/elders/$elder->id/daily-view")->assertJsonPath('data.items.0.allowed_actions', []);
    }

    public function test_shifts_can_be_corrected_and_cancelled_without_losing_history(): void
    {
        [$manager, $elder] = $this->network();
        $this->actingAs($manager);
        $contact = $this->postJson("/api/v1/elders/$elder->id/contacts", ['name' => 'Responsável', 'phone' => '+5565999990001', 'type' => 'family', 'priority' => 1])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/elders/$elder->id/contacts", ['name' => 'Central', 'phone' => '+5565999990002', 'type' => 'central', 'priority' => 2])->assertCreated();
        $data = ['emergency_contact_id' => $contact, 'starts_at' => now()->subHour()->toIso8601String(), 'ends_at' => now()->addHour()->toIso8601String()];
        $id = $this->postJson("/api/v1/elders/$elder->id/care-shifts", $data)->assertCreated()->assertJsonPath('data.version', 1)->json('data.id');
        $this->putJson("/api/v1/elders/$elder->id/care-shifts/$id", [...$data, 'ends_at' => now()->addHours(2)->toIso8601String(), 'version' => 1])->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson("/api/v1/elders/$elder->id/care-shifts/$id", [...$data, 'version' => 1])->assertConflict()->assertJsonPath('error.code', 'STALE_RESOURCE');
        $this->deleteJson("/api/v1/elders/$elder->id/care-shifts/$id", ['version' => 2])->assertNoContent();
        $this->getJson("/api/v1/elders/$elder->id/quick-call")->assertJsonPath('data.type', 'central');
        $this->assertDatabaseHas('care_shifts', ['id' => $id, 'active' => false, 'version' => 3]);
        $this->postJson("/api/v1/elders/$elder->id/care-shifts", $data)->assertCreated();
    }

    public function test_shift_edits_cannot_escape_the_elder_scope(): void
    {
        [$manager, $elder] = $this->network();
        [$otherManager, $otherElder] = $this->network();
        $this->actingAs($otherManager);
        $contact = $this->postJson("/api/v1/elders/$otherElder->id/contacts", ['name' => 'Responsável', 'phone' => '+5565999990001', 'type' => 'family', 'priority' => 1])->assertCreated()->json('data.id');
        $data = ['emergency_contact_id' => $contact, 'starts_at' => now()->subHour()->toIso8601String(), 'ends_at' => now()->addHour()->toIso8601String()];
        $id = $this->postJson("/api/v1/elders/$otherElder->id/care-shifts", $data)->assertCreated()->json('data.id');
        $this->actingAs($manager)->deleteJson("/api/v1/elders/$elder->id/care-shifts/$id", ['version' => 1])->assertNotFound();
        $this->assertDatabaseHas('care_shifts', ['id' => $id, 'active' => true]);
    }

    public function test_revocation_invalidates_other_pending_invitations_for_the_same_person(): void
    {
        [$manager, $elder] = $this->network();
        $relative = User::factory()->create();
        $tokens = [];
        for ($i = 0; $i < 2; $i++) {
            $tokens[] = $this->actingAs($manager)->postJson("/api/v1/elders/$elder->id/invitations", ['email' => $relative->email, 'role' => 'relative'])->assertCreated()->json('invitation_token');
        }
        $this->getJson("/api/v1/elders/$elder->id/invitations")->assertOk()->assertJsonCount(2, 'data')->assertJsonMissingPath('data.0.token_hash');
        $this->actingAs($relative)->postJson('/api/v1/invitations/accept', ['token' => $tokens[0]])->assertOk();
        $membership = CareMembership::where('elder_id', $elder->id)->where('user_id', $relative->id)->firstOrFail();
        $this->actingAs($manager)->deleteJson("/api/v1/elders/$elder->id/memberships/$membership->id")->assertNoContent();
        $this->actingAs($relative)->postJson('/api/v1/invitations/accept', ['token' => $tokens[1]])->assertStatus(410);
        $this->getJson("/api/v1/elders/$elder->id")->assertForbidden();
    }
}
