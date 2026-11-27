<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

// Execute somente no terminal/cron, com PHP 8.5.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$lock = Cache::lock('hostgator:cron', 600);
if (! $lock->get()) {
    fwrite(STDOUT, "O processamento anterior ainda está ativo.\n");
    exit(0);
}

$status = 0;
try {
    // O cron dispara a cada 15 minutos. A fila encerra ao esvaziar ou atingir os limites.
    $status = Artisan::call('schedule:run');
    fwrite(STDOUT, Artisan::output());
    if ($status === 0) {
        $status = Artisan::call('queue:work', [
            '--stop-when-empty' => true,
            '--max-time' => 40,
            '--max-jobs' => 50,
            '--timeout' => 40,
            '--tries' => 1,
        ]);
        fwrite(STDOUT, Artisan::output());
    }
} catch (Throwable $error) {
    Log::error('Falha no cron HostGator', ['exception' => get_class($error)]);
    fwrite(STDERR, "Falha no processamento. Consulte storage/logs.\n");
    $status = 1;
} finally {
    $lock->release();
}
exit($status);
