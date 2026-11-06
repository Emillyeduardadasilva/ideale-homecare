<?php

namespace App\Policies;

use App\Models\Elder;
use App\Models\User;
use App\Services\CareAccess;

class ElderPolicy
{
    public function view(User $user, Elder $elder): bool
    {
        return app(CareAccess::class)->allows($user, $elder);
    }

    public function update(User $user, Elder $elder): bool
    {
        return app(CareAccess::class)->allows($user, $elder, 'manageProfile');
    }
}
