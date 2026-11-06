<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CareMembership extends Model
{
    protected $table = 'care_memberships';

    protected $guarded = ['id'];

    protected $attributes = ['active' => true];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
