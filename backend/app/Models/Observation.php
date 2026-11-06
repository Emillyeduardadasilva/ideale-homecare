<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Observation extends Model
{
    protected $table = 'observations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tags' => 'array', 'occurred_at' => 'immutable_datetime'];
    }
}
