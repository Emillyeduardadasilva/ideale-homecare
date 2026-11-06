<?php

namespace App\Services;

class PushEndpoint
{
    public static function allowed(string $url): bool
    {
        $p = parse_url($url);
        if (! $p || ($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass']) || isset($p['port']) || isset($p['fragment'])) {
            return false;
        }
        $host = strtolower($p['host'] ?? '');

        return in_array($host, config('care.push.allowed_hosts'), true) || str_ends_with($host, '.notify.windows.com');
    }
}
