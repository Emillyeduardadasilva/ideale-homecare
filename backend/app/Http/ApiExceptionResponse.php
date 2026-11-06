<?php

namespace App\Http;

use App\Exceptions\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ApiExceptionResponse
{
    public function __invoke(Response $response, Throwable $exception, Request $request): Response
    {
        if (! $request->is('api/*')) {
            return $response;
        }
        $status = $response->getStatusCode();
        $original = $response instanceof JsonResponse ? $response->getData(true) : [];
        $code = $exception instanceof ApiProblem ? $exception->problemCode : match ($status) {
            400 => 'BAD_REQUEST', 401 => 'UNAUTHENTICATED', 403 => 'FORBIDDEN',
            404 => 'NOT_FOUND', 405 => 'METHOD_NOT_ALLOWED', 409 => 'CONFLICT',
            410 => 'GONE', 413 => 'PAYLOAD_TOO_LARGE', 419 => 'CSRF_TOKEN_MISMATCH',
            422 => 'VALIDATION_ERROR', 429 => 'RATE_LIMITED', 503 => 'UNAVAILABLE',
            default => 'INTERNAL_ERROR',
        };
        $fallback = match ($status) {
            400 => 'Requisição inválida.', 401 => 'Entre na sua conta para continuar.',
            403 => 'Você não tem permissão para esta operação.', 404 => 'Registro não encontrado.',
            405 => 'Método não permitido.', 409 => 'A operação conflita com o estado atual.',
            410 => 'Este recurso não está mais disponível.', 413 => 'Requisição muito grande.',
            419 => 'Sua sessão expirou. Atualize a página e tente novamente.',
            422 => 'Confira os campos informados.', 429 => 'Muitas tentativas. Aguarde e tente novamente.',
            default => 'Não foi possível concluir a operação. Tente novamente mais tarde.',
        };
        $message = $status >= 500 ? $fallback : ($exception instanceof ApiProblem ? $exception->getMessage() : ($original['message'] ?? $fallback));
        if ($message === '' || (! $exception instanceof ApiProblem && in_array($status, [401, 403, 404, 405, 413, 419, 422, 429]))) {
            $message = $fallback;
        }
        $id = $request->attributes->get('request_id') ?? (string) Str::uuid();
        $body = ['message' => $message, 'error' => ['code' => $code, 'message' => $message], 'request_id' => $id];
        if (isset($original['errors'])) {
            $body['errors'] = $original['errors'];
            $body['error']['fields'] = $original['errors'];
        }

        return response()->json($body, $status)->withHeaders($response->headers->all())
            ->header('Content-Type', 'application/json')->header('X-Request-ID', $id)->header('Cache-Control', 'no-store, private');
    }
}
