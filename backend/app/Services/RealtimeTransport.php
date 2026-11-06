<?php

namespace App\Services;

use App\Events\CareReminderAvailable;
use App\Models\CareNotification;

class RealtimeTransport
{
    public function send(CareNotification $notification): void
    {
        event(new CareReminderAvailable($notification->user_id, $notification->id));
    }
}
