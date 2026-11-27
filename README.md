# Ideale Home Care

Plataforma web para organizar o cuidado domiciliar de idosos. Família, cuidadores e equipe da Ideale passam a trabalhar no mesmo lugar: agenda do dia, medicamentos com horário, tarefas com responsável, diário de evolução com medições, notificações e controle financeiro.

## Por que o projeto existe

Quando um idoso é cuidado em casa, as informações costumam ficar espalhadas entre grupos de mensagem, cadernos e a memória de quem está de plantão. Isso leva a doses esquecidas ou repetidas, troca de turno sem contexto, família sem saber o que aconteceu no dia e gastos sem controle. O Ideale Home Care reúne essas informações num único histórico, com permissões por perfil: cada pessoa vê e faz somente o que lhe cabe.

## Perfis de acesso

| Perfil | Quem é | O que faz |
| --- | --- | --- |
| Familiar gestor (`family` + vínculo `manager`) | Responsável pelo idoso | Cadastra pacientes, medicamentos e tarefas, convida a rede de cuidado, registra evolução e controla o financeiro |
| Familiar (`family` + vínculo `relative`) | Outros parentes | Acompanha o idoso e registra tarefas, medicamentos e evolução; não gerencia a rede nem os convites |
| Profissional (`ideale_professional`) | Cuidador da Ideale | Vê a agenda de atendimentos, confirma visitas, administra medicamentos, conclui tarefas e registra evolução |
| Equipe administrativa (`ideale_admin`) | Coordenação da Ideale | Acompanha o painel da equipe e os idosos vinculados |
| Idoso (`elder`) | O próprio paciente | Consulta a própria agenda e confirma os próprios medicamentos e tarefas |
| Administrador do sistema (`system_admin`) | Operador da plataforma | Visão global somente leitura e suspensão/reativação de contas |

A equipe só acessa idosos aos quais foi vinculada por convite da família. A API aplica as permissões independentemente do menu exibido.

## Estrutura

```
backend/    API Laravel 13 (PHP 8.5, MySQL 8.4, Sanctum, filas, Reverb, Web Push)
frontend/   SPA Vue 3 + Vite que consome a API
```

## Como executar localmente

Requisitos: PHP 8.5, Composer 2, MySQL 8.4 e Node.js 22.

### 1. Backend

```powershell
Set-Location backend
composer install
Copy-Item .env.example .env
php artisan key:generate
# Configure DB_* em .env antes de migrar.
php artisan migrate
php artisan care:demo-users   # opcional: cria admin, familiar e cuidador de demonstração (somente local)
php artisan serve --host=127.0.0.1
```

Em terminais separados, rode o worker e o agendador (geram as ocorrências de medicamentos e os lembretes):

```powershell
php artisan queue:work --tries=1 --timeout=45
php artisan schedule:work
```

### 2. Frontend

```powershell
Set-Location frontend
npm install
Copy-Item .env.example .env.local
npm run dev
```

Acesse `http://localhost:5173`. Se o backend estiver em outro endereço, ajuste `VITE_API_BASE_URL` em `.env.local` (sem `/` no final).

Com `MAIL_MAILER=log`, os links de confirmação de e-mail e de recuperação de senha ficam em `backend/storage/logs/laravel.log`.

## Testes

```powershell
Set-Location backend
php artisan test
```

O GitHub Actions roda os testes do backend e o build do frontend a cada push.

## Documentação

- [Instalação e funcionalidades do backend](backend/README.md)
- [Frontend](frontend/README.md)
- [Contrato de uso da API](backend/API.md)
- [OpenAPI 3.1](backend/docs/openapi.json), também em `GET /api/v1/openapi.json`
- [Operação, publicação e recuperação](backend/docs/OPERACAO.md)
- [Escopo e evidências de validação](backend/docs/ENTREGA.md)

Em produção, a raiz pública do servidor deve apontar para `backend/public/` e o conteúdo de `frontend/dist/` deve ser publicado em HTTPS. Notificações em tempo real (Reverb) e Web Push estão descritas no guia de operação.
