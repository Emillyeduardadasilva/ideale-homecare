<?php

namespace App\Jobs;

use App\Models\CareNotification;
use App\Models\Elder;
use App\Models\PushDelivery;
use App\Models\PushSubscription;
use App\Models\Reminder;
use App\Models\User;
use App\Services\CareAccess;
use App\Services\PushTransport;
use App\Services\ReminderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public int $deliveryId) {}

    public function handle(CareAccess $access, ReminderService $reminders, PushTransport $transport): void
    {
        $d = DB::transaction(function () {
            $d = PushDelivery::whereKey($this->deliveryId)->lockForUpdate()->first();
            if (! $d || ! in_array($d->status, ['pending', 'processing']) || $d->available_at->isFuture() || $d->lease_until?->isFuture()) {
                return null;
            }
            if ($d->attempts >= 5) {
                $d->update(['status' => 'failed', 'lease_until' => null, 'last_error' => 'attempt_limit']);

                return null;
            }
            $d->update(['status' => 'processing', 'lease_until' => now()->addMinutes(2), 'attempts' => $d->attempts + 1]);

            return $d;
        });
        if (! $d) {
            return;
        }
        $n = CareNotification::find($d->care_notification_id);
        $s = PushSubscription::find($d->push_subscription_id);
        $u = $n ? User::find($n->user_id) : null;
        $e = $n ? Elder::find($n->elder_id) : null;
        $r = $n ? Reminder::find($n->reminder_id) : null;
        if (! $n || ! $s || ! $u || ! $e?->active || $s->user_id !== $u->id || ! $access->allows($u, $e) || ! $r || ! $reminders->valid($r)) {
            $d->update(['status' => 'cancelled', 'lease_until' => null]);

            return;
        }
        $exceptionType = null;
        try {
            $result = $transport->send($s->subscription, $n->id);
        } catch (\Throwable $exception) {
            $exceptionType = $exception::class;
            $result = 'retry';
        }
        if ($result === 'expired') {
            $s->delete();

            return;
        }
        $status = $result === 'sent' ? 'sent' : ($d->attempts >= 5 || $result === 'invalid' ? 'failed' : 'pending');
        if ($result !== 'sent') {
            Log::warning('Push delivery unsuccessful', [
                'delivery_id' => $d->id,
                'notification_id' => $n->id,
                'result' => $result,
                'attempts' => $d->attempts,
                'terminal' => $status === 'failed',
                'exception_type' => $exceptionType,
            ]);
        }
        $d->update(['status' => $status,
            'available_at' => now()->addSeconds(min(3600, 60 * (2 ** $d->attempts))), 'lease_until' => null, 'last_error' => $result === 'sent' ? null : $result]);
    }
}
