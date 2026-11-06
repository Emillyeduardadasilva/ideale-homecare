<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CareInvitation extends Model
{
    protected $table = 'care_invitations';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}
