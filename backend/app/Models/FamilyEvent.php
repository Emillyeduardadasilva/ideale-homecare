<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FamilyEvent extends Model
{
    protected $table = 'family_events';

    protected $guarded = ['id'];

    protected $attributes = ['active' => true, 'version' => 1];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'active' => 'boolean', 'version' => 'integer'];
    }
}
