<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class CareReminderAvailable implements ShouldBroadcastNow
{
    public function __construct(private int $userId, private int $notificationId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('care.users.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'care.reminder.available';
    }

    public function broadcastWith(): array
    {
        return ['notification_id' => $this->notificationId];
    }
}
