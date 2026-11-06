<?php

namespace App\Services;

use App\Jobs\SendPushNotification;
use App\Jobs\SendRealtimeNotification;
use App\Models\CareMembership;
use App\Models\CareNotification;
use App\Models\Elder;
use App\Models\MedicationOccurrence;
use App\Models\PushDelivery;
use App\Models\PushSubscription;
use App\Models\RealtimeDelivery;
use App\Models\Reminder;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;

class ReminderService
{
    public function valid(Reminder $r): bool
    {
        if ($r->status === 'cancelled') {
            return false;
        }
        if ($r->source_type === 'visit') {
            $v = Visit::find($r->source_id);

            return $v && in_array($v->status, ['scheduled', 'confirmed']) && $v->version === $r->source_version;
        }
        $o = MedicationOccurrence::find($r->source_id);

        return $o && $o->status === 'pending';
    }

    public function process(): void
    {
        Reminder::where('status', 'pending')->where('due_at', '<=', now())->select(['id', 'elder_id'])->chunkById(100, function ($reminders) {
            foreach ($reminders as $item) {
                DB::transaction(function () use ($item) {
                    $e = Elder::whereKey($item->elder_id)->lockForUpdate()->firstOrFail();
                    $r = Reminder::whereKey($item->id)->lockForUpdate()->firstOrFail();
                    if ($r->status !== 'pending') {
                        return;
                    }
                    if (! $e->active || ! $this->valid($r)) {
                        $r->update(['status' => 'cancelled']);

                        return;
                    }
                    $ids = CareMembership::where('elder_id', $e->id)->where('active', true)->whereIn('role', ['manager', 'relative'])->pluck('user_id');
                    if ($e->user_id) {
                        $ids->push($e->user_id);
                    }
                    foreach (User::whereIn('id', $ids)->where('active', true)->whereNotNull('email_verified_at')->get() as $user) {
                        $n = CareNotification::firstOrCreate(['user_id' => $user->id, 'reminder_id' => $r->id], ['elder_id' => $e->id]);
                        RealtimeDelivery::firstOrCreate(['care_notification_id' => $n->id], ['available_at' => now()]);
                        foreach (PushSubscription::where('user_id', $user->id)->get() as $s) {
                            PushDelivery::firstOrCreate(['care_notification_id' => $n->id, 'push_subscription_id' => $s->id], ['available_at' => now()]);
                        }
                    }
                    $r->update(['status' => 'processed']);
                }, 3);
            }
        });
    }

    public function dispatchPending(): void
    {
        if (config('broadcasting.default') === 'reverb') {
            RealtimeDelivery::whereIn('status', ['pending', 'processing'])->where('available_at', '<=', now())
                ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()))->select('id')->chunkById(100, function ($items) {
                    foreach ($items as $item) {
                        SendRealtimeNotification::dispatch($item->id);
                    }
                });
        }
        PushDelivery::whereIn('status', ['pending', 'processing'])->where('available_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<=', now()))->select('id')->chunkById(100, function ($items) {
                foreach ($items as $item) {
                    SendPushNotification::dispatch($item->id);
                }
            });
    }
}
