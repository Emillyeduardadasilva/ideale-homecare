<?php

use App\Models\Professional;
use App\Models\PushDelivery;
use App\Models\RealtimeDelivery;
use App\Models\Reminder;
use App\Models\User;
use App\Services\MedicationService;
use App\Services\ReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Minishlink\WebPush\VAPID;

Artisan::command('care:tick', function () {
    app(MedicationService::class)->generate();
    app(ReminderService::class)->process();
    app(ReminderService::class)->dispatchPending();
    $this->info('Ocorrências e lembretes processados.');
})->purpose('Gera ocorrências, materializa lembretes e recupera entregas pendentes');
Schedule::command('care:tick')->everyMinute()->withoutOverlapping(5);
Schedule::call(fn () => DB::table('idempotency_requests')->where('expires_at', '<', now())->delete())->daily();
Artisan::command('care:staff {email} {--name=} {--role=ideale_professional} {--specialty=}', function () {
    $role = $this->option('role');
    $email = strtolower(trim($this->argument('email')));
    if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! in_array($role, ['ideale_admin', 'ideale_professional']) || ! $this->option('name') || ($role === 'ideale_professional' && ! $this->option('specialty'))) {
        $this->error('Informe e-mail válido, --name, --role e --specialty para profissional.');

        return 1;
    }
    if (User::where('email', $email)->exists()) {
        $this->error('E-mail já cadastrado; nenhuma conta alterada.');

        return 1;
    }
    $user = DB::transaction(function () use ($email, $role) {
        $u = User::create(['name' => $this->option('name'), 'email' => $email, 'role' => $role, 'password' => Str::random(64)]);
        if ($role === 'ideale_professional') {
            Professional::create(['user_id' => $u->id, 'specialty' => $this->option('specialty')]);
        }

        return $u;
    });
    $this->info('Conta criada. Use recuperação de senha e confirmação de e-mail; o acesso exige convite da família.');
})->purpose('Provisiona conta da equipe sem permitir autoelevação de perfil');
Artisan::command('care:vapid', function () {
    $path = storage_path('app/private/vapid.json');
    if (is_file($path)) {
        $this->error('Chaves existentes preservadas: storage/app/private/vapid.json');

        return 1;
    }
    $keys = VAPID::createVapidKeys();
    file_put_contents($path, json_encode($keys, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    @chmod($path, 0600);
    $this->info('Chaves criadas em storage/app/private/vapid.json. Configure VAPID_PUBLIC_KEY e VAPID_PRIVATE_KEY no ambiente.');
});
Artisan::command('care:status {--json : Exibe os indicadores em JSON}', function () {
    $oldestReminder = Reminder::where('status', 'pending')->where('due_at', '<=', now())->min('due_at');
    $oldestPush = PushDelivery::whereIn('status', ['pending', 'processing'])->where('available_at', '<=', now())->min('available_at');
    $metrics = [
        'pending_reminders' => Reminder::where('status', 'pending')->count(),
        'overdue_reminders' => Reminder::where('status', 'pending')->where('due_at', '<=', now())->count(),
        'oldest_reminder_delay_seconds' => $oldestReminder ? (int) max(0, CarbonImmutable::parse($oldestReminder)->diffInSeconds(now())) : 0,
        'pending_push_deliveries' => PushDelivery::whereIn('status', ['pending', 'processing'])->count(),
        'oldest_push_delay_seconds' => $oldestPush ? (int) max(0, CarbonImmutable::parse($oldestPush)->diffInSeconds(now())) : 0,
        'failed_push_deliveries' => PushDelivery::where('status', 'failed')->count(),
        'pending_realtime_deliveries' => RealtimeDelivery::whereIn('status', ['pending', 'processing'])->count(),
        'failed_realtime_deliveries' => RealtimeDelivery::where('status', 'failed')->count(),
        'queued_jobs' => DB::table('jobs')->count(),
        'failed_jobs' => DB::table('failed_jobs')->count(),
        'vapid_configured' => (bool) (config('care.push.subject') && config('care.push.public_key') && config('care.push.private_key')),
        'realtime_configured' => config('broadcasting.default') === 'reverb' && config('broadcasting.connections.reverb.app_id') && config('broadcasting.connections.reverb.key') && config('broadcasting.connections.reverb.secret'),
    ];
    if ($this->option('json')) {
        $this->line(json_encode($metrics, JSON_THROW_ON_ERROR));

        return 0;
    }
    $this->table(['Indicador', 'Quantidade'], [
        ['Lembretes pendentes', $metrics['pending_reminders']],
        ['Lembretes vencidos', $metrics['overdue_reminders']],
        ['Maior atraso de lembrete (segundos)', $metrics['oldest_reminder_delay_seconds']],
        ['Entregas pendentes', $metrics['pending_push_deliveries']],
        ['Maior atraso de entrega (segundos)', $metrics['oldest_push_delay_seconds']],
        ['Entregas com falha', $metrics['failed_push_deliveries']],
        ['Sinais em tempo real pendentes', $metrics['pending_realtime_deliveries']],
        ['Sinais em tempo real com falha', $metrics['failed_realtime_deliveries']],
        ['Jobs na fila', $metrics['queued_jobs']],
        ['Jobs com falha', $metrics['failed_jobs']],
    ]);
    $this->info($metrics['vapid_configured'] ? 'VAPID configurado.' : 'VAPID ainda não configurado.');
});
Artisan::command('care:reverb', function () {
    $path = storage_path('app/private/reverb.json');
    if (is_file($path)) {
        $this->error('Configuração existente preservada: storage/app/private/reverb.json');

        return 1;
    }
    $configuration = ['REVERB_APP_ID' => (string) Str::uuid(), 'REVERB_APP_KEY' => Str::random(32), 'REVERB_APP_SECRET' => Str::random(64)];
    file_put_contents($path, json_encode($configuration, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    @chmod($path, 0600);
    $this->info('Credenciais criadas em storage/app/private/reverb.json. Configure o ambiente e reinicie os processos.');
})->purpose('Gera credenciais locais do Reverb sem exibir segredos');
Artisan::command('care:retry-realtime {id}', function () {
    $delivery = RealtimeDelivery::find($this->argument('id'));
    if (! $delivery || $delivery->status !== 'failed') {
        $this->error('Entrega não encontrada ou sem falha terminal.');

        return 1;
    }
    $delivery->update(['status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'lease_until' => null]);
    $this->info('Sinal liberado para nova tentativa com revalidação de acesso e origem.');
});
Artisan::command('care:retry-push {id}', function () {
    $d = PushDelivery::find($this->argument('id'));
    if (! $d || $d->status !== 'failed') {
        $this->error('Entrega não encontrada ou sem falha terminal.');

        return 1;
    }
    $d->update(['status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'lease_until' => null]);
    $this->info('Entrega liberada para reprocessamento com nova verificação de acesso e origem.');
});
