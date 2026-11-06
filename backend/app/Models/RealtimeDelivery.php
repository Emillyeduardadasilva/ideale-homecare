<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RealtimeDelivery extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'pending', 'attempts' => 0];

    protected function casts(): array
    {
        return ['available_at' => 'immutable_datetime', 'lease_until' => 'immutable_datetime', 'attempts' => 'integer'];
    }
}
