<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;

class OpenApiController extends Controller
{
    public function __invoke()
    {
        return response()->json(json_decode(file_get_contents(base_path('docs/openapi.json')), true, 512, JSON_THROW_ON_ERROR));
    }
}
