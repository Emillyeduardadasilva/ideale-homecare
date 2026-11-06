# Contrato de uso da API

Base: `/api/v1`. JSON com `Accept: application/json`. Login e mutações exigem cookie de sessão e proteção CSRF. Todas as rotas de cuidado exigem conta ativa e e-mail verificado. As respostas de item usam `data`; listas usam paginação Laravel. Nomes entre `{}` são IDs inteiros.

Contrato OpenAPI 3.1 completo em [`docs/openapi.json`](docs/openapi.json) e `GET /api/v1/openapi.json`. Erros usam `{message, error: {code, message, fields?}, request_id}`; `errors` permanece disponível para compatibilidade com validações Laravel. Respostas de cuidado usam `Cache-Control: no-store, private`. Detalhes internos, SQL e rastros de exceção não são retornados.

Instantes aceitam offset explícito (`2026-11-06T08:00:00-04:00`) ou UTC com `Z`, com ou sem fração de segundo (`2026-11-06T12:00:00.000Z`). Datas impossíveis e horários sem fuso são rejeitados; a persistência utiliza UTC.

`Idempotency-Key` é opcional em POST/PUT/DELETE de cuidado: use uma chave aleatória de 16–128 caracteres e a mesma chave para repetir a mesma requisição por falha de rede. Durante 24 horas, conteúdo diferente com a mesma chave retorna 409. Após esse prazo, a chave inicia uma nova operação, mesmo que a limpeza diária ainda não tenha ocorrido. Respostas são armazenadas criptografadas, e a limpeza diária remove registros vencidos. GETs não registram leituras de diário ou notificações automaticamente.

## Conta

| Método e caminho | Corpo |
|---|---|
| POST `/auth/register` | `name`, `email`, `password`, `password_confirmation` (mínimo 12 caracteres, letras e números) |
| POST `/auth/login` | `email`, `password` |
| GET `/auth/me` | — |
| POST `/auth/logout` | — |
| POST `/auth/forgot-password` | `email` |
| POST `/auth/reset-password` | `email`, `token`, `password`, `password_confirmation` |
| POST `/auth/email/verification-notification` | — |
| GET `/auth/email/verify/{id}/{hash}` | Usar a URL assinada recebida, sem modificá-la |

## Idosos e rede

| Método e caminho | Corpo/filtro |
|---|---|
| GET `/elders` | `page` opcional; somente a rede autorizada |
| POST `/elders` | `name`, `birth_date`, `timezone`, `medical_information` opcional |
| GET `/elders/{elder}` | — |
| PUT `/elders/{elder}` | mesmos campos do POST + `version`; `active` opcional |
| GET `/elders/{elder}/memberships` | gestor |
| GET `/elders/{elder}/invitations` | convites paginados, somente gestor; não revela tokens |
| POST `/elders/{elder}/invitations` | `email`, `role`: `relative`, `team` ou `elder` |
| POST `/invitations/accept` | `token` retornado no convite |
| DELETE `/elders/{elder}/invitations/{invitation}` | revoga convite ainda não aceito |
| DELETE `/elders/{elder}/memberships/{membership}` | revoga vínculo, exceto gestor |
| DELETE `/elders/{elder}/elder-account` | desvincula conta do próprio idoso |

Convite retorna `data`, `invitation_token` e `invitation_url`; expira em sete dias. O token só aparece na criação. Aceitar exige o mesmo e-mail verificado. Exemplo de criação:

```json
{"name":"Pessoa acompanhada","birth_date":"1940-02-10","timezone":"America/Cuiaba"}
```

## Tarefas

Prefixo `/elders/{elder}`. GET `/tasks`; POST `/tasks`; PUT `/tasks/{task}`.

```json
{"title":"Caminhada acompanhada","description":"Atividade familiar","due_at":"2026-10-07T08:00:00-04:00","assignees":[12,13]}
```

PUT exige `version`. Os responsáveis devem ter vínculo familiar ativo. POST `/tasks/{task}/complete` não exige corpo; identidade e horário são definidos pelo servidor. POST `/tasks/{task}/cancel` recebe `version`. Conclusão duplicada não gera outro registro. Estados: `pending`, `completed`, `cancelled`.

## Medicamentos

GET/POST `/elders/{elder}/medications`; PUT `/elders/{elder}/medications/{medication}`.

```json
{"name":"Nome informado","dosage":"Dose informada pela família","instructions":"Texto opcional","starts_on":"2026-10-07","ends_on":null,"times":["08:00","20:00"]}
```

PUT recebe também `version` e `active`. A edição substitui regras futuras, cancela ocorrências futuras pendentes e mantém confirmações e passado. Não converte aviso entregue em medicação tomada.

- GET `/elders/{elder}/medication-occurrences?date=2026-10-07`: ocorrências datadas, paginadas.
- POST `/elders/{elder}/medication-occurrences/{occurrence}/confirm`: sem corpo; registra relato pelo ator autenticado. Não aceita ocorrência futura ou cancelada.

## Diário e medições

GET/POST `/elders/{elder}/observations`.

```json
{"text":"Observação da família","occurred_at":"2026-10-07T08:30:00-04:00","tags":["pouco_apetite"],"corrects_id":null}
```

