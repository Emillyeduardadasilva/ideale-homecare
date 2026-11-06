<?php

use App\Models\Elder;
use App\Models\User;
use App\Services\VisitService;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Separate process used exclusively by the MySQL concurrency integration test.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || ! str_ends_with(config('database.connections.mysql.database'), '_test')) {
    exit(2);
}
[$script,$userId,$elderId,$professionalId,$barrier,$worker] = $argv;
file_put_contents($barrier.'.'.$worker.'.ready', 'ready');
$deadline = microtime(true) + 20;
while (! is_file($barrier) && microtime(true) < $deadline) {
    usleep(10000);
}
if (! is_file($barrier)) {
    exit(3);
}
try {
    app(VisitService::class)->save(User::findOrFail($userId), Elder::findOrFail($elderId), [
        'professional_id' => (int) $professionalId, 'starts_at' => '2030-01-01T10:00:00+00:00', 'ends_at' => '2030-01-01T11:00:00+00:00']);
    echo '201';
} catch (HttpException $e) {
    echo $e->getStatusCode();
}
