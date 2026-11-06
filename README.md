# Ideale Home Care

O backend da versão V2 está na pasta [`backend/`](backend/README.md). A aplicação oferece API Laravel para redes de cuidado, tarefas, medicamentos, diário, medições, visitas, agenda, contatos e notificações.

## Executar o backend

Requisitos: PHP 8.5, Composer 2 e MySQL 8.4. Configure um banco exclusivo da aplicação.

```powershell
Set-Location backend
composer install
Copy-Item .env.example .env
php artisan key:generate
# Configure DB_* e as URLs em .env antes de migrar.
php artisan migrate
php artisan serve --host=127.0.0.1
```

Execute também o worker e o agendador, em terminais separados:

```powershell
php artisan queue:work --tries=1 --timeout=45
php artisan schedule:work
```

Para notificações em tempo real, configure Reverb conforme o [guia de operação](backend/docs/OPERACAO.md). Web Push precisa das chaves VAPID e da integração do navegador.

## Documentação

- [Instalação e funcionalidades](backend/README.md)
- [Contrato de uso da API](backend/API.md)
- [OpenAPI 3.1](backend/docs/openapi.json), também disponível em `GET /api/v1/openapi.json`
- [Operação, publicação e recuperação](backend/docs/OPERACAO.md)
- [Escopo e evidências de validação](backend/docs/ENTREGA.md)

O frontend Vue consome a API separadamente. Em produção, a raiz pública do servidor deve apontar para `backend/public/`.
