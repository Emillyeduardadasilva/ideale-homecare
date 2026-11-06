<?php

namespace App\Jobs;

use App\Models\CareNotification;
use App\Models\Elder;
use App\Models\RealtimeDelivery;
use App\Models\Reminder;
use App\Models\User;
use App\Services\CareAccess;
use App\Services\RealtimeTransport;
use App\Services\ReminderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendRealtimeNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $deliveryId) {}

    public function handle(CareAccess $access, ReminderService $reminders, RealtimeTransport $transport): void
    {
        if (config('broadcasting.default') !== 'reverb') {
            return;
        }
        $delivery = DB::transaction(function () {
            $row = RealtimeDelivery::whereKey($this->deliveryId)->lockForUpdate()->first();
            if (! $row || ! in_array($row->status, ['pending', 'processing']) || $row->available_at->isFuture() || $row->lease_until?->isFuture()) {
                return null;
            }
            if ($row->attempts >= 5) {
                $row->update(['status' => 'failed', 'lease_until' => null, 'last_error' => 'attempt_limit']);

                return null;
            }
            $row->update(['status' => 'processing', 'lease_until' => now()->addMinutes(2), 'attempts' => $row->attempts + 1]);

            return $row;
        });
        if (! $delivery) {
            return;
        }
        $notification = CareNotification::find($delivery->care_notification_id);
        $user = $notification ? User::find($notification->user_id) : null;
        $elder = $notification ? Elder::find($notification->elder_id) : null;
        $reminder = $notification ? Reminder::find($notification->reminder_id) : null;
        if (! $user || ! $user->hasVerifiedEmail() || ! $elder?->active || ! $access->allows($user, $elder) || ! $reminder || ! $reminders->valid($reminder)) {
            $delivery->update(['status' => 'cancelled', 'lease_until' => null]);

            return;
        }
        try {
            $transport->send($notification);
            $delivery->update(['status' => 'sent', 'lease_until' => null, 'last_error' => null]);
        } catch (\Throwable $exception) {
            $terminal = $delivery->attempts >= 5;
            $delivery->update(['status' => $terminal ? 'failed' : 'pending', 'lease_until' => null, 'available_at' => now()->addSeconds(min(3600, 60 * (2 ** $delivery->attempts))), 'last_error' => 'transport_error']);
            Log::warning('Realtime delivery unsuccessful', ['delivery_id' => $delivery->id, 'notification_id' => $notification->id, 'attempts' => $delivery->attempts, 'terminal' => $terminal, 'exception_type' => $exception::class]);
        }
    }
}
