<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Elder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function overview(Request $request): array
    {
        $this->authorizeAdmin($request);
        $this->audit($request, 'system_admin.overview_viewed', 'system');

        return ['data' => [
            'accounts' => User::count(),
            'active_accounts' => User::where('active', true)->count(),
            'families' => User::where('role', 'family')->count(),
            'care_staff' => User::whereIn('role', ['ideale_admin', 'ideale_professional'])->count(),
            'elders' => Elder::count(),
            'active_elders' => Elder::where('active', true)->count(),
            'tasks' => DB::table('tasks')->count(),
            'medications' => DB::table('medications')->count(),
            'observations' => DB::table('observations')->count(),
            'measurements' => DB::table('measurements')->count(),
            'visits' => DB::table('visits')->count(),
            'finance_entries' => DB::table('finance_entries')->count(),
        ]];
    }

    public function users(Request $request)
    {
        $this->authorizeAdmin($request);
        $this->audit($request, 'system_admin.accounts_listed', 'users');
        $filters = $request->validate([
            'role' => ['sometimes', Rule::in(['family', 'elder', 'ideale_admin', 'ideale_professional', 'system_admin'])],
            'active' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string', 'max:120'],
        ]);

        $query = User::query()->select(['id', 'name', 'email', 'role', 'active', 'email_verified_at', 'created_at'])->orderBy('name');
        if (isset($filters['role'])) $query->where('role', $filters['role']);
        if (isset($filters['active'])) $query->where('active', $filters['active']);
        if (! empty($filters['search'])) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%')->orWhere('email', 'like', '%'.$filters['search'].'%'));
        }

        return $query->paginate(50);
    }

    public function elders(Request $request)
    {
        $this->authorizeAdmin($request);
        $this->audit($request, 'system_admin.elders_listed', 'elders');

        return Elder::query()->withCount(['memberships as active_memberships_count' => fn ($q) => $q->where('active', true)])
            ->orderBy('name')->paginate(50);
    }

    public function setActive(Request $request, User $user)
    {
        $admin = $this->authorizeAdmin($request);
        $data = $request->validate(['active' => ['required', 'boolean']]);
        $active = (bool) $data['active'];

        abort_if(! $active && $user->is($admin), 422, 'Não é possível desativar a própria conta administrativa.');
        if (! $active && $user->role === 'system_admin') {
            abort_if(User::where('role', 'system_admin')->where('active', true)->whereKeyNot($user->id)->count() === 0, 422, 'Mantenha pelo menos uma conta administrativa ativa.');
        }

        $user->update(['active' => $active]);
        $this->audit($request, 'system_admin.account_status_changed', 'users', $user->id);

        return ['data' => $user->only(['id', 'name', 'email', 'role', 'active', 'email_verified_at'])];
    }

    private function authorizeAdmin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->active && $user->role === 'system_admin', 403, 'Acesso exclusivo do administrador do sistema.');

        return $user;
    }

    private function audit(Request $request, string $action, string $entity, ?int $entityId = null): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'elder_id' => null,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'request_id' => $request->attributes->get('request_id'),
            'created_at' => now(),
        ]);
    }
}
