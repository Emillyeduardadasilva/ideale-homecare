<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmergencyContact extends Model
{
    protected $table = 'emergency_contacts';

    protected $guarded = ['id'];

    protected $attributes = ['active' => true, 'priority' => 0, 'version' => 1];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'version' => 'integer'];
    }
}
