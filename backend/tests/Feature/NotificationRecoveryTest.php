<?php

namespace Tests\Feature;

use App\Models\CareMembership;
use App\Models\CareNotification;
use App\Models\Elder;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_catch_up_cursor_returns_only_new_authorized_notifications_in_order(): void
    {
        $user = User::factory()->create();
        $elder = Elder::create(['name' => 'Pessoa', 'birth_date' => '1940-01-01', 'created_by' => $user->id]);
        $membership = CareMembership::create(['elder_id' => $elder->id, 'user_id' => $user->id, 'role' => 'manager']);
        $notifications = [];
        for ($i = 1; $i <= 3; $i++) {
            $reminder = Reminder::create(['elder_id' => $elder->id, 'source_type' => 'visit', 'source_id' => $i, 'source_version' => 1, 'due_at' => now()]);
            $notifications[] = CareNotification::create(['user_id' => $user->id, 'elder_id' => $elder->id, 'reminder_id' => $reminder->id]);
        }
        $this->actingAs($user)->getJson('/api/v1/notifications?after_id='.$notifications[0]->id)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $notifications[1]->id)->assertJsonPath('next_after_id', $notifications[2]->id);
        $membership->update(['active' => false]);
        $this->getJson('/api/v1/notifications?after_id=0')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/notifications/'.$notifications[0]->id.'/read')->assertForbidden();
    }

    public function test_push_device_cannot_be_reassigned_to_another_account(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $payload = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/synthetic-device', 'keys' => ['p256dh' => str_repeat('a', 87), 'auth' => str_repeat('b', 22)]];
        $id = $this->actingAs($first)->postJson('/api/v1/push/subscriptions', $payload)->assertCreated()->json('data.id');
        $this->actingAs($second)->postJson('/api/v1/push/subscriptions', $payload)->assertConflict();
        $this->assertDatabaseHas('push_subscriptions', ['id' => $id, 'user_id' => $first->id]);
        $this->assertDatabaseCount('push_subscriptions', 1);
        $this->actingAs($first)->postJson('/api/v1/push/subscriptions', $payload)->assertCreated()->assertJsonPath('data.id', $id);
    }
}
