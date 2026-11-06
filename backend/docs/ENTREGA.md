# Backend Ideale Home Care V2 — entrega de desenvolvimento

Validação realizada em 06/11/2026, com PHP 8.5.12, Laravel 13.35 e MySQL 8.4. A implementação segue os módulos de cuidado doméstico da V2 e a matriz de acesso descrita no README. O frontend Vue é um consumidor separado.

## Escopo implementado

| Área | Entrega |
|---|---|
| Identidade | Cadastro familiar, login/logout por sessão, CSRF, conta ativa, confirmação de e-mail e recuperação de senha com invalidação de sessões. |
| Rede | Idosos, gestor protegido contra rebaixamento por convite, parentes/equipe por vínculo, consulta e revogação de convites, desvinculação da conta do idoso e revogação de convites pendentes ao retirar acesso. |
| Tarefas | Responsáveis da rede, edição versionada, conclusão única com autoria, cancelamento e confirmação pelo idoso titular. |
| Medicamentos | Horários diários, vigência, geração persistida, histórico de dose/horário, substituição de programação futura e confirmação relatada após o horário. |
| Diário | Observações, tags, instante do fato separado da inserção e retificações vinculadas, sem alteração do registro original. |
| Medições | Pressão, glicemia e temperatura, validação de forma e unidades definidas no servidor. |
| Visitas | Equipe vinculada, reserva/reagendamento sem sobreposição concorrente do profissional, transições de estado, pré-atendimento e leitura explícita por observação/visita/profissional. |
| Agenda | Projeção conjunta por fuso e visão diária mínima com `allowed_actions`. |
| Contatos | Contatos versionados, criação/edição/cancelamento de escalas com histórico e resolução do responsável atual ou central. |
| Notificações | Caixa persistida, leitura explícita, recuperação incremental com `after_id`, Web Push e Reverb privado por usuário. |
| Recuperação | Entregas persistidas, reserva temporária, tentativas limitadas, espera crescente, revalidação de acesso/origem e comandos de reprocessamento. |
| Contrato | OpenAPI 3.1 cobrindo 61 operações HTTP, erros estáveis com `request_id`, compatibilidade dos campos de erro Laravel e cabeçalhos contra cache de dados de cuidado. |
| Entrada | FormRequests nos principais cadastros/edições, mensagens de validação em português e instantes ISO 8601 com offset ou `Z`, incluindo frações enviadas pelo JavaScript. |

O acesso continua condicionado à conta e ao vínculo atual com cada idoso. A equipe não recebe acesso global. `allowed_actions` orienta a interface; toda escrita revalida a autorização no servidor. Medicamentos e medições são registros informados, sem diagnóstico, prescrição ou ajuste de dose.

## Evidências

| Verificação | Resultado |
|---|---|
| Suíte completa em MySQL | **52 testes, 5.033 asserções, nenhum erro/falha e nenhum teste pulado.** |
| Suíte padrão SQLite em memória | 49 testes executados com sucesso; três testes exclusivos de concorrência MySQL pulados. |
| Concorrência com processos separados | Reserva do mesmo profissional entre duas redes, conclusão compartilhada de tarefa e disputa de inscrição push entre contas aprovadas. |
| Contrato OpenAPI | Todas as rotas registradas da API documentadas; referências internas resolvidas. |
| Formatação | `vendor/bin/pint --test` aprovado. |
| Dependências | `composer validate --strict` aprovado; `composer audit --locked` sem avisos de vulnerabilidade na consulta desta entrega. |
| HTTP real | Cadastro, CSRF ativo, confirmação de e-mail assinada, login, cuidados, visitas, leituras, escalas, revogação e recuperação de senha aprovados em servidor local isolado. |
| WebSocket real | Canal privado autorizado, sinal recebido do Reverb após processamento pela fila e recuperação da notificação pela API. O evento contém apenas `notification_id`. |
| Atualização do banco | Registros da estrutura anterior preservados ao aplicar, reverter e reaplicar as três migrations mais recentes; informação criptografada permaneceu legível com a chave preservada. |
| Backup/restauração | Dump sintético restaurado em outro banco: 33 tabelas e 78 registros; contagens, checksums e relações verificadas. Ensaio de aproximadamente 46 segundos, sem compromisso de RTO. |

Os ensaios usaram bancos temporários separados e dados sintéticos. O banco da aplicação não foi acessado ou migrado. O teste de Web Push usa a biblioteca real com transporte HTTP simulado; não foi enviado aviso a um dispositivo real nem e-mail por SMTP externo.

## Ativação no ambiente da aplicação

1. Instalar as dependências do `composer.lock` atualizado.
2. Configurar o ambiente e preservar a `APP_KEY` existente quando houver dados criptografados.
3. Fazer backup e aplicar `php artisan migrate`. A nova versão inclui as migrations de contatos, escalas/índices e entregas Reverb.
4. Configurar SMTP, URLs, cookies e HTTPS conforme o ambiente.
5. Manter worker e agendador supervisionados; configurar e iniciar Reverb e VAPID quando os canais forem usados.
6. Integrar o Vue aos endpoints, ao canal privado e ao Service Worker para Web Push.

O [guia de operação](OPERACAO.md) detalha os comandos. Hospedagem, credenciais externas, disponibilidade contratada, RPO/RTO, retenção e anonimização exigem definição operacional. A entrega de código não representa publicação em produção ou homologação visual/acessível do frontend.
