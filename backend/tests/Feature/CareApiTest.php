<?php

namespace Tests\Feature;

use App\Jobs\SendPushNotification;
use App\Models\CareInvitation;
use App\Models\CareMembership;
use App\Models\Elder;
use App\Models\MedicationOccurrence;
use App\Models\Observation;
use App\Models\Professional;
use App\Models\PushDelivery;
use App\Models\PushSubscription;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Models\Visit;
use App\Services\MedicationService;
use App\Services\PushTransport;
use App\Services\ReminderService;
use App\Services\VisitService;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CareApiTest extends TestCase
{
    use RefreshDatabase;

    private function family(): array
    {
        $u = User::factory()->create();
        $e = Elder::create(['name' => 'Idoso de teste', 'birth_date' => '1940-01-01', 'timezone' => 'America/Cuiaba', 'created_by' => $u->id]);
        CareMembership::create(['elder_id' => $e->id, 'user_id' => $u->id, 'role' => 'manager']);

        return [$u, $e];
    }

    private function staff(Elder $e, string $role = 'ideale_admin'): User
    {
        $u = User::factory()->create(['role' => $role]);
        CareMembership::create(['elder_id' => $e->id, 'user_id' => $u->id, 'role' => 'team']);
        if ($role === 'ideale_professional') {
            Professional::create(['user_id' => $u->id, 'specialty' => 'Fisioterapia']);
        }

        return $u;
    }

    private function visit(Elder $e, User $admin, User $professional): Visit
    {
        return app(VisitService::class)->save($admin, $e, ['professional_id' => Professional::where('user_id', $professional->id)->value('id'),
            'starts_at' => now()->addDay()->setTime(10, 0)->toIso8601String(), 'ends_at' => now()->addDay()->setTime(11, 0)->toIso8601String()]);
    }

    public function test_registration_cannot_elevate_role_and_unverified_account_is_blocked(): void
    {
        Notification::fake();
        $this->postJson('/api/v1/auth/register', ['name' => 'Família', 'email' => 'FAMILY@example.test', 'password' => 'safe-test-pass-1234', 'password_confirmation' => 'safe-test-pass-1234', 'role' => 'ideale_admin'])
            ->assertCreated()->assertJsonPath('data.role', 'family');
        $this->assertDatabaseHas('users', ['email' => 'family@example.test', 'role' => 'family']);
        $this->getJson('/api/v1/elders')->assertForbidden();
        Notification::assertSentTo(User::first(), VerifyEmail::class);
    }

    public function test_login_rejects_inactive_and_wrong_password(): void
    {
        $u = User::factory()->create(['active' => false]);
        $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'password'])->assertUnprocessable();
        $u->update(['active' => true]);
        $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'password'])->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
    }

    public function test_family_can_create_own_network_and_medical_information_is_encrypted(): void
    {
        $u = User::factory()->create();
        $r = $this->actingAs($u)->postJson('/api/v1/elders', ['name' => 'Pessoa', 'birth_date' => '1940-01-01', 'timezone' => 'America/Cuiaba', 'medical_information' => 'dado sensível'])->assertCreated();
        $id = $r->json('data.id');
        $this->assertDatabaseHas('care_memberships', ['elder_id' => $id, 'user_id' => $u->id, 'role' => 'manager']);
        $this->assertNotSame('dado sensível', DB::table('elders')->where('id', $id)->value('medical_information'));
        $this->getJson("/api/v1/elders/$id")->assertOk()->assertJsonPath('data.medical_information', 'dado sensível');
    }

    public function test_other_family_and_unlinked_staff_cannot_access_elder(): void
    {
        [$owner,$e] = $this->family();
        [$outsider] = $this->family();
        $this->actingAs($outsider)->getJson("/api/v1/elders/$e->id")->assertForbidden();
        $this->getJson('/api/v1/elders')->assertJsonCount(1, 'data');
        $admin = User::factory()->create(['role' => 'ideale_admin']);
        $this->actingAs($admin)->getJson("/api/v1/elders/$e->id/observations")->assertForbidden();
    }

    public function test_invite_is_bound_to_verified_email_and_revocation_is_immediate(): void
    {
        [$u,$e] = $this->family();
        $relative = User::factory()->create();
        $stranger = User::factory()->create();
        $token = $this->actingAs($u)->postJson("/api/v1/elders/$e->id/invitations", ['email' => $relative->email, 'role' => 'relative'])->assertCreated()->json('invitation_token');
        $this->actingAs($stranger)->postJson('/api/v1/invitations/accept', ['token' => $token])->assertForbidden();
        $this->actingAs($relative)->postJson('/api/v1/invitations/accept', ['token' => $token])->assertOk();
        $this->postJson('/api/v1/invitations/accept', ['token' => $token])->assertOk();
        $this->getJson("/api/v1/elders/$e->id")->assertOk();
        $id = CareMembership::where('user_id', $relative->id)->value('id');
        $this->actingAs($u)->deleteJson("/api/v1/elders/$e->id/memberships/$id")->assertNoContent();
        $this->actingAs($relative)->getJson("/api/v1/elders/$e->id")->assertForbidden();
        $this->postJson('/api/v1/invitations/accept', ['token' => $token])->assertOk();
        $this->getJson("/api/v1/elders/$e->id")->assertForbidden();
    }

    public function test_invitation_cannot_demote_the_network_manager(): void
    {
        [$manager, $elder] = $this->family();
        $token = $this->actingAs($manager)->postJson("/api/v1/elders/$elder->id/invitations", ['email' => $manager->email, 'role' => 'relative'])->assertCreated()->json('invitation_token');

        $this->postJson('/api/v1/invitations/accept', ['token' => $token])->assertConflict();

        $this->assertDatabaseHas('care_memberships', ['elder_id' => $elder->id, 'user_id' => $manager->id, 'role' => 'manager', 'active' => true]);
        $this->assertNull(CareInvitation::where('token_hash', hash('sha256', $token))->firstOrFail()->accepted_at);
        $this->getJson("/api/v1/elders/$elder->id/memberships")->assertOk();
    }

    public function test_manager_can_accept_a_relative_invitation_to_another_network(): void
    {
        [$manager, $ownElder] = $this->family();
        [$otherManager, $otherElder] = $this->family();
        $token = $this->actingAs($otherManager)->postJson("/api/v1/elders/$otherElder->id/invitations", ['email' => $manager->email, 'role' => 'relative'])->assertCreated()->json('invitation_token');

        $this->actingAs($manager)->postJson('/api/v1/invitations/accept', ['token' => $token])->assertOk();

        $this->assertDatabaseHas('care_memberships', ['elder_id' => $ownElder->id, 'user_id' => $manager->id, 'role' => 'manager', 'active' => true]);
        $this->assertDatabaseHas('care_memberships', ['elder_id' => $otherElder->id, 'user_id' => $manager->id, 'role' => 'relative', 'active' => true]);
    }

    public function test_family_cannot_become_team_by_accepting_team_invitation(): void
    {
        [$u,$e] = $this->family();
        $relative = User::factory()->create();
        $token = $this->actingAs($u)->postJson("/api/v1/elders/$e->id/invitations", ['email' => $relative->email, 'role' => 'team'])->json('invitation_token');
        $this->actingAs($relative)->postJson('/api/v1/invitations/accept', ['token' => $token])->assertForbidden();
    }

    public function test_task_completion_is_idempotent_and_assignment_scoped(): void
    {
        [$u,$e] = $this->family();
        [$other] = $this->family();
        $body = ['title' => 'Caminhada', 'due_at' => now()->addHour()->toIso8601String(), 'assignees' => [$other->id]];
        $this->actingAs($u)->postJson("/api/v1/elders/$e->id/tasks", $body)->assertUnprocessable();
        $body['assignees'] = [$u->id];
        $id = $this->postJson("/api/v1/elders/$e->id/tasks", $body)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/elders/$e->id/tasks/$id/complete")->assertOk();
        $this->postJson("/api/v1/elders/$e->id/tasks/$id/complete")->assertOk();
        $this->assertDatabaseCount('task_completions', 1);
    }

    public function test_nested_record_cannot_escape_elder_scope(): void
    {
        [$u,$e] = $this->family();
        [$other,$otherElder] = $this->family();
        $task = Task::create(['elder_id' => $otherElder->id, 'created_by' => $other->id, 'title' => 'Privada', 'due_at' => now()]);
        $this->actingAs($u)->postJson("/api/v1/elders/$e->id/tasks/$task->id/complete")->assertNotFound();
    }

    public function test_stale_edits_are_rejected(): void
    {
        [$u,$e] = $this->family();
        $this->actingAs($u);
        $body = ['name' => 'Nome alterado', 'birth_date' => '1940-01-01', 'timezone' => 'America/Cuiaba', 'version' => 1];
        $this->putJson("/api/v1/elders/$e->id", $body)->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson("/api/v1/elders/$e->id", $body)->assertConflict();
    }

    public function test_profile_policy_allows_only_the_active_manager_to_reactivate_an_elder(): void
    {
        [$manager, $elder] = $this->family();
        $elder->update(['active' => false]);
        $relative = User::factory()->create();
        CareMembership::create(['elder_id' => $elder->id, 'user_id' => $relative->id, 'role' => 'relative']);
        $outsider = User::factory()->create();

        $this->assertTrue(Gate::forUser($manager)->allows('update', $elder));
        $this->assertFalse(Gate::forUser($relative)->allows('update', $elder));
        $this->assertFalse(Gate::forUser($outsider)->allows('update', $elder));
        $manager->update(['active' => false]);
        $this->assertFalse(Gate::forUser($manager)->allows('update', $elder));
    }

    public function test_contact_updates_reject_stale_versions_without_overwriting_current_data(): void
    {
        [$manager, $elder] = $this->family();
        $body = ['name' => 'Central', 'phone' => '+5565999990001', 'type' => 'central', 'priority' => 1];
        $id = $this->actingAs($manager)->postJson("/api/v1/elders/$elder->id/contacts", $body)->assertCreated()->assertJsonPath('data.version', 1)->json('data.id');

        $this->putJson("/api/v1/elders/$elder->id/contacts/$id", [...$body, 'phone' => '+5565999990002', 'version' => 1])->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson("/api/v1/elders/$elder->id/contacts/$id", [...$body, 'version' => 1])->assertConflict();
        $this->putJson("/api/v1/elders/$elder->id/contacts/$id", $body)->assertUnprocessable();

        $this->assertDatabaseHas('emergency_contacts', ['id' => $id, 'phone' => '+5565999990002', 'version' => 2]);
    }

    public function test_medication_occurrences_preserve_confirmation_when_schedule_changes(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 6)->setTime(10, 0));
        [$u,$e] = $this->family();
        $this->actingAs($u);
        $body = ['name' => 'Remédio informado', 'dosage' => 'Dose informada', 'starts_on' => '2026-10-06', 'times' => ['08:00']];
        $id = $this->postJson("/api/v1/elders/$e->id/medications", $body)->assertCreated()->json('data.id');
        $first = MedicationOccurrence::where('medication_id', $id)->orderBy('scheduled_at')->firstOrFail();
        $this->assertSame('12:00', $first->scheduled_at->format('H:i'));
        $this->postJson("/api/v1/elders/$e->id/medication-occurrences/$first->id/confirm")->assertConflict();
        $this->travelTo($first->scheduled_at->addMinute());
        $this->postJson("/api/v1/elders/$e->id/medication-occurrences/$first->id/confirm")->assertOk();
        $this->postJson("/api/v1/elders/$e->id/medication-occurrences/$first->id/confirm")->assertOk();
        $this->putJson("/api/v1/elders/$e->id/medications/$id", [...$body, 'times' => ['09:00'], 'version' => 1, 'active' => true])->assertOk();
        $this->assertSame('confirmed', $first->fresh()->status);
        $this->assertGreaterThan(0, MedicationOccurrence::where('medication_id', $id)->where('status', 'cancelled')->count());
        $before = MedicationOccurrence::count();
        app(MedicationService::class)->generate();
        $this->assertSame($before, MedicationOccurrence::count());
    }

    public function test_measurement_shape_and_immutable_diary_correction(): void
    {
        [$u,$e] = $this->family();
        $this->actingAs($u);
        $this->postJson("/api/v1/elders/$e->id/measurements", ['type' => 'blood_pressure', 'value' => 120, 'measured_at' => now()->toIso8601String()])->assertUnprocessable();
        $this->postJson("/api/v1/elders/$e->id/measurements", ['type' => 'blood_pressure', 'value' => 120, 'secondary_value' => 80, 'measured_at' => now()->toIso8601String()])->assertCreated()->assertJsonPath('data.unit', 'mmHg');
        $id = $this->postJson("/api/v1/elders/$e->id/observations", ['text' => 'Primeira nota', 'occurred_at' => now()->toIso8601String()])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/elders/$e->id/observations", ['text' => 'Retificação', 'occurred_at' => now()->toIso8601String(), 'corrects_id' => $id])->assertCreated();
        $this->assertDatabaseHas('observations', ['id' => $id, 'text' => 'Primeira nota']);
    }

    public function test_visit_overlap_is_rejected_and_adjacent_visit_is_allowed(): void
    {
        [$u,$e] = $this->family();
        $a = $this->staff($e);
        $p = $this->staff($e, 'ideale_professional');
        $v = $this->visit($e, $a, $p);
        $this->actingAs($a);
        $body = ['professional_id' => $v->professional_id, 'starts_at' => $v->starts_at->toIso8601String(), 'ends_at' => $v->ends_at->toIso8601String()];
        $this->postJson("/api/v1/elders/$e->id/visits", $body)->assertConflict();
        $body['starts_at'] = $v->ends_at->toIso8601String();
        $body['ends_at'] = $v->ends_at->addHour()->toIso8601String();
        $this->postJson("/api/v1/elders/$e->id/visits", $body)->assertCreated();
    }

    public function test_reschedule_cancels_old_reminder_and_cancelled_visit_cannot_complete(): void
    {
        [$u,$e] = $this->family();
        $a = $this->staff($e);
        $p = $this->staff($e, 'ideale_professional');
        $v = $this->visit($e, $a, $p);
        $old = Reminder::first();
        $this->actingAs($a);
        $this->putJson("/api/v1/elders/$e->id/visits/$v->id", ['professional_id' => $v->professional_id, 'starts_at' => $v->starts_at->addDay()->toIso8601String(), 'ends_at' => $v->ends_at->addDay()->toIso8601String(), 'version' => 1])->assertOk();
        $this->assertSame('cancelled', $old->fresh()->status);
        $this->postJson("/api/v1/elders/$e->id/visits/$v->id/status", ['status' => 'cancelled', 'version' => 2])->assertOk();
        $this->postJson("/api/v1/elders/$e->id/visits/$v->id/status", ['status' => 'completed', 'version' => 3])->assertConflict();
    }

    public function test_confirming_visit_preserves_a_processed_reminder_awaiting_push(): void
    {
        [$family, $elder] = $this->family();
        $admin = $this->staff($elder);
        $professional = $this->staff($elder, 'ideale_professional');
        $visit = $this->visit($elder, $admin, $professional);
        PushSubscription::create(['user_id' => $family->id, 'endpoint_hash' => hash('sha256', 'confirmation'), 'subscription' => ['endpoint' => 'https://fcm.googleapis.com/fcm/send/test']]);
        Reminder::query()->update(['due_at' => now()->subMinute()]);
        app(ReminderService::class)->process();
        $reminder = Reminder::firstOrFail();
        $delivery = PushDelivery::firstOrFail();
        $this->assertSame('processed', $reminder->status);

        $this->actingAs($admin)->postJson("/api/v1/elders/$elder->id/visits/$visit->id/status", ['status' => 'confirmed', 'version' => 1])->assertOk();

        $this->assertTrue(app(ReminderService::class)->valid($reminder->fresh()));
        $this->assertDatabaseCount('reminders', 1);
        $this->mock(PushTransport::class)->shouldReceive('send')->once()->andReturn('sent');
        app()->call([new SendPushNotification($delivery->id), 'handle']);
        $this->assertSame('sent', $delivery->fresh()->status);
    }

    public function test_confirming_rescheduled_visit_does_not_revalidate_its_old_reminder(): void
    {
        [$family, $elder] = $this->family();
        $admin = $this->staff($elder);
        $professional = $this->staff($elder, 'ideale_professional');
        $visit = $this->visit($elder, $admin, $professional);
        Reminder::query()->update(['due_at' => now()->subMinute()]);
        app(ReminderService::class)->process();
        $old = Reminder::firstOrFail();

        $this->actingAs($admin)->putJson("/api/v1/elders/$elder->id/visits/$visit->id", ['professional_id' => $visit->professional_id, 'starts_at' => $visit->starts_at->addDay()->toIso8601String(), 'ends_at' => $visit->ends_at->addDay()->toIso8601String(), 'version' => 1])->assertOk();
        $this->postJson("/api/v1/elders/$elder->id/visits/$visit->id/status", ['status' => 'confirmed', 'version' => 2])->assertOk();

        $this->assertFalse(app(ReminderService::class)->valid($old->fresh()));
        $this->assertTrue(app(ReminderService::class)->valid(Reminder::latest('id')->firstOrFail()));
        $this->assertDatabaseCount('reminders', 2);
    }

    public function test_briefing_does_not_leak_other_elder_and_read_is_explicit(): void
    {
        [$u,$e] = $this->family();
        [$other,$otherElder] = $this->family();
        $a = $this->staff($e);
        $p = $this->staff($e, 'ideale_professional');
        $v = $this->visit($e, $a, $p);
        $o = Observation::create(['elder_id' => $e->id, 'user_id' => $u->id, 'text' => 'Nota', 'occurred_at' => now()->subDay()]);
        $secret = Observation::create(['elder_id' => $otherElder->id, 'user_id' => $other->id, 'text' => 'Segredo', 'occurred_at' => now()]);
        $this->actingAs($p)->getJson("/api/v1/elders/$e->id/visits/$v->id/briefing")->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseCount('observation_reads', 0);
        $this->postJson("/api/v1/elders/$e->id/visits/$v->id/observation-reads", ['observation_ids' => [$secret->id]])->assertUnprocessable();
        $this->postJson("/api/v1/elders/$e->id/visits/$v->id/observation-reads", ['observation_ids' => [$o->id]])->assertOk();
        $this->postJson("/api/v1/elders/$e->id/visits/$v->id/observation-reads", ['observation_ids' => [$o->id]])->assertOk();
        $this->assertDatabaseCount('observation_reads', 1);
    }

    public function test_calendar_uses_elder_timezone_including_previous_utc_day_boundary(): void
    {
        [$u,$e] = $this->family();
        Task::create(['elder_id' => $e->id, 'created_by' => $u->id, 'title' => 'Noite', 'due_at' => '2026-10-07 03:30:00']);
        $this->actingAs($u)->getJson("/api/v1/elders/$e->id/calendar?date=2026-10-06")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/elders/$e->id/calendar?date=2026-10-07")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_quick_call_uses_current_shift_then_central(): void
    {
        [$u,$e] = $this->family();
        $this->actingAs($u);
        $relative = $this->postJson("/api/v1/elders/$e->id/contacts", ['name' => 'Parente', 'phone' => '+5565999990001', 'type' => 'family', 'priority' => 1])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/elders/$e->id/contacts", ['name' => 'Central', 'phone' => '+5565999990002', 'type' => 'central', 'priority' => 1])->assertCreated();
        $this->getJson("/api/v1/elders/$e->id/quick-call")->assertJsonPath('data.type', 'central');
        $this->postJson("/api/v1/elders/$e->id/care-shifts", ['emergency_contact_id' => $relative, 'starts_at' => now()->subHour()->toIso8601String(), 'ends_at' => now()->addHour()->toIso8601String()])->assertCreated();
        $this->getJson("/api/v1/elders/$e->id/quick-call")->assertJsonPath('data.phone', '+5565999990001');
    }

    public function test_reminders_and_delivery_jobs_deduplicate_and_revocation_blocks_push(): void
    {
        [$u,$e] = $this->family();
        $a = $this->staff($e);
        $p = $this->staff($e, 'ideale_professional');
        $v = $this->visit($e, $a, $p);
        $s = PushSubscription::create(['user_id' => $u->id, 'endpoint_hash' => hash('sha256', 'fake'), 'subscription' => ['endpoint' => 'https://fcm.googleapis.com/fcm/send/test']]);
        Reminder::query()->update(['due_at' => now()->subMinute()]);
        app(ReminderService::class)->process();
        app(ReminderService::class)->process();
        $this->assertDatabaseCount('care_notifications', 1);
        $this->assertDatabaseCount('push_deliveries', 1);
        $delivery = PushDelivery::first();
        $mock = $this->mock(PushTransport::class);
        $mock->shouldNotReceive('send');
        CareMembership::where('user_id', $u->id)->update(['active' => false]);
        app()->call([new SendPushNotification($delivery->id), 'handle']);
        $this->assertSame('cancelled', $delivery->fresh()->status);
    }

    public function test_push_retry_is_persisted_and_expired_subscription_removed(): void
    {
        [$u,$e] = $this->family();
        $a = $this->staff($e);
        $p = $this->staff($e, 'ideale_professional');
        $this->visit($e, $a, $p);
        PushSubscription::create(['user_id' => $u->id, 'endpoint_hash' => hash('sha256', 'fake'), 'subscription' => ['endpoint' => 'https://fcm.googleapis.com/fcm/send/test']]);
        Reminder::query()->update(['due_at' => now()->subMinute()]);
        app(ReminderService::class)->process();
        $d = PushDelivery::first();
        $mock = $this->mock(PushTransport::class);
        $mock->shouldReceive('send')->once()->andReturn('retry');
        app()->call([new SendPushNotification($d->id), 'handle']);
        $this->assertSame('pending', $d->fresh()->status);
        $this->assertSame(1, $d->fresh()->attempts);
        $d->update(['available_at' => now()->subMinute()]);
        $mock->shouldReceive('send')->once()->andReturn('expired');
        app()->call([new SendPushNotification($d->id), 'handle']);
        $this->assertDatabaseCount('push_subscriptions', 0);
        $this->assertDatabaseCount('push_deliveries', 0);
    }

    public function test_push_transport_exception_is_logged_without_sensitive_contents(): void
    {
        [$family, $elder] = $this->family();
        $admin = $this->staff($elder);
        $professional = $this->staff($elder, 'ideale_professional');
        $this->visit($elder, $admin, $professional);
        PushSubscription::create(['user_id' => $family->id, 'endpoint_hash' => hash('sha256', 'logging'), 'subscription' => ['endpoint' => 'https://fcm.googleapis.com/fcm/send/PRIVATE_ENDPOINT']]);
        Reminder::query()->update(['due_at' => now()->subMinute()]);
        app(ReminderService::class)->process();
        $delivery = PushDelivery::firstOrFail();
        $this->mock(PushTransport::class)->shouldReceive('send')->once()->andThrow(new \RuntimeException('PRIVATE_ENDPOINT patient@example.test conteúdo clínico'));
        Log::spy();

        app()->call([new SendPushNotification($delivery->id), 'handle']);

        $this->assertSame('pending', $delivery->fresh()->status);
        $this->assertSame('retry', $delivery->fresh()->last_error);
        Log::shouldHaveReceived('warning')->once()->with('Push delivery unsuccessful', [
            'delivery_id' => $delivery->id,
            'notification_id' => $delivery->care_notification_id,
            'result' => 'retry',
            'attempts' => 1,
            'terminal' => false,
            'exception_type' => \RuntimeException::class,
        ]);
    }

    public function test_push_endpoint_rejects_local_network_and_deceptive_hosts(): void
    {
        [$u] = $this->family();
        $this->actingAs($u);
        foreach (['http://127.0.0.1', 'https://127.0.0.1', 'https://fcm.googleapis.com.attacker.test/x', 'https://user@fcm.googleapis.com/x', 'https://fcm.googleapis.com:443/x'] as $endpoint) {
            $this->postJson('/api/v1/push/subscriptions', ['endpoint' => $endpoint, 'keys' => ['p256dh' => str_repeat('a', 87), 'auth' => str_repeat('a', 22)]])->assertUnprocessable();
        }
    }

    public function test_push_recovers_an_expired_lease_and_limits_crashed_attempts(): void
    {
        [$u,$e] = $this->family();
        $a = $this->staff($e);
        $p = $this->staff($e, 'ideale_professional');
        $this->visit($e, $a, $p);
        PushSubscription::create(['user_id' => $u->id, 'endpoint_hash' => hash('sha256', 'recovery'), 'subscription' => ['endpoint' => 'https://fcm.googleapis.com/fcm/send/test']]);
        Reminder::query()->update(['due_at' => now()->subMinute()]);
        app(ReminderService::class)->process();
        $d = PushDelivery::first();
        $d->update(['status' => 'processing', 'attempts' => 1, 'lease_until' => now()->subMinute()]);
        $mock = $this->mock(PushTransport::class);
        $mock->shouldReceive('send')->once()->andReturn('sent');
        app()->call([new SendPushNotification($d->id), 'handle']);
        $this->assertSame('sent', $d->fresh()->status);
        $this->assertSame(2, $d->fresh()->attempts);
        app()->call([new SendPushNotification($d->id), 'handle']);
        $d->refresh()->update(['status' => 'processing', 'attempts' => 5, 'available_at' => now()->subMinute(), 'lease_until' => now()->subMinute()]);
        app()->call([new SendPushNotification($d->id), 'handle']);
        $this->assertSame('failed', $d->fresh()->status);
        $this->assertSame('attempt_limit', $d->fresh()->last_error);
    }
}
