<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use JsonException;

class ValidateApiJson
{
    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->is('api/*') && in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE']) && $request->isJson() && $request->getContent() !== '') {
            try {
                $body = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new ApiProblem(400, 'BAD_REQUEST', 'O corpo deve conter JSON válido.');
            }
            if (! $body instanceof \stdClass && $body !== []) {
                throw new ApiProblem(400, 'BAD_REQUEST', 'O corpo JSON deve ser um objeto.');
            }
        }

        return $next($request);
    }
}
