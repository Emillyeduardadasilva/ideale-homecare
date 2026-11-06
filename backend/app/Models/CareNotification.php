<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareNotification extends Model
{
    protected $table = 'care_notifications';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['read_at' => 'immutable_datetime'];
    }

    public function reminder(): BelongsTo
    {
        return $this->belongsTo(Reminder::class);
    }
}
