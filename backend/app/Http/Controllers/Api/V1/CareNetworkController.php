<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CareInvitation;
use App\Models\CareMembership;
use App\Models\Elder;
use App\Models\User;
use App\Services\CareAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CareNetworkController extends Controller
{
    public function __construct(private CareAccess $access) {}

    public function index(Request $r, Elder $elder)
    {
        $this->access->check($r->user(), $elder, 'manage');

        return ['data' => CareMembership::where('elder_id', $elder->id)->join('users', 'users.id', '=', 'care_memberships.user_id')->get(['care_memberships.id', 'users.name', 'users.email', 'care_memberships.role', 'care_memberships.active'])];
    }

    public function invite(Request $r, Elder $elder)
    {
        $d = $r->validate(['email' => 'required|email|max:254', 'role' => 'required|in:relative,team,elder']);

        return $this->access->write($r->user(), $elder, 'manage', function (Elder $e) use ($d, $r) {
            $token = Str::random(64);
            $invite = CareInvitation::create(['elder_id' => $e->id, 'created_by' => $r->user()->id, 'email' => strtolower(trim($d['email'])), 'role' => $d['role'], 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7)]);
            $this->access->audit($r->user(), $e, 'invitation.created', $invite);

            return response()->json(['data' => $invite, 'invitation_token' => $token, 'invitation_url' => rtrim(config('care.frontend_url'), '/').'/accept-invitation?token='.$token], 201);
        });
    }

    public function invitations(Request $r, Elder $elder)
    {
        $this->access->check($r->user(), $elder, 'manage');

        return CareInvitation::where('elder_id', $elder->id)->latest('id')->paginate(30);
    }

    public function accept(Request $r)
    {
        $d = $r->validate(['token' => 'required|string|size:64']);
        $invite = CareInvitation::where('token_hash', hash('sha256', $d['token']))->firstOrFail();

        return DB::transaction(function () use ($invite, $r) {
            $e = Elder::whereKey($invite->elder_id)->lockForUpdate()->firstOrFail();
            $i = CareInvitation::whereKey($invite->id)->lockForUpdate()->firstOrFail();
            $user = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->active && $user->hasVerifiedEmail(), 403);
            abort_unless($e->active && ! $i->revoked_at && $i->expires_at->isFuture(), 410, 'Convite expirado ou revogado.');
            abort_unless(hash_equals($i->email, strtolower($user->email)) && $user->hasVerifiedEmail(), 403);
            $role = $user->role;
            abort_unless(match ($i->role) {
                'relative' => $role === 'family', 'team' => in_array($role, ['ideale_admin', 'ideale_professional']), 'elder' => $role === 'family' || $role === 'elder', default => false
            }, 403);
            if ($i->accepted_at) {
                return ['message' => 'Convite já aceito.'];
            }
            $membership = CareMembership::where('elder_id', $e->id)->where('user_id', $user->id)->lockForUpdate()->first();
            abort_if($membership?->role === 'manager', 409, 'Convites não podem alterar o gestor da rede.');
            if ($i->role === 'elder') {
                abort_if(($e->user_id && $e->user_id !== $user->id) || CareMembership::where('user_id', $user->id)->exists() || Elder::where('user_id', $user->id)->whereKeyNot($e->id)->exists(), 409, 'Conta já vinculada a outra função.');
                $user->update(['role' => 'elder']);
                $e->update(['user_id' => $user->id]);
            } else {
                CareMembership::updateOrCreate(['elder_id' => $e->id, 'user_id' => $user->id], ['role' => $i->role, 'active' => true]);
            }
            $i->update(['accepted_at' => now()]);
            $this->access->audit($user, $e, 'invitation.accepted', $i);

            return ['message' => 'Vínculo criado.'];
        }, 3);
    }

    public function revoke(Request $r, Elder $elder, int $membership)
    {
        return $this->access->write($r->user(), $elder, 'manage', function (Elder $e) use ($membership, $r) {
            $m = CareMembership::where('elder_id', $e->id)->findOrFail($membership);
            abort_if($m->role === 'manager', 409, 'Não é permitido remover o gestor da rede.');
            $m->update(['active' => false]);
            $email = User::whereKey($m->user_id)->value('email');
            CareInvitation::where('elder_id', $e->id)->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $this->access->audit($r->user(), $e, 'membership.revoked', $m);

            return response()->noContent();
        });
    }

    public function revokeInvitation(Request $r, Elder $elder, int $invitation)
    {
        return $this->access->write($r->user(), $elder, 'manage', function (Elder $e) use ($invitation, $r) {
            $i = CareInvitation::where('elder_id', $e->id)->findOrFail($invitation);
            abort_if($i->accepted_at, 409, 'Remova o vínculo para revogar acesso.');
            $i->update(['revoked_at' => now()]);
            $this->access->audit($r->user(), $e, 'invitation.revoked', $i);

            return response()->noContent();
        });
    }

    public function unlinkElderAccount(Request $r, Elder $elder)
    {
        return $this->access->write($r->user(), $elder, 'manage', function (Elder $e) use ($r) {
            $e->update(['user_id' => null, 'version' => $e->version + 1]);
            $this->access->audit($r->user(), $e, 'elder_account.unlinked', $e);

            return response()->noContent();
        });
    }
}
