<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('care.users.{userId}', fn (User $user, string $userId) => $user->active && $user->hasVerifiedEmail() && (string) $user->id === $userId);
