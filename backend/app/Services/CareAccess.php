<?php

namespace App\Services;

use App\Exceptions\ApiProblem;
use App\Models\CareMembership;
use App\Models\Elder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CareAccess
{
    public function scope(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->where('user_id', $user->id)->orWhereHas('memberships', fn (Builder $m) => $m->where('user_id', $user->id)->where('active', true));
        });
    }

    public function allows(User $user, Elder $elder, string $ability = 'view'): bool
    {
        if (! $user->active) {
            return false;
        }
        $m = CareMembership::where('elder_id', $elder->id)->where('user_id', $user->id)->where('active', true)->first();
        if ($ability === 'view') {
            return (bool) $m || ($user->role === 'elder' && $elder->user_id === $user->id);
        }
        if ($ability === 'manageProfile') {
            return $m && $m->role === 'manager' && $user->role === 'family';
        }
        if ($ability === 'confirm' && $elder->active && $user->role === 'elder' && $elder->user_id === $user->id) {
            return true;
        }
        if (! $elder->active || ! $m) {
            return false;
        }

        return match ($ability) {
            'family' => $user->role === 'family' && in_array($m->role, ['manager', 'relative']),
            'manage' => $user->role === 'family' && $m->role === 'manager',
            'schedule' => $user->role === 'ideale_admin' && $m->role === 'team',
            'professional' => $user->role === 'ideale_professional' && $m->role === 'team',
            'confirm' => ($user->role === 'family' && in_array($m->role, ['manager', 'relative'])),
            default => false,
        };
    }

    public function check(User $user, Elder $elder, string $ability = 'view'): void
    {
        abort_unless($this->allows($user, $elder, $ability), 403, 'Acesso não autorizado para este idoso.');
    }

    public function write(User $user, Elder $elder, string $ability, callable $callback): mixed
    {
        return DB::transaction(function () use ($user, $elder, $ability, $callback) {
            $locked = Elder::whereKey($elder->id)->lockForUpdate()->firstOrFail();
            $this->check($user->fresh(), $locked, $ability);

            return $callback($locked);
        }, 3);
    }

    public function version(Model $model, int $version): void
    {
        if ($model->version !== $version) {
            throw new ApiProblem(409, 'STALE_RESOURCE', 'Registro alterado. Atualize os dados e tente novamente.');
        }
    }

    public function audit(User $user, Elder $elder, string $action, Model $model): void
    {
        DB::table('audit_logs')->insert(['user_id' => $user->id, 'elder_id' => $elder->id, 'action' => $action,
            'entity' => $model->getTable(), 'entity_id' => $model->id, 'request_id' => request()->attributes->get('request_id'), 'created_at' => now()]);
    }
}
