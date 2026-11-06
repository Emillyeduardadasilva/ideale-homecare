<?php

namespace App\Services;

use App\Exceptions\ApiProblem;
use App\Models\CareShift;
use App\Models\Elder;
use App\Models\EmergencyContact;
use App\Models\User;
use Carbon\CarbonImmutable;

class CareShiftService
{
    public function __construct(private CareAccess $access) {}

    public function save(User $user, Elder $elder, array $data, ?int $id = null): CareShift
    {
        return $this->access->write($user, $elder, 'manage', function (Elder $locked) use ($user, $data, $id) {
            $shift = $id ? CareShift::where('elder_id', $locked->id)->findOrFail($id) : null;
            if ($shift) {
                $this->access->version($shift, $data['version']);
                abort_unless($shift->active, 409, 'Escala cancelada.');
            }
            EmergencyContact::where('elder_id', $locked->id)->where('active', true)->where('type', 'family')->findOrFail($data['emergency_contact_id']);
            $values = ['emergency_contact_id' => $data['emergency_contact_id'], 'starts_at' => CarbonImmutable::parse($data['starts_at'])->utc(), 'ends_at' => CarbonImmutable::parse($data['ends_at'])->utc()];
            $conflict = CareShift::where('elder_id', $locked->id)->where('active', true)->when($shift, fn ($query) => $query->whereKeyNot($shift->id))
                ->where('starts_at', '<', $values['ends_at'])->where('ends_at', '>', $values['starts_at'])->exists();
            if ($conflict) {
                throw new ApiProblem(409, 'SHIFT_CONFLICT', 'Escala sobreposta.');
            }
            if ($shift) {
                $shift->update([...$values, 'version' => $shift->version + 1]);
            } else {
                $shift = CareShift::create([...$values, 'elder_id' => $locked->id]);
            }
            $this->access->audit($user, $locked, $id ? 'shift.updated' : 'shift.created', $shift);

            return $shift;
        });
    }

    public function cancel(User $user, Elder $elder, int $id, int $version): void
    {
        $this->access->write($user, $elder, 'manage', function (Elder $locked) use ($user, $id, $version) {
            $shift = CareShift::where('elder_id', $locked->id)->findOrFail($id);
            if (! $shift->active) {
                return;
            }
            $this->access->version($shift, $version);
            $shift->update(['active' => false, 'version' => $shift->version + 1]);
            $this->access->audit($user, $locked, 'shift.cancelled', $shift);
        });
    }
}
