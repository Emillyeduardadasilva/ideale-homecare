<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Measurement extends Model
{
    protected $table = 'measurements';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['value' => 'decimal:2', 'secondary_value' => 'decimal:2', 'measured_at' => 'immutable_datetime'];
    }
}
