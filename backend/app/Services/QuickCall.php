<?php

namespace App\Services;

use App\Models\CareShift;
use App\Models\Elder;
use App\Models\EmergencyContact;

class QuickCall
{
    public function resolve(Elder $elder): ?array
    {
        $shift = CareShift::where('elder_id', $elder->id)->where('active', true)->where('starts_at', '<=', now())->where('ends_at', '>', now())->first();
        $contact = $shift ? EmergencyContact::where('elder_id', $elder->id)->where('active', true)->where('type', 'family')->find($shift->emergency_contact_id) : null;
        $contact ??= EmergencyContact::where('elder_id', $elder->id)->where('active', true)->where('type', 'central')->orderBy('priority')->orderBy('id')->first();

        return $contact ? ['name' => $contact->name, 'phone' => $contact->phone, 'dial_uri' => 'tel:'.$contact->phone, 'type' => $contact->type] : null;
    }
}
