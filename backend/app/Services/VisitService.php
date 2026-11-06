<?php

namespace App\Services;

use App\Exceptions\ApiProblem;
use App\Models\CareMembership;
use App\Models\Elder;
use App\Models\Professional;
use App\Models\Reminder;
use App\Models\User;
use App\Models\Visit;
use Carbon\CarbonImmutable;

class VisitService
{
    public function __construct(private CareAccess $access) {}

    public function save(User $user, Elder $elder, array $data, ?int $id = null): Visit
    {
        return $this->access->write($user, $elder, 'schedule', function (Elder $e) use ($user, $data, $id) {
            $v = $id ? Visit::where('elder_id', $e->id)->findOrFail($id) : null;
            if ($v) {
                $this->access->version($v, $data['version']);
                abort_unless(in_array($v->status, ['scheduled', 'confirmed']), 409);
            }
            $professionalIds = array_unique(array_filter([$data['professional_id'], $v?->professional_id]));
            sort($professionalIds);
            $professionals = Professional::whereIn('id', $professionalIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $p = $professionals->get($data['professional_id']);
            abort_unless($p?->active && $p->user->active, 422, 'Profissional indisponível.');
            abort_unless(CareMembership::where('elder_id', $e->id)->where('user_id', $p->user_id)->where('role', 'team')->where('active', true)->exists(), 422, 'Profissional sem vínculo com este idoso.');
            $start = CarbonImmutable::parse($data['starts_at'])->utc();
            $end = CarbonImmutable::parse($data['ends_at'])->utc();
            $conflict = Visit::where('professional_id', $p->id)->whereIn('status', ['scheduled', 'confirmed'])->where('starts_at', '<', $end)->where('ends_at', '>', $start)->when($v, fn ($q) => $q->whereKeyNot($v->id))->lockForUpdate()->first();
            if ($conflict) {
                throw new ApiProblem(409, 'VISIT_CONFLICT', 'O profissional já possui visita nesse intervalo.');
            }
            $values = ['professional_id' => $p->id, 'specialty' => $p->specialty, 'starts_at' => $start, 'ends_at' => $end, 'status' => 'scheduled'];
            if ($v) {
                $v->update([...$values, 'version' => $v->version + 1]);
                Reminder::where('source_type', 'visit')->where('source_id', $v->id)->whereIn('status', ['pending', 'processed'])->update(['status' => 'cancelled']);
            } else {
                $v = Visit::create([...$values, 'elder_id' => $e->id, 'created_by' => $user->id]);
            }
            Reminder::create(['elder_id' => $e->id, 'source_type' => 'visit', 'source_id' => $v->id, 'source_version' => $v->version, 'due_at' => $start->subDay()->max(CarbonImmutable::now())]);
            $this->access->audit($user, $e, $id ? 'visit.rescheduled' : 'visit.created', $v);

            return $v;
        });
    }

    public function transition(User $user, Elder $elder, int $id, string $status, int $version): Visit
    {
        $ability = $user->role === 'ideale_admin' ? 'schedule' : 'professional';

        return $this->access->write($user, $elder, $ability, function (Elder $e) use ($user, $id, $status, $version) {
            $v = Visit::where('elder_id', $e->id)->findOrFail($id);
            $p = Professional::whereKey($v->professional_id)->lockForUpdate()->firstOrFail();
            if ($user->role !== 'ideale_admin') {
                abort_unless($p->user_id === $user->id && $status !== 'cancelled', 403);
            }
            if ($v->status === $status) {
                return $v;
            }
            $this->access->version($v, $version);
            abort_unless(match ($status) {
                'confirmed' => $v->status === 'scheduled','completed' => $v->status === 'confirmed' && $v->starts_at->lte(now()),'cancelled' => in_array($v->status, ['scheduled', 'confirmed']),default => false
            }, 409, 'Transição não permitida.');
            $v->update(['status' => $status, 'completed_at' => $status === 'completed' ? now() : null, 'version' => $v->version + 1]);
            if ($status === 'confirmed') {
                Reminder::where('source_type', 'visit')->where('source_id', $v->id)->where('source_version', $version)->whereIn('status', ['pending', 'processed'])->update(['source_version' => $v->version]);
            } else {
                Reminder::where('source_type', 'visit')->where('source_id', $v->id)->whereIn('status', ['pending', 'processed'])->update(['status' => 'cancelled']);
            }
            $this->access->audit($user, $e, 'visit.'.$status, $v);

            return $v;
        });
    }
}
