<?php

use App\Models\CareMembership;
use App\Models\Elder;
use App\Models\EmergencyContact;
use App\Models\FamilyEvent;
use App\Models\FinanceEntry;
use App\Models\Measurement;
use App\Models\Medication;
use App\Models\MedicationOccurrence;
use App\Models\MedicationSchedule;
use App\Models\Observation;
use App\Models\Professional;
use App\Models\PushDelivery;
use App\Models\RealtimeDelivery;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\TaskCompletion;
use App\Models\User;
use App\Models\Visit;
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

Artisan::command('care:demo-data', function () {
    if (! app()->environment(['local', 'testing'])) {
        $this->error('Os dados de demonstração só podem ser criados nos ambientes local e testing (use --env=local).');

        return 1;
    }

    $familia = User::where('email', 'familia.demo@ideale.test')->first();
    $cuidador = User::where('email', 'cuidador.demo@ideale.test')->first();
    $professional = $cuidador ? Professional::where('user_id', $cuidador->id)->first() : null;
    if (! $familia || ! $professional) {
        $this->error('Rode antes: php artisan care:demo-users');

        return 1;
    }
    if (Elder::where('name', 'José Carlos Souza')->where('created_by', $familia->id)->exists()) {
        $this->info('Os dados de demonstração já existem; nada foi alterado.');

        return 0;
    }

    $tz = 'America/Cuiaba';
    $hoje = CarbonImmutable::today($tz);
    $em = fn (string $hora, int $dias = 0) => $hoje->addDays($dias)->setTimeFromTimeString($hora)->utc();

    DB::transaction(function () use ($familia, $cuidador, $professional, $tz, $hoje, $em) {
        $maria = Elder::where('created_by', $familia->id)->where('name', 'Idoso de demonstração')->first()
            ?? new Elder(['created_by' => $familia->id]);
        $maria->fill(['name' => 'Maria Aparecida Souza', 'birth_date' => '1944-03-12', 'timezone' => $tz,
            'medical_information' => 'Hipertensão e diabetes tipo 2. Usa andador.', 'active' => true])->save();
        $jose = Elder::create(['created_by' => $familia->id, 'name' => 'José Carlos Souza', 'birth_date' => '1941-08-30', 'timezone' => $tz,
            'medical_information' => 'Pós-operatório de quadril. Fisioterapia três vezes por semana.']);

        foreach ([$maria, $jose] as $idoso) {
            CareMembership::updateOrCreate(['elder_id' => $idoso->id, 'user_id' => $familia->id], ['role' => 'manager', 'active' => true]);
            CareMembership::updateOrCreate(['elder_id' => $idoso->id, 'user_id' => $cuidador->id], ['role' => 'team', 'active' => true]);
        }

        $remedios = [
            [$maria, 'Losartana 50 mg', '1 comprimido', 'Tomar com água, de manhã e à noite.', ['08:00', '20:00'], null],
            [$maria, 'Metformina 850 mg', '1 comprimido', 'Após o almoço.', ['12:30'], null],
            [$maria, 'Vitamina D 2.000 UI', '1 cápsula', 'Junto com o café da manhã.', ['08:00'], $hoje->addDays(60)],
            [$jose, 'Dipirona 500 mg', '1 comprimido', 'Se dor, de 6 em 6 horas.', ['07:00', '13:00', '19:00'], $hoje->addDays(15)],
        ];
        foreach ($remedios as [$idoso, $nome, $dose, $instrucoes, $horarios, $fim]) {
            $med = Medication::create(['elder_id' => $idoso->id, 'created_by' => $familia->id, 'name' => $nome, 'dosage' => $dose,
                'instructions' => $instrucoes, 'starts_on' => $hoje->subDays(30)->format('Y-m-d'), 'ends_on' => $fim?->format('Y-m-d')]);
            foreach ($horarios as $h) {
                MedicationSchedule::create(['medication_id' => $med->id, 'local_time' => $h, 'timezone' => $tz, 'dosage' => $dose,
                    'effective_at' => $hoje->subDays(7)->utc()]);
            }
            app(MedicationService::class)->materialize($med);
        }
        // Doses que já passaram do horário aparecem como dadas pelo cuidador.
        foreach (MedicationOccurrence::whereIn('elder_id', [$maria->id, $jose->id])->where('scheduled_at', '<=', now()->subMinutes(30))->get() as $o) {
            $o->update(['status' => 'confirmed', 'confirmed_by' => $cuidador->id, 'confirmed_at' => $o->scheduled_at->addMinutes(5)]);
            Reminder::where('source_type', 'medication')->where('source_id', $o->id)->update(['status' => 'cancelled']);
        }

        $tarefas = [
            [$maria, 'Banho assistido', null, '08:30', 0, [$cuidador], true],
            [$maria, 'Caminhada leve no quintal', '15 minutos com o andador.', '09:30', 0, [$cuidador], true],
            [$maria, 'Medir glicemia antes do jantar', 'Anotar o valor na evolução.', '18:00', 0, [$familia, $cuidador], false],
            [$maria, 'Trocar curativo do pé esquerdo', 'Usar soro fisiológico e gaze estéril.', '19:00', 0, [$cuidador], false],
            [$maria, 'Comprar fitas de glicemia', null, '10:00', 1, [$familia], false],
            [$jose, 'Exercícios de alongamento', 'Série passada pela fisioterapeuta.', '17:00', 0, [$familia, $cuidador], false],
        ];
        foreach ($tarefas as [$idoso, $titulo, $desc, $hora, $dias, $resp, $feita]) {
            $t = Task::create(['elder_id' => $idoso->id, 'created_by' => $familia->id, 'title' => $titulo, 'description' => $desc,
                'due_at' => $em($hora, $dias), 'status' => $feita ? 'completed' : 'pending', 'version' => $feita ? 2 : 1]);
            $t->assignees()->attach(collect($resp)->pluck('id'));
            if ($feita) {
                TaskCompletion::create(['task_id' => $t->id, 'user_id' => $cuidador->id, 'completed_at' => $em($hora)->addMinutes(20)]);
            }
        }

        $diario = [
            [$maria, $familia, 'Dormiu a noite toda, acordou às 6h30 sem queixas.', ['sono'], $em('06:40')],
            [$maria, $cuidador, 'Almoçou bem, aceitou toda a refeição. Disposta e conversando bastante.', ['alimentação', 'humor'], $em('12:10')],
            [$maria, $cuidador, 'Reclamou de leve dor no joelho direito ao subir o degrau da varanda.', ['dor', 'mobilidade'], $em('17:20', -1)],
            [$jose, $cuidador, 'Fez os exercícios com ajuda. Amplitude do quadril melhorando.', ['fisioterapia'], $em('15:05', -1)],
        ];
        foreach ($diario as [$idoso, $autor, $texto, $tags, $quando]) {
            Observation::create(['elder_id' => $idoso->id, 'user_id' => $autor->id, 'text' => $texto, 'tags' => $tags, 'occurred_at' => $quando]);
        }
        $medicoes = [
            [$maria, 'temperature', 36.4, null, '°C', $em('07:05')],
            [$maria, 'glucose', 112, null, 'mg/dL', $em('07:10')],
            [$maria, 'blood_pressure', 128, 82, 'mmHg', $em('11:30')],
            [$maria, 'blood_pressure', 131, 84, 'mmHg', $em('11:20', -1)],
            [$jose, 'blood_pressure', 135, 85, 'mmHg', $em('14:20', -1)],
        ];
        foreach ($medicoes as [$idoso, $tipo, $v1, $v2, $un, $quando]) {
            Measurement::create(['elder_id' => $idoso->id, 'user_id' => $cuidador->id, 'type' => $tipo, 'value' => $v1,
                'secondary_value' => $v2, 'unit' => $un, 'measured_at' => $quando]);
        }

        Visit::create(['elder_id' => $maria->id, 'professional_id' => $professional->id, 'created_by' => $cuidador->id,
            'specialty' => $professional->specialty, 'starts_at' => $em('10:00'), 'ends_at' => $em('12:00'), 'status' => 'confirmed', 'version' => 2]);
        Visit::create(['elder_id' => $jose->id, 'professional_id' => $professional->id, 'created_by' => $cuidador->id,
            'specialty' => $professional->specialty, 'starts_at' => $em('14:00', 1), 'ends_at' => $em('15:00', 1)]);
        FamilyEvent::create(['elder_id' => $jose->id, 'created_by' => $familia->id, 'title' => 'Consulta com ortopedista',
            'description' => 'Levar os exames de imagem.', 'starts_at' => $em('16:30', 2), 'ends_at' => $em('17:30', 2)]);
        EmergencyContact::create(['elder_id' => $maria->id, 'name' => 'Central Ideale Home Care', 'phone' => '+556533330000', 'type' => 'central', 'priority' => 1]);

        $financeiro = [
            ['Aposentadoria INSS — Maria', '3200.00', 'income', 'Renda', -20, 'paid'],
            ['Plano de cuidado Ideale — mês atual', '2400.00', 'expense', 'Cuidadores', -15, 'paid'],
            ['Farmácia — remédios do mês', '386.90', 'expense', 'Medicamentos', -13, 'paid'],
            ['Fraldas e material de higiene', '214.50', 'expense', 'Higiene', -7, 'paid'],
            ['Contribuição Lucas', '600.00', 'income', 'Família', 1, 'pending'],
            ['Fisioterapia domiciliar — José', '720.00', 'expense', 'Saúde', 3, 'pending'],
        ];
        foreach ($financeiro as [$desc, $valor, $tipo, $cat, $dias, $situacao]) {
            FinanceEntry::create(['user_id' => $familia->id, 'description' => $desc, 'amount' => $valor, 'type' => $tipo,
                'category' => $cat, 'due_date' => $hoje->addDays($dias)->format('Y-m-d'), 'status' => $situacao]);
        }
    });

    app(ReminderService::class)->process();
    $this->info('Dados de demonstração criados: 2 idosos, remédios, tarefas, evolução, visitas e financeiro.');

    return 0;
})->purpose('Preenche as contas de demonstração com dados fictícios de cuidado (somente local/testing)');

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
