# Operação do backend V2

## Instalação e atualização

Use PHP 8.5 com PDO MySQL, mbstring, OpenSSL, cURL, XML, fileinfo e intl; Composer 2; MySQL 8.4. Instale as dependências com `composer install`, configure `.env`, gere `APP_KEY` apenas em uma instalação nova e execute `php artisan migrate`.

As migrations novas acrescentam versão aos contatos, versão/cancelamento às escalas, índices de consulta e entregas duráveis do Reverb. Elas preservam os registros existentes. Nunca use `migrate:fresh` ou testes no banco da aplicação. A reversão de migrations deve ocorrer somente em ambiente identificado para esse fim; a publicação usa evolução do esquema para a frente.

Em uma atualização de ambiente existente, preserve a chave e faça backup antes da migração. Após validar a publicação:

```sh
php artisan migrate --force
php artisan optimize
php artisan queue:restart
php artisan reverb:restart
```

O último comando se aplica quando Reverb estiver habilitado. Supervisione o processo para que ele inicie novamente. A raiz pública deve ser `backend/public`; código, `.env` e `storage/app/private` não devem ser servidos pela web.

## Processos

| Processo | Comando de desenvolvimento | Operação |
|---|---|---|
| HTTP | `php artisan serve --host=127.0.0.1` | Em produção, PHP-FPM ou Apache atrás de HTTPS. |
| Worker | `php artisan queue:work --tries=1 --timeout=45` | Manter supervisionado; `retry_after` deve superar o timeout. |
| Agendador | `php artisan schedule:work` | Em produção, executar `schedule:run` a cada minuto. |
| Reverb | `php artisan reverb:start` | Manter supervisionado quando habilitado; publicar WebSocket com TLS. |

`care:tick` gera as ocorrências, materializa lembretes e recupera entregas pendentes. O banco mantém a caixa de notificações e os estados das entregas. Um aviso enviado ao provedor não significa que foi exibido ou lido.

## Sessões e correio

Configure `APP_URL`, `FRONTEND_URL` e `SANCTUM_STATEFUL_DOMAINS` com as origens reais, incluindo portas no desenvolvimento. O Vue precisa preservar cookies, obter `/sanctum/csrf-cookie` e enviar `X-XSRF-TOKEN` nas alterações. Login, logout, recuperação, verificação e autenticação do canal privado utilizam as mesmas proteções.

Em produção: `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, `SESSION_ENCRYPT=true` e configuração de cookies compatível com as origens usadas. Prefira a mesma origem para a SPA e API.

Configure SMTP para confirmação de e-mail e recuperação de senha. `MAIL_MAILER=log` é útil apenas no desenvolvimento e grava links no log. Não há usuários ou senhas predefinidos. Equipe é provisionada com `care:staff`, usa recuperação de senha e precisa aceitar convite para cada rede.

## Reverb e recuperação após desconexão

1. Execute `php artisan care:reverb`. As credenciais serão gravadas em `storage/app/private/reverb.json`, sem sobrescrever uma configuração existente.
2. Transfira os três valores `REVERB_APP_*` para o ambiente seguro. A chave pública pode ser usada pelo cliente; o segredo permanece no servidor.
3. Defina `BROADCAST_CONNECTION=reverb`, `REVERB_HOST`, `REVERB_PORT` e `REVERB_SCHEME`. Para desenvolvimento local, o padrão é `127.0.0.1:8080` com HTTP. Em produção, configure HTTPS/TLS no proxy e o endereço acessível ao backend.
4. Defina `REVERB_ALLOWED_ORIGINS` com os hostnames autorizados separados por vírgula, sem esquema. Configure `REVERB_SERVER_HOST/PORT` para o endereço de escuta. Reinicie os processos e limpe/refaça o cache de configuração ao alterar o ambiente.
5. O cliente autentica o canal em `POST /api/v1/broadcasting/auth` usando sessão e CSRF. Canal Echo: `care.users.{id}`; canal no protocolo: `private-care.users.{id}`. Somente o dono ativo e com e-mail verificado pode assiná-lo. Eventos enviados pelo cliente são desabilitados.
6. Escute `.care.reminder.available`. O payload contém somente `notification_id`. Busque o conteúdo autorizado pela API; não persista dados de saúde no armazenamento do navegador.

Exemplo do evento após configurar Laravel Echo e sua autenticação:

```js
echo.private(`care.users.${user.id}`)
    .listen('.care.reminder.available', ({ notification_id }) => {
        refreshAuthorizedNotifications(notification_id);
    });
