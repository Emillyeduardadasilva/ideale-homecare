# Publicação na HostGator — plano M

O pacote publica Vue e Laravel em https://lumajoias.com.br, com a API no mesmo domínio HTTPS. O Vue usa /api/v1 e busca a sessão em /sanctum/csrf-cookie. O cron processa tarefas e avisos a cada 15 minutos, conforme a decisão para o plano M. Sob carga, filas acumuladas podem ampliar esse atraso.

## Ambiente a confirmar na conta

Selecionar PHP 8.5 no cPanel e usar a mesma versão no terminal e no cron. Verificar PDO MySQL, OpenSSL, mbstring, cURL, XML, fileinfo, intl, tokenizer e ctype. O Composer verifica os requisitos exatos das dependências.

A matriz da HostGator lista PHP 8.5 e MySQL 8.0 em servidores AlmaLinux. A mesma matriz menciona Laravel 10 e 11. Este projeto utiliza Laravel 13. A execução desta versão na sua conta ainda precisa ser confirmada pelas checagens abaixo. Se a conta estiver em MySQL 5.7, solicitar um ambiente MySQL 8.0 ou superior.

Fontes oficiais — [compatibilidades](https://suporte.hostgator.com.br/hc/pt-br/articles/30811116692115-Quais-s%C3%A3o-as-compatibilidades-da-HostGator), [PHP](https://suporte.hostgator.com.br/hc/pt-br/articles/30818470707219-Como-funcionam-as-configura%C3%A7%C3%B5es-do-PHP), [SSH](https://suporte.hostgator.com.br/hc/pt-br/articles/30814927583379-Como-habilitar-o-acesso-SSH-no-plano-de-hospedagem), [cron](https://suporte.hostgator.com.br/hc/pt-br/articles/30810523318419-Como-gerenciar-um-trabalho-Cron).

## Gerar o pacote no Windows

Requer Node.js compatível com o projeto e Docker Desktop em execução. Na raiz do repositório, executar no PowerShell.

~~~powershell
./deploy/hostgator/build.ps1
~~~

O ZIP fica em dist-hostgator/ideale-homecare-hostgator.zip. Inclui a interface compilada e as dependências PHP de produção. O empacotador copia somente código e modelos de configuração e cria diretórios vazios para os dados do servidor.

## Extrair no cPanel

A estrutura abaixo deve ficar dentro da pasta pessoal da conta. USUARIO representa o usuário real do cPanel.

~~~text
/home/USUARIO/
├── ideale-backend/
│   ├── .env
│   ├── artisan
│   ├── hostgator-cron.php
│   ├── app/
│   ├── storage/
│   └── vendor/
└── public_html/
    ├── .htaccess
    ├── index.php
    ├── index.html
    ├── assets/
    └── sw.js
~~~

Para uma instalação nova, enviar o ZIP à pasta pessoal e extrair ali. Se já houver um site em public_html, fazer backup e escolher a raiz de publicação antes de mesclar os arquivos. Os nomes ideale-backend e public_html são usados pelo index.php fornecido.

Para um domínio adicional com outra raiz pública, colocar o backend na pasta irmã correspondente ou ajustar a variável $backend do index.php público para o caminho real.

Ativar o certificado SSL e o redirecionamento HTTPS no painel do domínio.

## Configurar o servidor

Editar ideale-backend/.env, que contém um modelo sem credenciais reais.

- APP_URL está configurado para https://lumajoias.com.br. FRONTEND_URL e CORS_ALLOWED_ORIGINS acompanham esse valor. Configurar o domínio e o certificado SSL no cPanel.
- Criar o banco e o usuário no cPanel. Preencher DB_HOST, DB_DATABASE, DB_USERNAME e DB_PASSWORD, incluindo os prefixos da conta.
- Configurar a caixa de e-mail e preencher MAIL_HOST, MAIL_PORT, MAIL_SCHEME, MAIL_USERNAME, MAIL_PASSWORD e MAIL_FROM_ADDRESS. Porta 465 usa smtps. Porta 587 usa smtp com STARTTLS.
- Gerar APP_KEY somente na instalação nova. Em atualizações, preservar a chave, o .env e storage, que contêm configuração e dados do servidor.
- Para Web Push, executar care:vapid e preencher VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY e VAPID_SUBJECT. A caixa de notificações da aplicação usa o banco. O pacote do plano M mantém BROADCAST_CONNECTION=log.
- Garantir escrita pelo usuário PHP em storage e bootstrap/cache. Pastas usam normalmente 755 e arquivos 644. O .env pode usar 600 quando PHP executa como o dono da conta.

Habilitar SSH no suporte, se necessário. Confirmar o executável PHP 8.5 com php -v ou solicitar seu caminho ao suporte. Usar esse mesmo executável nos comandos abaixo e no cron.

~~~sh
cd /home/USUARIO/ideale-backend
php -v
php artisan about
php artisan key:generate
php artisan migrate --force
php artisan optimize
php artisan care:status --json
~~~

O comando key:generate é exclusivo da instalação nova. O domínio e o .env devem estar preenchidos antes de gerar os caches com optimize. Os comandos verificam compatibilidade, conexão e migrations antes de disponibilizar a aplicação.

## Configurar o cron do plano M

A HostGator exige intervalo mínimo de 15 minutos na hospedagem compartilhada. Configurar os cinco campos como */15 * * * *. No campo de comando, usar o caminho absoluto do PHP 8.5 confirmado na conta.

~~~sh
/CAMINHO/DO/PHP85 /home/USUARIO/ideale-backend/hostgator-cron.php >> /home/USUARIO/ideale-backend/storage/logs/hostgator-cron.log 2>&1
~~~

O comando executa o agendador Laravel, processa um lote da fila e encerra. Uma trava no banco evita execuções simultâneas. O worker verifica seus limites de tempo entre jobs. O encerramento de um job por timeout depende de PCNTL no PHP CLI. Verificar a extensão na conta e os limites de execução do provedor.

Os avisos podem ser enviados depois do horário cadastrado, conforme o intervalo do cron e o volume de tarefas. O arquivo storage/logs/hostgator-cron.log registra o resultado e deve integrar a rotação dos logs.

## Conferir depois de publicar

Abrir /login, /cadastro e uma URL interna diretamente para verificar o roteamento Vue. Confirmar que /api/v1/openapi.json devolve JSON, /up responde 200 e /sanctum/csrf-cookie cria a sessão. Testar cadastro, confirmação de e-mail, entrada, saída e recuperação de senha usando uma conta de teste.

Executar o cron manualmente e consultar care:status --json. Verificar a caixa de notificações e, após configurar VAPID e conceder permissão no navegador, testar Web Push. A confirmação final depende do domínio, das credenciais e do ambiente real da hospedagem.

Para atualizar uma instalação existente, transferir código e arquivos públicos preservando .env, storage e a chave existentes. Executar migrations e refazer caches. O ZIP contém um .env de exemplo e diretórios de dados vazios para a primeira instalação.
