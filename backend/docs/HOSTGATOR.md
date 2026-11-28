# Publicar na HostGator (hospedagem compartilhada)

Este guia publica o sistema completo, com API e telas, em um único endereço, por exemplo `https://app.seudominio.com.br`. As telas do Vue são entregues pelo próprio Laravel, então login, cookies e convites funcionam sem configuração de domínios cruzados.

O que muda em relação ao ambiente local:

- A HostGator oferece PHP até 8.3. O projeto aceita PHP 8.3 e o `composer.json` fixa a resolução das bibliotecas nessa versão.
- Não há processos permanentes. A fila roda pelo cron do agendador (`QUEUE_VIA_SCHEDULER=true`).
- Não há Reverb (tempo real). Os avisos continuam na página **Notificações**.

## 1. O pacote

O `composer.lock` já está resolvido para PHP 8.3 e a suíte de testes passa nessa versão (57 testes; 3 exclusivos de MySQL pulados).

Para gerar o pacote no seu computador (PHP 8.3 ou superior, Composer 2 e Node.js 22):

```powershell
Set-Location backend
composer install --no-dev --optimize-autoloader
php artisan key:generate --show   # guarde a chave para o .env
Set-Location ..\frontend
npm ci
npm run build:hostgator
```

Depois compacte a pasta `backend` inteira, **sem** `.env`, `node_modules` e `storage/logs/*.log`. A pasta `vendor` precisa ir junto.

## 2. No cPanel da HostGator

1. **Versão do PHP:** em *Selecionar versão do PHP* (ou *MultiPHP Manager*), escolha **8.3** e ative as extensões `pdo_mysql`, `mbstring`, `openssl`, `curl`, `intl`, `fileinfo`, `xml`, `tokenizer`, `ctype` e **`bcmath` ou `gmp`** (necessária para as notificações no navegador).
2. **Banco de dados:** em *Bancos de dados MySQL*, crie o banco e o usuário, dê todas as permissões ao usuário no banco e anote os nomes completos (com o prefixo da conta).
3. **Arquivos:** no *Gerenciador de Arquivos*, crie a pasta `ideale` na sua pasta inicial (fora de `public_html`), envie o zip para ela e extraia. A estrutura fica `/home/SEU_USUARIO/ideale/artisan`, `/home/SEU_USUARIO/ideale/public`, etc.
4. **Endereço:** em *Subdomínios* (ou *Domínios*), crie `app.seudominio.com.br` com a raiz do documento em `ideale/public`.
5. **HTTPS:** em *SSL/TLS Status*, rode o AutoSSL para o novo subdomínio.
6. **E-mail:** em *Contas de e-mail*, crie `nao-responda@seudominio.com.br`. Ele envia a confirmação de cadastro e a recuperação de senha.
7. **Configuração:** dentro de `ideale`, crie o `.env` a partir de `.env.hostgator.example` (ou use o que veio no pacote) e preencha os valores entre `< >`. Com *Mostrar arquivos ocultos* ligado no Gerenciador de Arquivos, o `.env` aparece na lista.

## 3. Banco e agendador

Com acesso ao **Terminal** do cPanel ou SSH:

```sh
cd ~/ideale
php artisan migrate --force
php artisan optimize
php artisan care:system-admin seu-email@dominio.com
```

O último comando promove uma conta já cadastrada a administradora do sistema. Cadastre a conta pelo site primeiro.

Em *Trabalhos Cron*, adicione uma tarefa **a cada minuto**:

```
/opt/cpanel/ea-php83/root/usr/bin/php /home/SEU_USUARIO/ideale/artisan schedule:run >> /dev/null 2>&1
```

Ela gera as doses de remédio, os lembretes e processa a fila de envio.

Sem Terminal nem SSH, crie temporariamente um cron com `php .../artisan migrate --force` e `php .../artisan optimize`, espere ele rodar uma vez e apague. O comando `care:system-admin` pede a senha de forma interativa e precisa de Terminal ou SSH.

## 4. Conferir

- `https://app.seudominio.com.br` abre a tela de entrada.
- `https://app.seudominio.com.br/up` responde que a aplicação está no ar.
- Cadastre uma conta, confirme o e-mail recebido e entre.
- Recarregar a página em `/pacientes` continua mostrando o sistema (e não um erro 404).

## Atualizar depois

Gere o pacote de novo (parte 1), envie o novo zip, extraia por cima (sem apagar `.env` nem `storage`) e rode `php artisan migrate --force` e `php artisan optimize`. Faça backup do banco antes, pelo *phpMyAdmin* ou por *Backup* no cPanel.

## Problemas comuns

| Sintoma | Causa provável |
|---|---|
| Erro 500 logo ao abrir | `.env` ausente ou `APP_KEY` vazia; veja `storage/logs/laravel.log`. |
| Erro de versão do PHP | O subdomínio ainda está em PHP 8.1/8.2. |
| Login volta para a tela de entrada | `APP_URL`/`SANCTUM_STATEFUL_DOMAINS` diferente do endereço usado, ou HTTPS desligado com `SESSION_SECURE_COOKIE=true`. |
| Remédios do dia não aparecem | O cron do `schedule:run` não está rodando. |
| E-mail não chega | Dados de SMTP errados no `.env`; confira também a caixa de spam. |
