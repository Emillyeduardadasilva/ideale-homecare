<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PushSubscription extends Model
{
    protected $table = 'push_subscriptions';

    protected $guarded = ['id'];

    protected $hidden = ['subscription', 'endpoint_hash'];

    protected function casts(): array
    {
        return ['subscription' => 'encrypted:array'];
    }
}