Use `corrects_id` para retificar uma nota do próprio autor. Não há PUT/DELETE de observação.

GET/POST `/elders/{elder}/measurements`.

```json
{"type":"blood_pressure","value":120,"secondary_value":80,"measured_at":"2026-10-07T08:30:00-04:00"}
```

Tipos: `blood_pressure` (mmHg; dois valores), `glucose` (mg/dL; um valor) e `temperature` (°C; um valor). Unidade é definida pelo servidor. Valores são registros informados; não há interpretação clínica automática.

## Visitas e pré-atendimento

- GET `/elders/{elder}/professionals`: profissionais ativos vinculados, para o administrativo.
- GET/POST `/elders/{elder}/visits`; PUT `/elders/{elder}/visits/{visit}`.

```json
{"professional_id":5,"starts_at":"2026-10-07T10:00:00-04:00","ends_at":"2026-10-07T11:00:00-04:00"}
```

PUT exige `version`. Reagendamento volta a `scheduled`. Sobreposição do profissional gera 409, inclusive entre redes diferentes. Especialidade é copiada do profissional, não aceita arbitrariamente no corpo.

- POST `/elders/{elder}/visits/{visit}/status`: `{"status":"confirmed","version":1}`. Transições: `scheduled → confirmed → completed`; `scheduled/confirmed → cancelled`. Concluir exige início ocorrido. Profissional pode confirmar/concluir somente a própria visita; cancelar requer administrativo vinculado.
- GET `/elders/{elder}/visits/{visit}/briefing`: observações paginadas e `criteria`; apenas profissional responsável.
- POST `/elders/{elder}/visits/{visit}/observation-reads`: `{"observation_ids":[11,12]}`. Marca IDs específicos do mesmo idoso e mantém a primeira leitura.

## Agenda, contatos e escala

- GET `/elders/{elder}/calendar?date=2026-10-07`: projeção diária por fuso, com IDs compostos (`task:1`, `visit:2` etc.). Eventos que atravessam meia-noite entram por sobreposição. Para modo acessível do idoso, use a mesma rota com a sessão dele.
- GET `/elders/{elder}/daily-view?date=2026-10-07`: `data` contém `elder` mínimo, `date`, `timezone`, `items` e `quick_call` (ou `null`). A data é opcional e assume hoje no fuso do idoso. Cada item informa `allowed_actions` conforme conta, vínculo e estado atual. A agenda também informa essas ações; elas orientam a interface e não substituem a autorização na escrita.
- POST `/elders/{elder}/family-events`; PUT `/elders/{elder}/family-events/{event}`: `title`, `description` opcional, `starts_at`, `ends_at`; PUT também `version` e `active`.
- GET/POST `/elders/{elder}/contacts`; PUT `/elders/{elder}/contacts/{contact}`: `name`, `phone` internacional (`+5565999990000`), `type` (`family`/`central`), `priority` (0–999), `active` opcional. PUT exige também a `version` retornada pelo GET/POST; cada edição incrementa a versão, e edição desatualizada retorna 409.
- GET/POST `/elders/{elder}/care-shifts`: POST recebe `emergency_contact_id`, `starts_at`, `ends_at`. Contato deve ser familiar ativo. Escalas sobrepostas retornam 409.
- PUT `/elders/{elder}/care-shifts/{shift}`: mesmos campos do POST e `version`; corrige uma escala ativa. DELETE no mesmo caminho recebe `version` e cancela sem apagar o histórico. Uma escala cancelada não ocupa o intervalo nem participa da chamada rápida.
- GET `/elders/{elder}/quick-call`: retorna `name`, `phone`, `dial_uri`, `type`; 404 quando não existe responsável atual nem central ativa.

## Notificações

- GET `/notifications`: caixa paginada do usuário com acesso atual aos idosos.
- GET `/notifications?after_id=123`: somente IDs maiores que 123, em ordem crescente. Use `next_after_id` para recuperar novas páginas após desconexão, sempre reiniciando `page=1` ao avançar o cursor.
- POST `/notifications/{notification}/read`: marca leitura, sem corpo.
- GET `/push/public-key`: `public_key`, nula até configurar VAPID.
- POST `/push/subscriptions`: inscrição criada pelo navegador (exemplo estrutural abaixo).
- DELETE `/push/subscriptions/{subscription}`: remove somente inscrição da própria conta.

```json
{"endpoint":"https://fcm.googleapis.com/fcm/send/ENDPOINT_REAL","keys":{"p256dh":"CHAVE_REAL_DO_NAVEGADOR","auth":"SEGREDO_REAL_DO_NAVEGADOR"}}
```

Use valores reais de `PushSubscription.toJSON()`; placeholders são rejeitados. Não envie conteúdo de saúde no endpoint público do provedor push. Veja README para Service Worker, configuração VAPID e operação da fila.

Reverb: POST `/broadcasting/auth` recebe `socket_id` e `channel_name`; exige sessão, CSRF, conta ativa e e-mail verificado. Cada usuário assina exclusivamente `private-care.users.{id}`. O evento `care.reminder.available` contém apenas `notification_id`. O histórico persistido continua acessível pela caixa de notificações. Configuração e recuperação em [`docs/OPERACAO.md`](docs/OPERACAO.md).
