<?php

return [
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    'medication_horizon_days' => 7,
    // Hospedagem compartilhada (ex.: HostGator) não mantém processos ligados: a fila roda pelo cron do agendador.
    'queue_via_scheduler' => (bool) env('QUEUE_VIA_SCHEDULER', false),
    'push' => [
        'subject' => env('VAPID_SUBJECT'), 'public_key' => env('VAPID_PUBLIC_KEY'), 'private_key' => env('VAPID_PRIVATE_KEY'),
        'allowed_hosts' => ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com'],
    ],
];
