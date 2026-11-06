<?php

use App\Http\ApiExceptionResponse;
use App\Http\Middleware\ActiveUser;
use App\Http\Middleware\RequestContext;
use App\Http\Middleware\ValidateApiJson;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->append(RequestContext::class);
        $middleware->append(ValidateApiJson::class);
        $middleware->alias(['active' => ActiveUser::class]);
        $middleware->throttleApi('api');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(new ApiExceptionResponse);
        $exceptions->report(function (QueryException $exception) {
            Log::error('Database operation failed', [
                'sql_state' => $exception->errorInfo[0] ?? null,
                'request_id' => request()->attributes->get('request_id'),
            ]);

            return false;
        });
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'token', 'subscription', 'text', 'medical_information', 'dosage']);
    })->create();
