<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PushSubscriptions
{
    public function save(User $user, array $data): PushSubscription
    {
        return DB::transaction(function () use ($user, $data) {
            $owner = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($owner->active && $owner->hasVerifiedEmail(), 403);
            $hash = hash('sha256', $data['endpoint']);
            $existing = PushSubscription::where('endpoint_hash', $hash)->lockForUpdate()->first();
            abort_if($existing && $existing->user_id !== $owner->id, 409, 'Inscrição já vinculada. Remova-a na conta anterior.');
            abort_if(! $existing && PushSubscription::where('user_id', $owner->id)->count() >= 20, 422, 'Limite de dispositivos atingido.');
            $values = ['user_id' => $owner->id, 'subscription' => ['endpoint' => $data['endpoint'], 'keys' => $data['keys'], 'contentEncoding' => 'aes128gcm']];
            if ($existing) {
                $existing->update($values);

                return $existing;
            }

            return PushSubscription::create(['endpoint_hash' => $hash, ...$values]);
        }, 3);
    }
}