```

Ao abrir ou reconectar, consulte `GET /api/v1/notifications`. O retorno inclui `next_after_id`. Para recuperar novos registros, use `?after_id=ID`; os resultados vêm em ordem crescente, até 30 por chamada. Continue com o `next_after_id` recebido e página 1 até não restarem itens. Deduplicate pelo ID. A caixa não marca leitura automaticamente.

As entregas Reverb são persistidas junto da notificação. Se o envio falhar ou o worker cair, uma execução posterior recupera a entrega após a expiração da reserva. Há até cinco tentativas com espera crescente. O job revalida conta, vínculo e origem; uma conexão antiga recebe no máximo um sinal sem conteúdo, e buscas posteriores continuam sujeitas à autorização.

## Web Push

Execute `php artisan care:vapid`, preserve as chaves privadas e configure `VAPID_SUBJECT`, `VAPID_PUBLIC_KEY` e `VAPID_PRIVATE_KEY`. O frontend precisa registrar Service Worker, obter permissão e cadastrar a inscrição real do navegador. O backend restringe os provedores, impede transferência de dispositivo entre contas e envia conteúdo genérico criptografado. O envio real depende do dispositivo, da permissão, da conexão e do suporte do navegador.

## Monitoramento e recuperação de entregas

```sh
php artisan care:status
php artisan care:status --json
php artisan care:retry-push ID
php artisan care:retry-realtime ID
```

Monitore atrasos dos lembretes, tamanho da fila, reservas expiradas, entregas com falha e presença dos processos. Os comandos de retry aceitam apenas falhas terminais; o processamento posterior revalida acesso e origem. Falhas de transporte registram categoria, tentativa e IDs técnicos, sem endpoint, texto clínico ou mensagem bruta de exceção. Use `request_id`/`X-Request-ID` para correlacionar erros HTTP.

Os limites de alerta, RPO, RTO, retenção e anonimização precisam ser definidos pela operação. A aplicação não escolhe esses prazos automaticamente. Campos clínicos além do perfil do idoso também exigem proteção no armazenamento e nos backups.

## Backup e restauração

Faça backup consistente do MySQL, proteja os arquivos e mantenha uma cópia isolada. Preserve também `APP_KEY`, eventuais chaves anteriores de criptografia e as credenciais de canais, separadamente do dump e com acesso restrito. O dump sozinho não recupera campos criptografados sem a chave.

Para executar ferramentas MySQL, use um arquivo de credenciais protegido ou outro mecanismo do operador; evite senha na linha de comando. Exemplo a adaptar ao ambiente:

```sh
mysqldump --defaults-extra-file=/secure/mysql-client.cnf --single-transaction --no-tablespaces --set-gtid-purged=OFF NOME_DO_BANCO > backup.sql
mysql --defaults-extra-file=/secure/mysql-restore.cnf BANCO_ISOLADO_DE_RESTAURACAO < backup.sql
```

Crie previamente o banco isolado. Compare contagens/checksums e relações entre vínculos, tarefas/conclusões, medicamentos/ocorrências e visitas/leituras. Teste a descriptografia com a configuração recuperada e cronometre o procedimento. Não restaure sobre o ambiente ativo durante um ensaio.

## Verificação

```sh
composer validate --strict
composer audit --locked
vendor/bin/pint --test
php artisan test
```

SQLite em memória cobre os fluxos comuns. Para concorrência, use MySQL exclusivo com nome terminado em `_test`, com as variáveis definidas no processo de testes. As tabelas desse banco serão recriadas. A suíte MySQL usa processos independentes para reservas, conclusões de tarefa e disputa de dispositivo.
