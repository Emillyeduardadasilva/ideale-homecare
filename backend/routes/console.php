<?php

use App\Models\Professional;
use App\Models\CareMembership;
use App\Models\Elder;
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

Artisan::command('care:demo-users', function () {
    if (! app()->environment(['local', 'testing'])) {
        $this->error('A criação de acessos de demonstração só é permitida nos ambientes local e testing.');

        return 1;
    }

    $password = 'Demo'.Str::random(10).'!9';
    $accounts = [
        ['name' => 'Administrador de demonstração', 'email' => 'admin.demo@ideale.test', 'role' => 'system_admin'],
        ['name' => 'Familiar de demonstração', 'email' => 'familia.demo@ideale.test', 'role' => 'family'],
        ['name' => 'Cuidador de demonstração', 'email' => 'cuidador.demo@ideale.test', 'role' => 'ideale_professional'],
    ];

    DB::transaction(function () use ($accounts, $password): void {
        $users = [];
        foreach ($accounts as $account) {
            // email_verified_at não é preenchível em massa: forceFill garante a conta já confirmada.
            $user = User::firstOrNew(['email' => $account['email']]);
            $user->forceFill([
                'name' => $account['name'],
                'role' => $account['role'],
                'active' => true,
                'email_verified_at' => now(),
                'password' => $password,
            ])->save();
            $users[$account['email']] = $user;
        }

        Professional::updateOrCreate(
            ['user_id' => $users['cuidador.demo@ideale.test']->id],
            ['specialty' => 'Cuidados gerais', 'active' => true],
        );

        $elder = Elder::firstOrCreate(
            ['name' => 'Idoso de demonstração', 'created_by' => $users['familia.demo@ideale.test']->id],
            ['birth_date' => '1940-01-01', 'timezone' => 'America/Cuiaba', 'active' => true],
        );

        CareMembership::updateOrCreate(
            ['elder_id' => $elder->id, 'user_id' => $users['familia.demo@ideale.test']->id],
            ['role' => 'manager', 'active' => true],
        );
        CareMembership::updateOrCreate(
            ['elder_id' => $elder->id, 'user_id' => $users['cuidador.demo@ideale.test']->id],
            ['role' => 'team', 'active' => true],
        );
    });

    $this->newLine();
    $this->info('Acessos de demonstração criados/atualizados para uso local:');
    $this->table(['Perfil', 'E-mail'], array_map(fn ($account) => [$account['role'], $account['email']], $accounts));
    $this->line('Senha temporária comum: '.$password);
    $this->warn('Execute novamente apenas se quiser redefinir a senha desses três acessos.');

    return 0;
})->purpose('Cria contas locais de demonstração para administrador, familiar e cuidador');

Artisan::command('care:tick', function () {
    app(MedicationService::class)->generate();
    app(ReminderService::class)->process();
    app(ReminderService::class)->dispatchPending();
    $this->info('Ocorrências e lembretes processados.');
})->purpose('Gera ocorrências, materializa lembretes e recupera entregas pendentes');
Schedule::command('care:tick')->everyMinute()->withoutOverlapping(5);
if (config('care.queue_via_scheduler')) {
    // Sem worker permanente: a cada minuto processa o que estiver na fila e encerra.
    Schedule::command('queue:work --stop-when-empty --tries=1 --timeout=45 --max-time=50')->everyMinute()->withoutOverlapping(2);
}
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

Artisan::command('care:password {email}', function () {
    $email = Str::lower(trim($this->argument('email')));
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Informe um e-mail válido.');

        return 1;
    }

    $user = User::where('email', $email)->first();
    if (! $user) {
        $this->error('Não existe uma conta com esse e-mail.');

        return 1;
    }

    $password = $this->secret('Nova senha (mínimo 8 caracteres, com letras e números)');
    $confirmation = $this->secret('Confirme a nova senha');
    if (! is_string($password) || mb_strlen($password, 'UTF-8') < 8
        || ! preg_match('/\p{L}/u', $password)
        || ! preg_match('/[0-9]/', $password)
        || ! hash_equals($password, (string) $confirmation)) {
        $this->error('Senha inválida ou confirmação diferente. Nenhuma alteração foi feita.');

        return 1;
    }

    DB::transaction(function () use ($user, $password): void {
        $user->password = $password;
        $user->remember_token = Str::random(60);
        $user->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();
    });

    $this->info('Senha atualizada. Entre novamente na aplicação.');
})->purpose('Redefine uma senha localmente sem exibi-la no terminal');

Artisan::command('care:system-admin {email}', function () {
    $email = Str::lower(trim($this->argument('email')));
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Informe um e-mail válido.');

        return 1;
    }

    $user = User::where('email', $email)->first();
    if (! $user) {
        $this->error('Crie a conta familiar primeiro e execute este comando novamente.');

        return 1;
    }
    if ($user->role !== 'system_admin' && ! $this->confirm('Promover esta conta a administradora global?')) {
        $this->info('Nenhuma alteração foi feita.');

        return 0;
    }

    $password = $this->secret('Nova senha (mínimo 8 caracteres, com letras e números)');
    $confirmation = $this->secret('Confirme a nova senha');
    if (! is_string($password) || mb_strlen($password, 'UTF-8') < 8
        || ! preg_match('/\p{L}/u', $password)
        || ! preg_match('/[0-9]/', $password)
        || ! hash_equals($password, (string) $confirmation)) {
        $this->error('Senha inválida ou confirmação diferente. Nenhuma alteração foi feita.');

        return 1;
    }

    DB::transaction(function () use ($user, $password): void {
        $user->role = 'system_admin';
        $user->active = true;
        $user->password = $password;
        $user->remember_token = Str::random(60);
        $user->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();
    });

    $this->info('Conta promovida a administradora global e senha atualizada. Entre novamente na aplicação.');
})->purpose('Promove uma conta existente a administradora global com confirmação local');
