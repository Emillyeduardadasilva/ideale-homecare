<?php

namespace App\Providers;

use App\Support\IsoInstant;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        require base_path('routes/channels.php');
        Validator::extend('iso_instant', fn ($attribute, $value) => IsoInstant::valid($value), 'O campo :attribute deve conter uma data e hora ISO 8601 com fuso.');
        // This application accepts first-party session cookies only.
        Sanctum::getAccessTokenFromRequestUsing(fn () => null);
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?? $r->ip()));
        RateLimiter::for('auth', fn (Request $r) => [Limit::perMinute(10)->by($r->ip()), Limit::perMinute(5)->by(strtolower((string) $r->input('email')).'|'.$r->ip())]);
        ResetPassword::createUrlUsing(fn ($user, $token) => rtrim(config('care.frontend_url'), '/').'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]));
    }
}
