<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicationSchedule extends Model
{
    protected $table = 'medication_schedules';

    protected $guarded = ['id'];

    protected $attributes = ['active' => true];

    protected function casts(): array
    {
        return ['effective_at' => 'immutable_datetime', 'active' => 'boolean'];
    }
}
