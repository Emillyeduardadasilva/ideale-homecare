# Ideale Home Care — frontend

Aplicação Vue 3 com Vite. As telas usam a API Laravel em `backend/`.

## Desenvolvimento

Requisitos: Node.js compatível com Vite 8.

```powershell
npm install
Copy-Item .env.example .env.local
npm run dev
```

O backend deve estar disponível em `http://localhost:8000`. Edite `VITE_API_BASE_URL` em `.env.local` para usar outra origem.

## Sessão e notificações

O cliente usa sessão Sanctum, cookies e CSRF. Configure as origens permitidas no `.env` do backend e use HTTPS em produção. O endereço da API não deve terminar com `/`.

Web Push é ativado na página **Notificações**. O servidor precisa fornecer `VAPID_PUBLIC_KEY` em `GET /api/v1/push/public-key`; o navegador pede permissão após o usuário clicar em **Ativar neste dispositivo**. O Service Worker fica em `public/sw.js`, mostra somente o título e o texto genérico enviados pelo backend e abre a central de notificações ao clicar.

Reverb é opcional. Copie `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT` e `VITE_REVERB_SCHEME` para `.env.local` usando a configuração do servidor. O frontend autentica o canal privado por sessão em `/api/v1/broadcasting/auth` e atualiza a caixa de notificações ao receber um sinal. O segredo Reverb nunca deve ser incluído nas variáveis `VITE_*`.

## Produção

```sh
npm run build
```

Publique o conteúdo de `dist/` em HTTPS. Defina as variáveis `VITE_*` antes do build; o Vite incorpora esses valores no bundle do cliente. Não coloque senhas, segredos ou chaves privadas em variáveis `VITE_*`.

## Perfis de acesso

A família gerencia sua própria rede de cuidado. Contas `ideale_admin` e `ideale_professional` abrem o painel do cuidador em `/cuidador` e veem somente idosos vinculados por convite. `system_admin` abre a visão global em `/admin`, consulta os dados e gerencia o status das contas; não altera registros clínicos. A API aplica as mesmas permissões independentemente do menu exibido.
O build de produção usa /api/v1 no próprio domínio como padrão. A inicialização de sessão acompanha a origem da API configurada. Para publicar no plano M, consultar o [guia HostGator](../deploy/hostgator/README.md).
