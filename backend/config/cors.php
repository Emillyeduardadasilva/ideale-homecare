<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'], 'allowed_methods' => ['*'],
    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:5173')],
    'allowed_origins_patterns' => [], 'allowed_headers' => ['Accept', 'Content-Type', 'X-XSRF-TOKEN', 'X-Requested-With', 'Idempotency-Key'],
    'exposed_headers' => ['X-Request-ID', 'Idempotency-Replayed'], 'max_age' => 600, 'supports_credentials' => true,
];
