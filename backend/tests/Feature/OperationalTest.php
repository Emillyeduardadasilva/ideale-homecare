<?php

namespace Tests\Feature;

use App\Models\CareMembership;
use App\Models\Elder;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class OperationalTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_and_authentication_boundary(): void
    {
        $this->get('/up')->assertOk();
        $this->getJson('/api/v1/elders')->assertUnauthorized();
        $this->getJson('/api/v1/elders', ['Authorization' => 'Bearer 1|invalid-token'])->assertUnauthorized();
    }

    public function test_idempotency_key_replays_creation_but_rejects_changed_input(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u);
        $body = ['name' => 'Pessoa', 'birth_date' => '1940-01-01', 'timezone' => 'America/Cuiaba'];
        $headers = ['Idempotency-Key' => 'sample-request-key-1234'];
        $id = $this->postJson('/api/v1/elders', $body, $headers)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/elders', $body, $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/elders', [...$body, 'name' => 'Outro'], $headers)->assertConflict();
        $this->assertDatabaseCount('elders', 1);
    }

    public function test_expired_idempotency_key_can_be_reused_without_scheduler_cleanup(): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = User::factory()->create();
        $this->actingAs($user);
        $body = ['name' => 'Primeiro cadastro', 'birth_date' => '1940-01-01', 'timezone' => 'America/Cuiaba'];
        $headers = ['Idempotency-Key' => 'expired-request-key-1234'];
        $firstId = $this->postJson('/api/v1/elders', $body, $headers)->assertCreated()->json('data.id');

        $this->travelTo(now()->addDay());
        $body['name'] = 'Novo cadastro';
        $secondId = $this->postJson('/api/v1/elders', $body, $headers)->assertCreated()->assertHeaderMissing('Idempotency-Replayed')->json('data.id');

        $this->assertNotSame($firstId, $secondId);
        $this->postJson('/api/v1/elders', $body, $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $secondId);
        $this->assertDatabaseCount('elders', 2);
        $this->assertDatabaseCount('idempotency_requests', 1);
    }

    public function test_status_exposes_overdue_reminder_metrics_without_clinical_contents(): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = User::factory()->create();
        $elder = Elder::create(['name' => 'Informação privada', 'birth_date' => '1940-01-01', 'created_by' => $user->id]);
        Reminder::create(['elder_id' => $elder->id, 'source_type' => 'visit', 'source_id' => 1, 'source_version' => 1, 'due_at' => now()->subMinutes(3)]);

        $this->assertSame(0, Artisan::call('care:status', ['--json' => true]));
        $output = Artisan::output();
        $metrics = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $metrics['pending_reminders']);
        $this->assertSame(1, $metrics['overdue_reminders']);
        $this->assertSame(180, $metrics['oldest_reminder_delay_seconds']);
        $this->assertSame(0, $metrics['queued_jobs']);
        $this->assertSame(0, $metrics['failed_push_deliveries']);
        $this->assertStringNotContainsString($elder->name, $output);
    }

    public function test_elder_can_be_inactivated_and_reactivated_without_losing_history(): void
    {
        $u = User::factory()->create();
        $e = Elder::create(['name' => 'Pessoa', 'birth_date' => '1940-01-01', 'created_by' => $u->id, 'timezone' => 'America/Cuiaba']);
        CareMembership::create(['elder_id' => $e->id, 'user_id' => $u->id, 'role' => 'manager']);
        $body = ['name' => 'Pessoa', 'birth_date' => '1940-01-01', 'timezone' => 'America/Cuiaba', 'active' => false, 'version' => 1];
        $this->actingAs($u)->putJson("/api/v1/elders/$e->id", $body)->assertOk();
        $this->postJson("/api/v1/elders/$e->id/observations", ['text' => 'Bloqueado', 'occurred_at' => now()->toIso8601String()])->assertForbidden();
        $this->putJson("/api/v1/elders/$e->id", [...$body, 'active' => true, 'version' => 2])->assertOk();
    }
}
