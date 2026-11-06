# Ideale Home Care — backend Laravel

API implementada em Laravel 13 para o Ideale Home Care V2. Inclui aplicação, migrations, testes e dependências fixadas em `composer.lock`. O frontend Vue é um consumidor separado.

## Instalação

Requisitos: PHP 8.5 (PDO MySQL, OpenSSL, mbstring, cURL, XML, fileinfo e intl), Composer 2 e MySQL 8.4 LTS.

```sh
composer install
cp .env.example .env
php artisan key:generate
# Configure DB_* em .env e crie um banco MySQL vazio.
php artisan migrate
php artisan serve --host=127.0.0.1
```

No PowerShell, use `Copy-Item .env.example .env`. Não há usuários ou senhas predefinidos. Não execute `migrate:fresh` no banco da aplicação.

Em dois processos adicionais:

```sh
php artisan queue:work --tries=1 --timeout=45
php artisan schedule:work
```

Em produção, use HTTPS e a pasta **`public/`** como raiz pública, supervisione os workers e execute `php artisan schedule:run` a cada minuto. Fila, cache e sessões usam MySQL. Após publicar código: `php artisan migrate --force`, `php artisan optimize`, `php artisan queue:restart`. Configure `APP_DEBUG=false`, `LOG_LEVEL=warning`, `SESSION_SECURE_COOKIE=true`, SMTP real, backups e criptografia do armazenamento. Preserve a `APP_KEY`; regenerá-la torna os campos criptografados existentes ilegíveis.

## Funcionalidades

- Cadastro familiar, sessão, confirmação de e-mail e recuperação de senha.
- Rede por idoso, convites, revogação e conta do próprio idoso.
- Tarefas compartilhadas, responsáveis e confirmação persistente.
- Medicamentos, horários diários, ocorrências datadas e confirmação relatada.
- Diário com tags e retificações; pressão arterial, glicemia e temperatura.
- Visitas, reagendamento, estados e prevenção de sobreposição de profissionais.
- Pré-atendimento e confirmação explícita de leitura das observações.
- Agenda unificada no fuso do idoso.
- Visão diária mínima com ações permitidas para cada usuário.
- Contatos, escala de responsável e telefone para chamada rápida.
- Notificações persistentes e Web Push com fila, recuperação e tentativas limitadas.
- Sinalização em tempo real com Reverb, canal privado por usuário e recuperação por cursor.
- Auditoria sem conteúdo clínico, controle de versões e idempotência opcional nas mutações.

As tabelas e rotas estão em inglês. `users`, `elders`, `medications`, `observations` e `visits` correspondem às entidades do PDF, complementadas pelas tabelas de vínculos e históricos. Não há módulo de faturamento, diagnóstico ou prescrição no escopo V2.

## Login e vínculos

Use sessão Sanctum. No Vue, habilite `credentials: include` ou Axios `withCredentials` e `withXSRFToken`. Obtenha `/sanctum/csrf-cookie` antes do login; envie `X-XSRF-TOKEN` nas alterações e `Accept: application/json`. Configure `APP_URL`, `FRONTEND_URL` e `SANCTUM_STATEFUL_DOMAINS`. Publique preferencialmente na mesma origem, ou no mesmo domínio principal com cookies adequados.

1. Cadastre a conta familiar em `POST /api/v1/auth/register` e confirme o e-mail.
2. Crie o idoso em `POST /api/v1/elders`; a conta vira gestora dessa rede.
3. O gestor gera um convite e compartilha a `invitation_url`. Não há envio automático do convite por e-mail.
4. O destinatário entra com o e-mail verificado correspondente e aceita o token.

`MAIL_MAILER=log` grava links somente no log local. Configure SMTP para enviar confirmação e recuperação de senha reais.

Perfis globais: `family`, `elder`, `ideale_admin`, `ideale_professional`. Vínculos: `manager`, `relative`, `team`. A equipe precisa de vínculo por idoso; não possui acesso global. Convite `team` não eleva conta familiar. A conta `elder` deve estar livre de vínculos familiares; o gestor pode desvinculá-la depois.

Provisionamento de equipe pelo operador do servidor:

```sh
php artisan care:staff equipe@example.test --name="Administrativo" --role=ideale_admin
php artisan care:staff profissional@example.test --name="Profissional" --role=ideale_professional --specialty="Fisioterapia"
```

Os comandos criam senha aleatória não exibida. A pessoa usa recuperação de senha, confirma o e-mail e aceita convite da família. Não existe endpoint público para elevar perfil.

| Operação | Gestor | Parente | Idoso | Administrativo vinculado | Profissional vinculado |
|---|---|---|---|---|---|
| Consultar dados de cuidado | Sim | Sim | Próprios | Sim | Sim |
| Gerir perfil, contatos, escala e convites | Sim | Não | Não | Não | Não |
| Criar tarefas, remédios, diário, medições e eventos | Sim | Sim | Não | Não | Não |
| Confirmar tarefa | Se responsável | Se responsável | Própria | Não | Não |
| Relatar confirmação de medicação | Sim | Sim | Própria | Não | Não |
| Agendar/reagendar/cancelar visita | Não | Não | Não | Sim | Não |
| Confirmar/concluir visita | Não | Não | Não | Sim | Própria |
| Confirmar leitura do diário | Não | Não | Não | Não | Na própria visita |

Inativação preserva histórico, bloqueia novas operações de cuidado e avisos e permite reativação pelo gestor. Eliminação definitiva/anonimização permanece pendente de validação.

## API

