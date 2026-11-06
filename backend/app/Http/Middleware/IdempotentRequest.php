<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiProblem;
use App\Models\Elder;
use App\Services\CareAccess;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class IdempotentRequest
{
    public function handle(Request $request, Closure $next): mixed
    {
        $key = $request->header('Idempotency-Key');
        if (! $key || ! in_array($request->method(), ['POST', 'PUT', 'DELETE'])) {
            return $next($request);
        }
        abort_unless(preg_match('/^[A-Za-z0-9._:-]{16,128}$/D', $key), 422, 'Idempotency-Key deve ter 16 a 128 caracteres.');
        if ($elder = $request->route('elder')) {
            $elder = $elder instanceof Elder ? $elder : Elder::findOrFail($elder);
            app(CareAccess::class)->check($request->user(), $elder);
        }
        $keyHash = hash('sha256', $key);
        $requestHash = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());

        return DB::transaction(function () use ($request, $next, $keyHash, $requestHash) {
            DB::table('idempotency_requests')->insertOrIgnore(['user_id' => $request->user()->id, 'key_hash' => $keyHash, 'request_hash' => $requestHash, 'expires_at' => now()->addDay()]);
            $row = DB::table('idempotency_requests')->where('user_id', $request->user()->id)->where('key_hash', $keyHash)->lockForUpdate()->first();
            if (CarbonImmutable::parse($row->expires_at)->lte(now())) {
                DB::table('idempotency_requests')->where('id', $row->id)->update(['request_hash' => $requestHash, 'expires_at' => now()->addDay(), 'status' => null, 'response' => null]);
                $row->request_hash = $requestHash;
                $row->status = null;
                $row->response = null;
            }
            if (! hash_equals($row->request_hash, $requestHash)) {
                throw new ApiProblem(409, 'IDEMPOTENCY_CONFLICT', 'Chave já usada com outra requisição.');
            }
            if ($row->response !== null) {
                return response(Crypt::decryptString($row->response), $row->status)->header('Content-Type', 'application/json')->header('Idempotency-Replayed', 'true');
            }
            $response = $next($request);
            if ($response->getStatusCode() < 400) {
                DB::table('idempotency_requests')->where('id', $row->id)->update(['status' => $response->getStatusCode(), 'response' => Crypt::encryptString($response->getContent())]);
            } else {
                DB::table('idempotency_requests')->where('id', $row->id)->delete();
            }

            return $response;
        }, 3);
    }
}
