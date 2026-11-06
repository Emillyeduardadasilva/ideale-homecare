<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Medication extends Model
{
    protected $table = 'medications';

    protected $guarded = ['id'];

    protected $attributes = ['active' => true, 'version' => 1];

    protected function casts(): array
    {
        return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'active' => 'boolean', 'version' => 'integer'];
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(MedicationSchedule::class);
    }
}