Veja **[API.md](API.md)** para corpos de requisição. `php artisan route:list --path=api --except-vendor` lista as rotas registradas. Instantes são ISO 8601 com offset; persistência em UTC. Datas: `YYYY-MM-DD`; horários: `HH:mm`. Listas usam paginação Laravel. PUTs versionados exigem `version`; edição desatualizada recebe 409. Validação: 422; não autenticado: 401; acesso negado: 403; registro fora do escopo: 404.

O contrato completo está em [OpenAPI 3.1](docs/openapi.json), também servido em `GET /api/v1/openapi.json`. O [guia de operação](docs/OPERACAO.md) cobre instalação, Reverb, filas, publicação e restauração. Consulte [escopo e validação](docs/ENTREGA.md) para as evidências desta entrega.

Para habilitar Reverb, execute `php artisan care:reverb`, transfira as credenciais geradas para o ambiente seguro, configure `BROADCAST_CONNECTION=reverb` e as variáveis `REVERB_*`, e inicie `php artisan reverb:start` em outro processo. Configure as origens autorizadas e o proxy TLS em produção. O canal privado usa a mesma sessão/CSRF da API e envia somente o ID da notificação.

## Push com aplicativo fechado

```sh
php artisan care:vapid
```

Copie as chaves de `storage/app/private/vapid.json` para `VAPID_PUBLIC_KEY` e `VAPID_PRIVATE_KEY` no ambiente seguro. Configure `VAPID_SUBJECT` com contato real, como `mailto:suporte@seu-dominio`. Preserve as chaves e nunca as versione.

**O Vue ainda precisa registrar um Service Worker**, pedir permissão, inscrever o navegador usando `GET /api/v1/push/public-key` e enviar a inscrição para `POST /api/v1/push/subscriptions`. O worker deve exibir `title` e `body`, usar `tag` para deduplicar e abrir o app no clique. Essa integração de frontend não está nesta pasta. Recebimento em segundo plano depende de HTTPS, permissão, conexão e suporte do navegador/sistema operacional.

O backend envia payload criptografado com `notification_id`, `title`, `body` e `tag`. O texto é genérico, sem nome do idoso ou medicamento. O aplicativo busca os dados autorizados ao abrir. Endpoints push aceitos são HTTPS de provedores conhecidos; redirecionamentos são desabilitados. Inscrições são criptografadas no banco.

Processamento a cada minuto. Visitas: aviso 24 horas antes ou no próximo processamento quando cadastradas mais perto do início. Medicação: aviso no horário da ocorrência. Jobs antigos revalidam origem e acesso; entrega é pelo menos uma vez. Até cinco tentativas, com espera crescente e recuperação de worker interrompido. A caixa no banco permite recuperar avisos ao abrir o app.

```sh
php artisan care:tick
php artisan care:status
php artisan care:status --json
php artisan care:retry-push 123
```

`care:status` informa pendências, falhas, tamanho da fila e maior atraso de lembretes e entregas em segundos. A opção `--json` permite coletar esses indicadores em monitoramento externo, sem conteúdo clínico. Defina os limites de alerta conforme o SLA operacional. Falhas de transporte são registradas no nível `warning` com IDs de entrega/notificação, categoria, tentativa e classe da exceção; mensagens de exceção, endpoints e payloads não são incluídos.

O transporte foi verificado com criptografia real e serviço HTTP simulado; envio a dispositivo real depende da integração Vue e configuração operacional.

## Regras desta versão

- Medicamentos usam horários fixos diários. Não há cálculo de dose nem interpretação de intervalos clínicos. O fuso fica fixo após cadastrar medicação. Geração: sete dias à frente, recuperação automática de até sete dias passados; indisponibilidades maiores exigem reconciliação. Horário local inexistente é omitido; repetido gera uma ocorrência.
- Tarefa tem conclusão única, sem reabertura. Observação é retificada por novo registro, preservando autoria e leitura antiga.
- Visitas usam intervalos semiabertos, sem tempo de deslocamento automático. Conclusão exige confirmação e início já ocorrido. O pré-atendimento usa a última visita realizada de qualquer especialidade e inclui notas inseridas depois sobre fatos antigos.
- Chamada rápida retorna `tel:` do responsável atual ou da central cadastrada; não efetua chamada nem registra socorro como realizado.
- Retenção, anonimização, SLA de alertas e publicação em produção precisam de definição operacional.

## Testes

```sh
php artisan test
vendor/bin/pint --test
composer validate --strict
```

A suíte padrão usa SQLite em memória. O teste de concorrência é pulado nesse modo. Para MySQL, crie um banco exclusivo terminado em **`_test`**: as tabelas dele serão recriadas. Use `.env.testing.example` como referência e defina `DB_CONNECTION=mysql`, `DB_DATABASE=ideale_test`, `DB_HOST`, `DB_PORT`, `DB_USERNAME` e `DB_PASSWORD` no ambiente do processo de testes, que tem precedência sobre `phpunit.xml`. A proteção da suíte rejeita bancos MySQL sem o sufixo `_test`.

Cobertura funcional: perfis, isolamento, convites, revogação, duplicatas, versões, medicação, diário, fusos, agenda, visitas e notificações. A concorrência usa dois processos e MySQL real. O transporte push usa a biblioteca real com HTTP simulado.

Referências: [Sanctum](https://laravel.com/docs/13.x/sanctum), [Queues](https://laravel.com/docs/13.x/queues), [Web Push PHP](https://github.com/web-push-libs/web-push-php). As versões exatas estão em `composer.lock`.
