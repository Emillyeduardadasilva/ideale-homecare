<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicationOccurrence extends Model
{
    protected $table = 'medication_occurrences';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'immutable_datetime', 'confirmed_at' => 'immutable_datetime', 'local_date' => 'date:Y-m-d'];
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class);
    }
}
