<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Elder extends Model
{
    protected $table = 'elders';

    protected $guarded = ['id'];

    protected $attributes = ['active' => true, 'version' => 1];

    protected function casts(): array
    {
        return ['birth_date' => 'date:Y-m-d', 'medical_information' => 'encrypted', 'active' => 'boolean', 'version' => 'integer'];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(CareMembership::class);
    }
}
