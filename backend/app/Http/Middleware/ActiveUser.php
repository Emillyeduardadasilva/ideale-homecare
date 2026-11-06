<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ActiveUser
{
    public function handle(Request $request, Closure $next): mixed
    {
        abort_unless($request->user()?->active, 403, 'Conta inativa.');

        return $next($request);
    }
}
