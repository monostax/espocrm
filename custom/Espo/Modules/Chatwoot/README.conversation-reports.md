# Relatórios operacionais de conversas

`Rebuild/SeedChatwootReports.php` cadastra os relatórios abaixo com IDs estáveis.
São relatórios nativos do Advanced: usam filtros de execução, exportação,
gráficos e ACL do CRM. O seeder atualiza os mesmos registros a cada rebuild.

| ID | Relatório | Fonte e granularidade |
|---|---|---|
| `chwRptCvList` | Conversas / Base para Exportação | Uma linha por `ChatwootConversation` sincronizada |
| `chwRptCvInboxMo` | Conversas Criadas / Mês e Caixa | Quantidade por mês de `chatwootCreatedAt` e caixa |
| `chwRptActInboxMo` | Movimento Sincronizado / Mês e Caixa | Conversas distintas com mensagens públicas no mês; mensagens recebidas e enviadas |
| `chwRptEvtInboxMo` | Reaberturas e Encerramentos / Mês e Caixa | Eventos por mês de `happenedAt` e ID original da caixa |
| `chwRptEvtList` | Eventos / Base para Exportação | Uma linha por `ChatwootReportingEvent` sincronizado |

## Uso pelo cliente

1. Acessar **Relatórios** e procurar os nomes com prefixo **Chatwoot ·**.
2. Selecionar conta, período e caixas de entrada.
3. Executar o relatório e usar **Exportar**.
4. Nos resumos, o XLSX reúne as métricas em abas; a exportação CSV usa a métrica
   selecionada. Nas bases em lista, exportar os resultados filtrados em CSV.

Links diretos na instalação principal:

- https://app.monostax.ai/#Report/view/chwRptCvList
- https://app.monostax.ai/#Report/view/chwRptCvInboxMo
- https://app.monostax.ai/#Report/view/chwRptActInboxMo
- https://app.monostax.ai/#Report/view/chwRptEvtInboxMo
- https://app.monostax.ai/#Report/view/chwRptEvtList

Os relatórios não fixam conta, período ou caixas de um cliente específico.
Para o Grupo Mousa, selecionar a conta correspondente e as caixas **Prainha
(1567)** e **N. Rep. (6404)**. Nos relatórios de eventos, os IDs originais são
**68** e **69**. O uso do ID original preserva a identificação da caixa em
eventos que sobreviveram à remoção da conversa ou da caixa na origem.

## Permissões

- Os registros são compartilhados globalmente com `applyAcl = true`; as consultas
  e exportações respeitam as permissões do usuário sobre as entidades de origem.
- `Global/Rebuild/SeedRole.php` define `exportPermission = yes` no papel
  **`tenant-admin`**, depois de expandir a configuração base do tenant.
- A exportação nativa de listas exige essa permissão além do acesso de leitura.
  É uma permissão geral de exportação dos dados já acessíveis ao usuário.
- **Administrador de conta Chatwoot** e **administrador de tenant CRM** são
  atribuições distintas. O usuário precisa do papel `tenant-admin`, diretamente
  ou pela equipe administrativa do tenant, para receber essa permissão.
- Após aplicar alterações em papéis por CLI, invalidar o cache de ACL pelo
  `Espo\Core\Acl\Cache\Clearer` e recarregar o cliente para atualizar o menu.

## Definição das métricas

- **Conversas criadas:** registros sincronizados agrupados pela data de criação
  no Chatwoot. Uma conversa antiga que retorna não é uma nova conversa.
- **Movimento sincronizado:** `COUNT_DISTINCT:conversationId` sobre mensagens
  `incoming`/`outgoing` com `isPrivate = false`. O período filtra a data da
  mensagem. Inclui respostas humanas, IA e automações; exclui notas privadas,
  atividades e o tipo `template`.
- **Total de conversas-mês:** soma dos valores mensais. A mesma conversa pode
  contar em mais de um mês, portanto esse total não representa clientes únicos
  nem conversas únicas do intervalo completo.
- **Reaberturas e encerramentos:** contagem de eventos; uma conversa pode gerar
  vários, incluindo encerramentos automáticos. Não equivale ao status atual.
- **Estado atual:** status, atendente e contagem de mensagens da base em lista
  representam o estado sincronizado no momento da consulta.

Os agrupamentos mensais seguem o fuso do sistema, como os demais relatórios
nativos do CRM. Na instalação principal: `America/Sao_Paulo`.

`COUNT_DISTINCT` é suportado pelo ORM; a definição de movimento especifica
`columnsData.type = Summary` e `fieldType = int` para que o motor nativo trate
a coluna como métrica agregada numérica, inclusive nas exportações.

## Cobertura dos dados

O CRM consulta seu próprio histórico sincronizado. A rotina
`Jobs/SyncConversationsFromChatwoot.php` ingere as mensagens presentes no payload
da conversa; isso não garante que todas as mensagens históricas foram copiadas.
O nome e a descrição do relatório de movimento explicitam essa origem.

Na validação do Grupo Mousa em 17/09/2026, para agosto/2026:

| Métrica | CRM | Consulta direta ao Chatwoot |
|---|---:|---:|
| Conversas criadas, caixas 68 e 69 | 1.541 | 1.541 |
| Conversas com movimento | 1.955 | 1.982 |
| Eventos de encerramento | 3.250 | 3.250 |

Para obter paridade integral no movimento e nas mensagens, é necessário
completar a sincronização histórica. Esses relatórios não executam esse
backfill nem classificam marcas a partir de palavras nas mensagens.

A distinção **Eco / Sanclin** depende de um critério de negócio e de dados
estruturados de classificação. Caixa, palavra mencionada e empresa responsável
pelo atendimento não são equivalentes.

## Validação realizada

- Sintaxe PHP dos dois seeders e `git diff --check`.
- Execução dos cinco relatórios persistidos pelo serviço nativo, com filtros
  de período, conta e caixa, usando um administrador de tenant não global.
- Comparação de totais mensais e paginação das bases em lista.
- Tentativa de consultar outra conta com o mesmo usuário: resultado vazio.
- Geração de CSV por métrica e XLSX dos três resumos.
- Exportação nativa de 1.541 linhas de conversas e 3.250 linhas de encerramentos,
  validando a leitura dos CSVs; os anexos temporários de verificação foram removidos.
- Permissão efetiva de exportação habilitada para `tenant-admin`; o usuário de
  teste com apenas o papel base `tenant` continuou sem essa permissão.

Os cinco registros e a permissão do papel `tenant-admin` foram aplicados à
instalação principal. Os seeders mantêm essas definições nos próximos rebuilds.
