<?php

namespace Tests\Feature;

use App\Events\CareReminderAvailable;
use App\Jobs\SendRealtimeNotification;
use App\Models\CareMembership;
use App\Models\Elder;
use App\Models\Professional;
use App\Models\RealtimeDelivery;
use App\Models\Reminder;
use App\Models\User;
use App\Services\RealtimeTransport;
use App\Services\ReminderService;
use App\Services\VisitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RealtimeTest extends TestCase
{
    use RefreshDatabase;

    private function delivery(): array
    {
        config(['broadcasting.default' => 'reverb']);
        $family = User::factory()->create();
        $admin = User::factory()->create(['role' => 'ideale_admin']);
        $professionalUser = User::factory()->create(['role' => 'ideale_professional']);
        $elder = Elder::create(['name' => 'Pessoa', 'birth_date' => '1940-01-01', 'created_by' => $family->id]);
        CareMembership::create(['elder_id' => $elder->id, 'user_id' => $family->id, 'role' => 'manager']);
        foreach ([$admin, $professionalUser] as $staff) {
            CareMembership::create(['elder_id' => $elder->id, 'user_id' => $staff->id, 'role' => 'team']);
        }
        $professional = Professional::create(['user_id' => $professionalUser->id, 'specialty' => 'Fisioterapia']);
        app(VisitService::class)->save($admin, $elder, ['professional_id' => $professional->id, 'starts_at' => now()->addHour()->toIso8601String(), 'ends_at' => now()->addHours(2)->toIso8601String()]);
        Reminder::query()->update(['due_at' => now()->subMinute()]);
        app(ReminderService::class)->process();

        return [$family, $elder, RealtimeDelivery::firstOrFail()];
    }

    public function test_private_channel_allows_only_its_verified_active_owner(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => 'test-app']);
        require base_path('routes/channels.php');
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-care.users.'.$user->id])->assertOk()->assertJsonStructure(['auth']);
        $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-care.users.'.($user->id + 1)])->assertForbidden();
        $user->update(['active' => false]);
        $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-care.users.'.$user->id])->assertForbidden();
    }

    public function test_durable_dispatch_and_repeated_jobs_emit_only_a_minimal_signal(): void
    {
        [$family, $elder, $delivery] = $this->delivery();
        app(ReminderService::class)->process();
        $this->assertDatabaseCount('realtime_deliveries', 1);
        Queue::fake();
        app(ReminderService::class)->dispatchPending();
        Queue::assertPushed(SendRealtimeNotification::class, fn ($job) => $job->deliveryId === $delivery->id);
        Event::fake([CareReminderAvailable::class]);
        app()->call([new SendRealtimeNotification($delivery->id), 'handle']);
        app()->call([new SendRealtimeNotification($delivery->id), 'handle']);
        Event::assertDispatchedTimes(CareReminderAvailable::class, 1);
        Event::assertDispatched(CareReminderAvailable::class, fn ($event) => $event->broadcastWith() === ['notification_id' => $delivery->care_notification_id] && $event->broadcastOn()[0]->name === 'private-care.users.'.$family->id);
        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertDatabaseCount('care_notifications', 1);
    }

    public function test_revoked_access_cancels_pending_realtime_delivery(): void
    {
        [$family, $elder, $delivery] = $this->delivery();
        CareMembership::where('elder_id', $elder->id)->where('user_id', $family->id)->update(['active' => false]);
        $this->mock(RealtimeTransport::class)->shouldNotReceive('send');
        app()->call([new SendRealtimeNotification($delivery->id), 'handle']);
        $this->assertSame('cancelled', $delivery->fresh()->status);
    }

    public function test_interrupted_delivery_recovers_and_transport_failure_can_be_retried(): void
    {
        [$family, $elder, $delivery] = $this->delivery();
        $delivery->update(['status' => 'processing', 'lease_until' => now()->subMinute()]);
        $transport = $this->mock(RealtimeTransport::class);
        $transport->shouldReceive('send')->once()->andThrow(new \RuntimeException('synthetic failure'));
        app()->call([new SendRealtimeNotification($delivery->id), 'handle']);
        $this->assertSame('pending', $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->attempts);
        $this->assertTrue($delivery->fresh()->available_at->isFuture());
        $this->travelTo($delivery->fresh()->available_at->addSecond());
        $transport->shouldReceive('send')->once()->andReturnNull();
        app()->call([new SendRealtimeNotification($delivery->id), 'handle']);
        $this->assertSame('sent', $delivery->fresh()->status);
    }
}
