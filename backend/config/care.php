<?php

return [
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    'medication_horizon_days' => 7,
    'push' => [
        'subject' => env('VAPID_SUBJECT'), 'public_key' => env('VAPID_PUBLIC_KEY'), 'private_key' => env('VAPID_PRIVATE_KEY'),
        'allowed_hosts' => ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com'],
    ],
];
