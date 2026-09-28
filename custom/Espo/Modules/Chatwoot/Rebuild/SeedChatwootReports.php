<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata;
use Espo\ORM\EntityManager;

/**
 * Rebuild action that seeds (creates or updates) Chatwoot's native and
 * internal-class reports with deterministic IDs.
 *
     * Modelled on Espo\Modules\Global\Rebuild\SeedRole — same lifecycle:
     *
     *   1. Compute a stable ID from a static slug (md5 when the platform is
     *      running in UUID mode, plain otherwise). Slugs must be ≤17 chars
     *      because `report.id` is `varchar(17)`.
 *   2. Restore the record if it was soft-deleted (raw UPDATE so the
 *      `deleted=1` filter on the ORM doesn't shadow it).
 *   3. Update in place if it already exists, otherwise create.
 *
 * Saves go through EntityManager so all the existing record hooks fire:
 *
 *   - `Global\Classes\RecordHooks\Report\BeforeSave` forces `applyAcl=true`
 *     when `isGloballyShared=true` (multi-tenant safety invariant).
 *   - `Global\Hooks\Common\GlobalSharing::afterSave` syncs `teams` to all
 *     teams, making the report visible to every team-based user. The
 *     `withStrictAccessControl()` inside the Report class still trims the
 *     numbers each user sees to their own ACL on ChatwootAiAgentRun.
 *
 * Adding a new internal report: append a new entry to
 * `getReportDefinitions()` — that's it.
 *
 * @noinspection PhpUnused
 */
class SeedChatwootReports implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
        private Log $log,
    ) {}

    public function process(): void
    {
        $this->log->info('Chatwoot Module: Starting to seed/update internal reports...');

        $toHash = $this->metadata->get(['app', 'recordId', 'type']) === 'uuid4'
            || $this->metadata->get(['app', 'recordId', 'dbType']) === 'uuid';

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($this->getReportDefinitions() as $config) {
            $result = $this->seedReport($config, $toHash);

            match ($result) {
                'created' => $created++,
                'updated' => $updated++,
                default   => $skipped++,
            };
        }

        $this->log->info(
            "Chatwoot Module: Report seeding complete. " .
            "Created: {$created}, Updated: {$updated}, Skipped: {$skipped}"
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function getReportDefinitions(): array
    {
        return [
            ...$this->getConversationReportDefinitions(),
            ...$this->getEpisodeReportDefinitions(),
            ...$this->getModelUsageReportDefinitions(),
            [
                // LLM token consumption per Tenant (Ambiente) per day —
                // the cost-drilldown counterpart to the conversations
                // reports. Sums the producer-reported token counters and
                // counts runs, grouped by day + tenant.
                //
                // Column semantics (all producer-derived, readOnly ints
                // on ChatwootAiAgentRun):
                //   SUM:inputTokens        — total prompt tokens billed
                //   SUM:cachedInputTokens  — subset of input served from
                //                            the implicit-context cache
                //                            (billed at a discount; the
                //                            cache-miss spend is
                //                            input − cachedInput)
                //   SUM:outputTokens       — completion tokens (the
                //                            expensive side)
                //   COUNT:id               — number of runs
                //
                // Not internal — SUM/COUNT grouping is native engine
                // capability (unlike the COUNT-DISTINCT that forced the
                // ConversationsEngaged* internal classes). Date group
                // first so DateBucketPadder pads empty days for free.
                //
                // Performance: served by the purpose-built
                // `(tenantId, runAt, deleted)` composite index.
                //
                // ACL: globally shared + applyAcl — tenant users only see
                // their own rows (team-trimmed), so tenant identities
                // never leak across environments; org admins see all
                // tenants side by side.
                'staticId' => 'chwRptTokTnDay',
                'name' => 'IA · Tokens e Execuções / Ambiente e Dia',
                'description' =>
                    'Tokens de entrada, entrada em cache e saída, e número de execuções ' .
                    'do agente, por Ambiente e dia civil no fuso do sistema. Uma execução ' .
                    'pode fazer várias chamadas ao LLM. Tokens em cache já fazem parte ' .
                    'da entrada; tokens de raciocínio já fazem parte da saída. ' .
                    'Mede consumo técnico, inclusive de execuções excluídas da cobrança; ' .
                    'não calcula valores monetários. Cada usuário vê apenas as execuções permitidas por sua ACL.',
                'entityType' => 'ChatwootAiAgentRun',
                'type' => 'Grid',
                'columns' => [
                    'SUM:inputTokens',
                    'SUM:cachedInputTokens',
                    'SUM:outputTokens',
                    'COUNT:id',
                ],
                'groupBy' => ['DAY:runAt', 'tenant'],
                'runtimeFilters' => ['runAt', 'tenant', 'model', 'kind'],
                'orderBy' => [],
                'depth' => 2,
                'chartType' => 'BarVertical',
                'fillEmptyDateBuckets' => true,
                'isInternal' => false,
                'isGloballyShared' => true,
                'applyAcl' => true,
            ],
            [
                // Tenant cost-ranking view: token totals + run count per
                // Tenant (no date grouping), ordered by total tokens
                // descending — answers "which tenant is driving the
                // spend?" in one glance. Scope the window with the
                // `runAt` runtime filter (e.g. last 30 days).
                //
                // `SUM:totalTokens` is included here (and omitted from
                // the daily report) because it is the natural ranking
                // key; input/cached/output break the total down.
                'staticId' => 'chwRptTokTn',
                'name' => 'IA · Tokens e Execuções / Ambiente / Total',
                'description' =>
                    'Totais de tokens e execuções do agente por Ambiente, ordenados ' .
                    'pelo total de tokens, do maior para o menor. Use Execução Finalizada em ' .
                    '(runAt) para selecionar o período. Entrada em cache é parte da entrada; ' .
                    'raciocínio é parte da saída. Consumo de tokens não equivale a valor ' .
                    'cobrado nem a créditos: uma execução pode fazer várias chamadas ao LLM. ' .
                    'Cada usuário vê apenas as execuções permitidas por sua ACL.',
                'entityType' => 'ChatwootAiAgentRun',
                'type' => 'Grid',
                'columns' => [
                    'SUM:totalTokens',
                    'SUM:inputTokens',
                    'SUM:cachedInputTokens',
                    'SUM:outputTokens',
                    'COUNT:id',
                ],
                'groupBy' => ['tenant'],
                'runtimeFilters' => ['runAt', 'model', 'kind'],
                'orderBy' => ['DESC:SUM:totalTokens'],
                'depth' => 1,
                'chartType' => 'BarHorizontal',
                'fillEmptyDateBuckets' => false,
                'isInternal' => false,
                'isGloballyShared' => true,
                'applyAcl' => true,
            ],
            [
                // Consumption matrix: Tenant × recorded agent model.
                // Run totals include knowledge-base search calls, which
                // may use a different model. These aggregates are not
                // sufficient to reconstruct provider charges per model.
                //
                // Grid engine caps grouping at 2 dimensions, so the
                // date window is a runtime filter (`runAt`) rather than
                // a group. Use `kind` to isolate follow-up consumption.
                'staticId' => 'chwRptTokTnMdl',
                'name' => 'IA · Tokens e Execuções / Ambiente e Modelo',
                'description' =>
                    'Totais de tokens e execuções por Ambiente e modelo LLM registrado ' .
                    'na execução. Os contadores incluem chamadas do agente principal e ' .
                    'da busca na base de conhecimento, que pode usar outro modelo. ' .
                    'Tokens em cache são parte da entrada; raciocínio é parte da saída. ' .
                    'Use para comparar consumo, não como fatura do provedor. ' .
                    'O filtro de período usa Execução Finalizada em (runAt). ' .
                    'Cada usuário vê apenas as execuções permitidas por sua ACL.',
                'entityType' => 'ChatwootAiAgentRun',
                'type' => 'Grid',
                'columns' => [
                    'SUM:totalTokens',
                    'SUM:inputTokens',
                    'SUM:cachedInputTokens',
                    'SUM:outputTokens',
                    'COUNT:id',
                ],
                'groupBy' => ['tenant', 'model'],
                'runtimeFilters' => ['runAt', 'kind'],
                'orderBy' => ['DESC:SUM:totalTokens'],
                'depth' => 2,
                'chartType' => 'BarHorizontal',
                'fillEmptyDateBuckets' => false,
                'isInternal' => false,
                'isGloballyShared' => true,
                'applyAcl' => true,
            ],
            [
                // Spend split per trigger class per day. `kind` is
                // producer-set (resolved by the chatwoot-agent
                // workflow's process-message, which consults the
                // Chatwoot mention table): 'customer-message'
                // (inbound contact message), 'private-mention'
                // (human agent asked the AI via private-note
                // @mention — internal usage, customer never sees the
                // trigger), 'public-mention' (customer-visible
                // @mention), 'scheduled-message' (scheduled note),
                // 'followup-trigger' (proactive follow-up run).
                // This is the billing dimension: it separates
                // customer-driven spend from internal @mention usage
                // and proactive follow-up cadence. Rows persisted
                // before the five-way split only carry the legacy
                // binary values (old mention runs are bucketed under
                // 'customer-message').
                //
                // Note: `kind=followup-trigger` counts every
                // follow-up RUN (tokens are burned even when the AI
                // decides not to message) — distinct from the
                // `sentFollowupMessage` flag used by chwRptFuMsgAgDay.
                'staticId' => 'chwRptTokKindDay',
                'name' => 'IA · Tokens e Execuções / Gatilho e Dia',
                'description' =>
                    'Tokens de entrada, entrada em cache e saída, e execuções do agente ' .
                    'por dia e tipo de gatilho: mensagem do cliente, @menção em nota privada, ' .
                    '@menção pública, mensagem agendada, follow-up ou @menção no histórico ' .
                    'da oportunidade. O gatilho identifica o que iniciou a execução, ' .
                    'não seu resultado. Follow-ups contam mesmo sem envio de mensagem. ' .
                    'Registros antigos podem classificar @menções como mensagem do cliente. ' .
                    'Não calcula valores de cobrança. Cada usuário vê apenas as execuções permitidas por sua ACL.',
                'entityType' => 'ChatwootAiAgentRun',
                'type' => 'Grid',
                'columns' => [
                    'SUM:inputTokens',
                    'SUM:cachedInputTokens',
                    'SUM:outputTokens',
                    'COUNT:id',
                ],
                'groupBy' => ['DAY:runAt', 'kind'],
                'runtimeFilters' => ['runAt', 'tenant', 'model'],
                'orderBy' => [],
                'depth' => 2,
                'chartType' => 'BarVertical',
                'fillEmptyDateBuckets' => true,
                'isInternal' => false,
                'isGloballyShared' => true,
                'applyAcl' => true,
            ],
            [
                'staticId' => 'chwRptCvTnDay',
                'name' => 'IA · Conversas com Execuções / Ambiente e Dia',
                'description' =>
                    'Conversas distintas com ao menos uma execução do agente no dia, ' .
                    'por Ambiente, no fuso do sistema. Cada conversa conta uma vez por dia; ' .
                    'a soma dos dias não é o número de conversas únicas no período. ' .
                    'Não exige resposta enviada ou atendimento concluído. ' .
                    'Cada usuário vê apenas as execuções permitidas por sua ACL.',
                'entityType' => 'ChatwootAiAgentRun',
                'type' => 'Grid',
                'columns' => ['COUNT:id'],
                'groupBy' => ['DAY:runAt', 'tenant'],
                'runtimeFilters' => ['runAt', 'tenant'],
                'orderBy' => [],
                'depth' => 2,
                'chartType' => 'BarVertical',
                'fillEmptyDateBuckets' => true,
                'isInternal' => true,
                'internalClassName' => 'Chatwoot:ConversationsEngagedByTenantPerDay',
                'isGloballyShared' => true,
                'applyAcl' => true,
            ],
            [
                'staticId' => 'chwRptCvDay',
                'name' => 'IA · Conversas com Execuções / Dia',
                'description' =>
                    'Conversas distintas com ao menos uma execução do agente em cada dia, ' .
                    'no fuso do sistema, sem separar por Ambiente. Uma conversa pode contar ' .
                    'em vários dias; o total soma conversas-dia, não conversas únicas ' .
                    'no período. Não exige resposta enviada ou atendimento concluído. ' .
                    'Cada usuário vê apenas as execuções permitidas por sua ACL.',
                'entityType' => 'ChatwootAiAgentRun',
                'type' => 'Grid',
                'columns' => ['COUNT:id'],
                'groupBy' => ['DAY:runAt'],
                'runtimeFilters' => ['runAt'],
                'orderBy' => [],
                'depth' => 1,
                'chartType' => 'BarVertical',
                'fillEmptyDateBuckets' => true,
                'isInternal' => true,
                'internalClassName' => 'Chatwoot:ConversationsEngagedPerDay',
                'isGloballyShared' => true,
                'applyAcl' => true,
            ],
            // ------------------------------------------------------------------
            // Billing — Pack model (simplified commercial offer).
            // packs = ceil(all-kind turns / packSize) × packUnitPrice
            // per conversation-day. Rates from Tenant AI Billing
            // (platform defaults when unset).
            // ------------------------------------------------------------------
            [
                'staticId' => 'chwRptBillPkDay',
                'name' => 'IA · Cobrança Estimada por Pacotes / Dia',
                'description' =>
                    'Estimativa pelo modelo de pacotes: para cada conversa ou oportunidade ' .
                    'em cada dia civil, divide as execuções pelo tamanho do pacote e arredonda ' .
                    'para cima. Exemplo: 5 execuções com tamanho 4 consomem 2 pacotes. ' .
                    'O excedente da franquia mensal é multiplicado pelo preço do pacote. ' .
                    'Exclui falhas explícitas e execuções sem vínculo para cobrança. ' .
                    'Chamadas ao LLM e tokens não alteram a quantidade de pacotes. ' .
                    'Usa as tarifas de cada Ambiente por vigência, com padrões da plataforma ' .
                    'quando ausentes, e o fuso do sistema. Selecione o mês completo para aplicar ' .
                    'a franquia corretamente. O relatório sempre aplica este modelo, mesmo ' .
                    'se o contrato usar outro. Valores sem mensalidade, restritos pela ACL do usuário; ' .
                    'o detalhamento lista as execuções consideradas no cálculo.',
                'entityType' => 'ChatwootAiAgentRun',
                'type' => 'Grid',
                'columns' => ['COUNT:id'],
                'groupBy' => ['DAY:runAt'],
                'runtimeFilters' => ['runAt'],
                'orderBy' => [],
                'depth' => 1,
                'chartType' => 'BarVertical',
                'fillEmptyDateBuckets' => true,
                'isInternal' => true,
                'internalClassName' => 'Chatwoot:BillingPacksPerDay',
                'isGloballyShared' => true,
                'applyAcl' => true,
            ],
            [
                'staticId' => 'chwRptBillPkTnD',
                'name' => 'IA · Cobrança Estimada por Pacotes / Ambiente e Dia',
                'description' =>
                    'Estimativa pelo modelo de pacotes, por Ambiente e dia civil no fuso do sistema. ' .
                    'Pacotes = execuções por conversa ou oportunidade no dia ÷ tamanho do pacote, ' .
                    'arredondado para cima. Valor = pacotes fora da franquia mensal × preço do pacote. ' .
                    'Exclui falhas explícitas e execuções sem vínculo para cobrança. ' .
                    'Tarifas e franquia são por Ambiente e vigência, com padrões quando ausentes. ' .
                    'Mostra o valor na moeda da tarifa e convertido para a moeda do sistema; ' .
                    'o total monetário entre ambientes soma apenas valores convertidos. ' .
                    'Selecione o mês completo para aplicar a franquia corretamente. ' .
                    'Sempre aplica este modelo, mesmo se o contrato usar outro. ' .
                    'Valores sem mensalidade, restritos pela ACL do usuário.',
                'entityType' => 'ChatwootAiAgentRun',
                'type' => 'Grid',
                'columns' => ['COUNT:id'],
                'groupBy' => ['DAY:runAt', 'tenant'],
                'runtimeFilters' => ['runAt', 'tenant'],
                'orderBy' => [],
                'depth' => 2,
                'chartType' => 'BarVertical',
                'fillEmptyDateBuckets' => true,
                'isInternal' => true,
                'internalClassName' => 'Chatwoot:BillingPacksByTenantPerDay',
                'isGloballyShared' => true,
                'applyAcl' => true,
            ],
            // ------------------------------------------------------------------
            // Billing — Extra model (negotiation flavour).
            // base once per conversation-day + unit × (reply overages
            // + mention engagements). Rates from Tenant AI Billing.
            // ------------------------------------------------------------------
            [
                'staticId' => 'chwRptBillE49Day',
                'name' => 'IA · Cobrança Estimada Base + Adicionais / Dia',
                'description' =>
                    'Estimativa pelo modelo base + adicionais. Cada conversa ou oportunidade ' .
                    'com execuções consideradas na cobrança em um dia civil consome uma base diária. ' .
                    'A base inclui até N execuções por mensagem do cliente; as excedentes e ' .
                    'todas as execuções por outros gatilhos são adicionais. A franquia mensal ' .
                    'cobre bases inteiras, incluindo seus adicionais. Fora da franquia, ' .
                    'cobra-se o preço da base mais o preço unitário de cada execução adicional. ' .
                    'As contagens de adicionais incluem também os cobertos pela franquia. ' .
                    'Exclui falhas explícitas e execuções sem vínculo para cobrança. ' .
                    'Usa tarifas por Ambiente e vigência, com padrões quando ausentes, e o fuso ' .
                    'do sistema. Selecione o mês completo para aplicar a franquia corretamente. ' .
                    'Sempre aplica este modelo, mesmo se o contrato usar outro. ' .
                    'Valores sem mensalidade, restritos pela ACL do usuário.',
                'entityType' => 'ChatwootAiAgentRun',
                'type' => 'Grid',
                'columns' => ['COUNT:id'],
                'groupBy' => ['DAY:runAt'],
                'runtimeFilters' => ['runAt'],
                'orderBy' => [],
                'depth' => 1,
                'chartType' => 'BarVertical',
                'fillEmptyDateBuckets' => true,
                'isInternal' => true,
                'internalClassName' => 'Chatwoot:BillingExtra049PerDay',
                'isGloballyShared' => true,
                'applyAcl' => true,
            ],
            [
                'staticId' => 'chwRptBillE49TnD',
                'name' => 'IA · Cobrança Estimada Base + Adicionais / Ambiente e Dia',
                'description' =>
                    'Estimativa pelo modelo base + adicionais, por Ambiente e dia civil no fuso ' .
                    'do sistema. Uma base diária corresponde a uma conversa ou oportunidade no dia. ' .
                    'Inclui até N execuções por mensagem do cliente; as excedentes e os demais ' .
                    'gatilhos são adicionais. A franquia mensal cobre a base e seus adicionais. ' .
                    'Fora dela, valor = preço da base + adicionais × preço unitário. ' .
                    'Exclui falhas explícitas e execuções sem vínculo para cobrança. ' .
                    'Tarifas e franquia são por Ambiente e vigência, com padrões quando ausentes. ' .
                    'Exibe valores na moeda da tarifa e do sistema; totais entre ambientes ' .
                    'somam apenas valores convertidos. Selecione o mês completo. ' .
                    'Sempre aplica este modelo, mesmo se o contrato usar outro. ' .
                    'Valores sem mensalidade, restritos pela ACL do usuário.',
                'entityType' => 'ChatwootAiAgentRun',
                'type' => 'Grid',
                'columns' => ['COUNT:id'],
                'groupBy' => ['DAY:runAt', 'tenant'],
                'runtimeFilters' => ['runAt', 'tenant'],
                'orderBy' => [],
                'depth' => 2,
                'chartType' => 'BarVertical',
                'fillEmptyDateBuckets' => true,
                'isInternal' => true,
                'internalClassName' => 'Chatwoot:BillingExtra049ByTenantPerDay',
                'isGloballyShared' => true,
                'applyAcl' => true,
            ],
            // ------------------------------------------------------------------
            // Billing — Credit model (pure linear; the customer-facing one).
            // 1 AI engagement = 1 credit, regardless of kind. Monthly credit
            // franchise consumed FIFO; the excess × creditUnitPrice is the bill.
            // No conversation / base / pack arithmetic on the readout.
            // ------------------------------------------------------------------
            [
                'staticId' => 'chwRptBillCrDay',
                'name' => 'IA · Créditos e Cobrança Estimada / Dia',
                'description' =>
                    'Estimativa pelo modelo de créditos: cada execução considerada na cobrança ' .
                    'consome 1 crédito, independentemente do gatilho, das chamadas ao LLM e dos tokens. ' .
                    'Exclui falhas explícitas e execuções sem vínculo para cobrança. A franquia ' .
                    'mensal cobre os primeiros créditos, em ordem de dia civil no fuso do sistema. ' .
                    'Valor = créditos fora da franquia × preço por crédito. Usa tarifas por ' .
                    'Ambiente e vigência; valores ausentes usam os padrões da plataforma ' .
                    '(preço 0,49 na moeda aplicável e sem franquia). Selecione o mês completo ' .
                    'para aplicar a franquia corretamente. Sempre aplica este modelo, mesmo ' .
                    'se o contrato usar outro. Valores sem mensalidade, restritos pela ACL do usuário; ' .
                    'o detalhamento lista as execuções consideradas no cálculo.',
                'entityType' => 'ChatwootAiAgentRun',
                'type' => 'Grid',
                'columns' => ['COUNT:id'],
                'groupBy' => ['DAY:runAt'],
                'runtimeFilters' => ['runAt'],
                'orderBy' => [],
                'depth' => 1,
                'chartType' => 'BarVertical',
                'fillEmptyDateBuckets' => true,
                'isInternal' => true,
                'internalClassName' => 'Chatwoot:BillingCreditsPerDay',
                'isGloballyShared' => true,
                'applyAcl' => true,
            ],
            [
                'staticId' => 'chwRptBillCrTnD',
                'name' => 'IA · Créditos e Cobrança Estimada / Ambiente e Dia',
                'description' =>
                    'Estimativa pelo modelo de créditos, por Ambiente e dia civil no fuso do sistema. ' .
                    'Cada execução considerada na cobrança consome 1 crédito. Exclui falhas explícitas ' .
                    'e execuções sem vínculo para cobrança. Valor = créditos fora da franquia mensal ' .
                    '× preço por crédito. Tarifas e franquia são por Ambiente e vigência, com padrões ' .
                    'quando ausentes. Exibe valores na moeda da tarifa e do sistema; totais entre ' .
                    'ambientes somam apenas valores convertidos. Selecione o mês completo para aplicar ' .
                    'a franquia corretamente. Sempre aplica este modelo, mesmo se o contrato usar outro. ' .
                    'Valores sem mensalidade, restritos pela ACL do usuário.',
                'entityType' => 'ChatwootAiAgentRun',
                'type' => 'Grid',
                'columns' => ['COUNT:id'],
                'groupBy' => ['DAY:runAt', 'tenant'],
                'runtimeFilters' => ['runAt', 'tenant'],
                'orderBy' => [],
                'depth' => 2,
                'chartType' => 'BarVertical',
                'fillEmptyDateBuckets' => true,
                'isInternal' => true,
                'internalClassName' => 'Chatwoot:BillingCreditsByTenantPerDay',
                'isGloballyShared' => true,
                'applyAcl' => true,
            ],
            [
                // Counts every "transition into open" per day across all
                // ChatwootConversations: synthetic conversation_created
                // (the first time a conversation enters open — Chatwoot
                // does NOT emit a real reporting_events row at creation)
                // plus native conversation_opened (every REOPEN).
                //
                // Together these two event names form the complete set
                // of "→ open" state transitions. Counting them per day
                // answers the operational question "how many
                // conversations entered the open queue today?".
                //
                // Not internal — a plain Report row with a filtersDataList
                // baked in. We use `type=in` at the top level (the only
                // multi-value where-item type Espo's WhereClauseManager
                // accepts in stored reports — `anyOf` is a list-view UX
                // shorthand and is rejected if persisted directly into
                // `report.filters_data_list`).
                'staticId' => 'chwRptOpensDay',
                'name' => 'Conversas Abertas por Dia',
                'description' =>
                    'Total de transições de conversas para o estado "aberta" ' .
                    'por dia. Inclui a criação inicial (sintética) + ' .
                    'reaberturas registradas.',
                'entityType' => 'ChatwootReportingEvent',
                'type' => 'Grid',
                'columns' => ['COUNT:id'],
                'groupBy' => ['DAY:happenedAt'],
                'runtimeFilters' => ['happenedAt'],
                'orderBy' => [],
                'filtersDataList' => [
                    [
                        'id' => 'opensFilter1',
                        'name' => 'eventName',
                        'params' => [
                            'type' => 'in',
                            'value' => [
                                'conversation_created',
                                'conversation_opened',
                            ],
                            'data' => [
                                'type' => 'anyOf',
                                'value' => [
                                    'conversation_created',
                                    'conversation_opened',
                                ],
                            ],
                            'field' => 'eventName',
                            'attribute' => 'eventName',
                        ],
                    ],
                ],
                'depth' => 1,
                'chartType' => 'Line',
                'chartColor' => '#6FA8D6',
                'fillEmptyDateBuckets' => true,
                'isInternal' => false,
                'isGloballyShared' => true,
                'applyAcl' => true,
            ],
            [
                // Counts runs flagged for follow-up sending per day,
                // grouped by AI Agent (Chatwoot account/user membership).
                // `sentFollowupMessage` is producer-derived from tool
                // invocations, including template sends where applicable.
                // COUNT:id counts each flagged run once, regardless of
                // invocation count; it is neither a message count nor a
                // delivery confirmation. Runs without a send invocation
                // are excluded.
                //
                // Performance: the `(sentFollowupMessage, runAt, deleted)`
                // composite index makes the WHERE-bool + DAY(runAt)
                // GROUP BY a small index range scan even at multi-
                // million-row scale. The companion `(agentId, runAt)`
                // index already exists and is used for the second group
                // dimension.
                //
                // Not internal — a plain Grid Report row carries the
                // ACL enforcement we need (`applyAcl=true` is coerced
                // by `Global\Classes\RecordHooks\Report\BeforeSave`
                // whenever `isGloballyShared=true`).
                //
                // Filter shape mirrors `chwRptOpensDay`: a single
                // baked-in `filtersDataList` entry with a top-level
                // `type` that Espo's WhereClauseManager accepts in
                // stored reports (`isTrue` for booleans; `anyOf` /
                // `equals` shorthands are list-view UX types and are
                // rejected if persisted directly into
                // `report.filters_data_list`).
                'staticId' => 'chwRptFuMsgAgDay',
                'name' => 'IA · Execuções com Invocação de Envio de Follow-up / Dia e Agente',
                'description' =>
                    'Execuções com o indicador sentFollowupMessage, por dia e agente. ' .
                    'O indicador registra a invocação de envio de follow-up, incluindo ' .
                    'templates quando aplicável. Cada execução conta uma vez, mesmo ' .
                    'com várias invocações: não é uma contagem de mensagens nem confirmação ' .
                    'de entrega. Exclui follow-ups que terminaram sem invocar envio. ' .
                    'Cada usuário vê apenas as execuções permitidas por sua ACL.',
                'entityType' => 'ChatwootAiAgentRun',
                'type' => 'Grid',
                'columns' => ['COUNT:id'],
                'groupBy' => ['DAY:runAt', 'agent'],
                'runtimeFilters' => ['runAt', 'agent', 'tenant'],
                'orderBy' => [],
                'filtersDataList' => [
                    [
                        'id' => 'fuMsgSentFilter1',
                        'name' => 'sentFollowupMessage',
                        'params' => [
                            'type' => 'isTrue',
                            'data' => [
                                'type' => 'isTrue',
                            ],
                            'field' => 'sentFollowupMessage',
                            'attribute' => 'sentFollowupMessage',
                        ],
                    ],
                ],
                'depth' => 2,
                'chartType' => 'BarVertical',
                'fillEmptyDateBuckets' => true,
                'isInternal' => false,
                'isGloballyShared' => true,
                'applyAcl' => true,
            ],
            [
                // Conversation-days with ≥1 AI run outside commercial hours
                // (Mon–Fri 08:00–18:00 system TZ). Same grain as billing /
                // ConversationsEngaged. Digest: "N fora do horário comercial".
                'staticId' => 'chwRptOffHrs',
                'name' => 'AI Agent Run (Conversas-Dia Fora do Horário Comercial / Por Dia)',
                'description' =>
                    'Conversas-dia (conversation × dia civil no fuso do ' .
                    'Ambiente) com ao menos uma execução de Agente IA fora ' .
                    'do horário comercial (segunda a sexta, 08:00–18:00 no ' .
                    'Tenant.timeZone; vazio = fuso da instância). Finais de ' .
                    'semana contam por completo. Agrupado por dia local do ' .
                    'Ambiente. ACL-strict em execuções do Agente IA no Chat.',
                'entityType' => 'ChatwootAiAgentRun',
                'type' => 'Grid',
                'columns' => ['COUNT:id'],
                'groupBy' => ['DAY:runAt'],
                'runtimeFilters' => ['runAt'],
                'orderBy' => [],
                'depth' => 1,
                'chartType' => 'BarVertical',
                'fillEmptyDateBuckets' => true,
                'isInternal' => true,
                'internalClassName' => 'Chatwoot:EngagementsOutsideBusinessHoursPerDay',
                'isGloballyShared' => true,
                'applyAcl' => true,
            ],
            // ------------------------------------------------------------------
            // Opportunities linked to conversations touched by AI in period.
            // Runtime filter: ChatwootAiAgentRun.runAt (+ tenant via runReport).
            // Digest: "R$ em oportunidades" scoped to AI-engaged conversations.
            // ------------------------------------------------------------------
            [
                'staticId' => 'chwRptAiOpp',
                'name' => 'Oportunidades (R$) · Conversas com IA no Período',
                'description' =>
                    'Soma amountConverted de Oportunidades distintas ligadas ' .
                    'a conversas do Chat que tiveram ≥1 execução do Agente IA ' .
                    'no filtro de período ' .
                    '(runAt). Ganhas/perdidas exigem closeDate no mesmo período; ' .
                    'abertas = snapshot aberto. ACL-strict em ' .
                    'execuções do Agente IA no Chat + Opportunity. Drill-down lista as opps.',
                'entityType' => 'ChatwootAiAgentRun',
                'type' => 'Grid',
                'columns' => [
                    'SUM:IF:(EQUAL:(probability, 100), amountConverted, 0)',
                    'SUM:IF:(EQUAL:(probability, 0), 1, 0)',
                    'SUM:IF:(AND:(NOT_EQUAL:(probability, 100), NOT_EQUAL:(probability, 0)), amountConverted, 0)',
                    'SUM:IF:(AND:(NOT_EQUAL:(probability, 100), NOT_EQUAL:(probability, 0)), 1, 0)',
                    'SUM:IF:(EQUAL:(probability, 0), amountConverted, 0)',
                    'SUM:IF:(EQUAL:(probability, 100), 1, 0)',
                    'SUM:amountConverted',
                    'COUNT:id',
                ],
                'groupBy' => [],
                'runtimeFilters' => ['runAt', 'tenant'],
                'orderBy' => [],
                'depth' => 0,
                'chartType' => null,
                'fillEmptyDateBuckets' => false,
                'isInternal' => true,
                'internalClassName' => 'Chatwoot:OpportunitiesFromAiAgentConversations',
                'isGloballyShared' => true,
                'applyAcl' => true,
            ],
            // ------------------------------------------------------------------
            // Single-payload daily/weekly WhatsApp digest (all KPIs, no day grid).
            // ------------------------------------------------------------------
            [
                'staticId' => 'chwRptDigest',
                'name' => 'IA Digest · Totais (WhatsApp)',
                'description' =>
                    'Relatório interno de totais (sem agrupamento por dia) para ' .
                    'a automação de notificação WhatsApp. Em um único payload: ' .
                    'SUM:amountConverted (abertas + ganhas/perdidas com closeDate ' .
                    'no período), ganhas/perdidas filtradas por closeDate, ' .
                    'COUNT:conversations, afterHours, AVG:leadTimeMs, turns. ' .
                    'Filtro runtime runAt (+ tenant via runReport). ACL-strict.',
                'entityType' => 'ChatwootAiAgentRun',
                'type' => 'Grid',
                'columns' => [
                    'SUM:amountConverted',
                    'COUNT:opportunities',
                    'COUNT:conversations',
                    'COUNT:afterHours',
                    'COUNT:afterHoursWeekend',
                    'COUNT:afterHoursWeekday',
                    'AVG:leadTimeMs',
                    'turns',
                ],
                'groupBy' => [],
                'runtimeFilters' => ['runAt', 'tenant'],
                'orderBy' => [],
                'depth' => 0,
                'chartType' => null,
                'fillEmptyDateBuckets' => false,
                'isInternal' => true,
                'internalClassName' => 'Chatwoot:DailyAiDigest',
                'isGloballyShared' => true,
                'applyAcl' => true,
            ],
        ];
    }

    /**
     * Request telemetry uses internal reports so grand totals recompute weighted
     * cache rates instead of summing or averaging the percentages of each bucket.
     *
     * @return list<array<string, mixed>>
     */
    protected function getModelUsageReportDefinitions(): array
    {
        $reports = [];
        foreach ([
            ['chwRptUsageDay', 'Por Dia', ['DAY:runAt'], 'ModelUsagePerDay'],
            ['chwRptUsageModel', 'Por Modelo', ['model'], 'ModelUsageByModel'],
            ['chwRptUsageTotal', 'Total', [], 'ModelUsageTotals'],
        ] as [$id, $label, $groups, $class]) {
            $reports[] = [
                'staticId' => $id,
                'name' => 'IA · Chamadas ao LLM, Tokens e Cache / ' . $label,
                'description' => 'Chamadas ao LLM concluídas e consumo de tokens, separados entre agente principal e busca na base de conhecimento. '
                    . 'Uma execução do agente pode fazer várias chamadas. Cache hit por chamada (%) = chamadas com tokens '
                    . 'de entrada em cache / chamadas com métricas completas de tokens × 100. Tokens de entrada em cache (%) = '
                    . 'tokens de entrada em cache / tokens de entrada × 100. Totais por componente, médias e máximos de tokens '
                    . 'de entrada por chamada consideram apenas chamadas com métricas completas. Os tokens de saída incluem '
                    . 'raciocínio. As taxas totais são calculadas a partir das contagens agregadas. Cobertura indica o percentual '
                    . 'de execuções com registro por chamada; dados ausentes permanecem desconhecidos. Falhas de transporte '
                    . 'sem conclusão da chamada pelo provedor não entram na contagem. Filtros de período, Ambiente, modelo '
                    . 'e tipo. Cada usuário vê apenas as execuções permitidas por sua ACL.',
                'entityType' => 'ChatwootAiAgentRun',
                'type' => 'Grid', 'columns' => ['COUNT:id'], 'groupBy' => $groups,
                'runtimeFilters' => ['runAt', 'tenant', 'model', 'kind'],
                'orderBy' => [], 'depth' => count($groups), 'chartType' => null,
                // Empty or uninstrumented buckets are unknown, not 0% cache hits.
                'fillEmptyDateBuckets' => false,
                'isInternal' => true, 'internalClassName' => 'Chatwoot:' . $class,
                'isGloballyShared' => true, 'applyAcl' => true,
            ];
        }
        return $reports;
    }

    /**
     * Native operational reports describe synchronized data; ChatwootMessage
     * is not guaranteed to contain the complete source history.
     * @return list<array<string, mixed>>
     */
    protected function getEpisodeReportDefinitions(): array
    {
        $common = [
            'entityType' => 'ChatwootConversationEpisode',
            'isInternal' => false, 'isGloballyShared' => true, 'applyAcl' => true,
        ];
        $grid = $common + [
            'type' => 'Grid', 'columns' => ['COUNT:id'], 'orderBy' => [],
            'depth' => 2, 'chartType' => 'BarGroupedVertical', 'fillEmptyDateBuckets' => true,
        ];

        return [
            ...$this->getEpisodeParticipationReportDefinitions(),
            $grid + [
                'staticId' => 'chwRptEpStart',
                'name' => 'Chat · Atendimentos Iniciados / Mês e Caixa',
                'description' => 'Volume de atendimentos: episódios iniciados no período, inclusive os ainda ativos. '
                    . 'Cada episódio conta uma vez no mês de início, no fuso do sistema. '
                    . 'Retornos após encerramento iniciam novos episódios segundo a política registrada na origem.',
                'groupBy' => ['MONTH:startedAt', 'inbox'],
                'runtimeFilters' => ['startedAt', 'chatwootAccount', 'inbox', 'boundaryPolicy', 'origin'],
            ],
            $grid + [
                'staticId' => 'chwRptEpClose',
                'name' => 'Chat · Atendimentos Encerrados / Mês e Motivo',
                'description' => 'Episódios encerrados no período, separando resolução, inatividade e mudança de política. '
                    . 'O prazo de inatividade é registrado mesmo quando o processamento ocorre depois.',
                'groupBy' => ['MONTH:closedAt', 'closeReason'],
                'runtimeFilters' => ['closedAt', 'chatwootAccount', 'inbox', 'closeReason'],
                'filtersDataList' => [[
                    'id' => 'closedEpisodes', 'name' => 'closedAt',
                    'params' => ['type' => 'isNotNull', 'data' => ['type' => 'isNotEmpty'], 'field' => 'closedAt', 'attribute' => 'closedAt'],
                ]],
            ],
            $common + [
                'staticId' => 'chwRptEpList', 'type' => 'List',
                'name' => 'Chat · Atendimentos / Base para Exportação',
                'description' => 'Uma linha por episódio, com política histórica, início, última interação, encerramento e cobertura das mensagens. '
                    . 'Etiquetas, agentes/usuários e equipes atribuídos no atendimento, sem repetição e separados por vírgulas, '
                    . 'reconstruídos a partir das atividades registradas, incluindo valores herdados no início. '
                    . 'O histórico de atividades pode ser parcial; cobertura das mensagens não garante cobertura das atribuições.',
                'columns' => ['name', 'chatwootAccount', 'inbox', 'conversation', 'boundaryPolicy', 'policyVersion',
                    'startedAt', 'lastInteractionAt', 'closedAt', 'closeReason', 'communicationSpanSeconds', 'elapsedSeconds',
                    'lifecycleTags', 'lifecycleAssignees', 'lifecycleTeams',
                    'origin', 'sourceMessageCount', 'syncedMessageCount', 'transcriptComplete'],
                'runtimeFilters' => ['startedAt', 'chatwootAccount', 'inbox', 'boundaryPolicy', 'closeReason', 'transcriptComplete'],
                'orderByList' => 'ASC:startedAt',
            ],
            [
                'staticId' => 'chwRptEpActive',
                'name' => 'Chat · Atendimentos com Interação / Mês e Caixa',
                'description' => 'Episódios distintos com interação válida no mês. Exclui notas, CSAT, mensagens de encerramento e recibos. '
                    . 'Um episódio pode contar em mais de um mês; o total mensal não representa episódios únicos no intervalo inteiro.',
                'entityType' => 'ChatwootMessage', 'type' => 'Grid',
                'columns' => ['COUNT_DISTINCT:conversationEpisodeId'],
                'columnsData' => (object) ['COUNT_DISTINCT:conversationEpisodeId' => (object) [
                    'type' => 'Summary', 'fieldType' => 'int', 'label' => 'Atendimentos-mês com interação',
                ]],
                'groupBy' => ['MONTH:episodeInteractionAt', 'conversationEpisode.inbox'],
                'runtimeFilters' => ['episodeInteractionAt', 'chatwootAccount', 'conversationEpisode.inbox'],
                'filtersDataList' => [[
                    'id' => 'qualifyingInteraction', 'name' => 'isEpisodeInteraction',
                    'params' => ['type' => 'isTrue', 'data' => ['type' => 'isTrue'], 'field' => 'isEpisodeInteraction', 'attribute' => 'isEpisodeInteraction'],
                ]],
                'orderBy' => [], 'depth' => 2, 'chartType' => 'BarGroupedVertical', 'fillEmptyDateBuckets' => true,
                'isInternal' => false, 'isGloballyShared' => true, 'applyAcl' => true,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function getEpisodeParticipationReportDefinitions(): array
    {
        $reports = [];
        foreach ([
            ['chwRptEpAgent', 'Agente', 'lifecycleAssignees', 'EpisodesByAgent'],
            ['chwRptEpTeam', 'Equipe', 'lifecycleTeams', 'EpisodesByTeam'],
        ] as [$id, $label, $group, $class]) {
            $reports[] = [
                'staticId' => $id,
                'name' => 'Chat · Atendimentos por ' . $label . ' / Participações',
                'description' => 'Atendimentos distintos por conta e nome histórico de ' . mb_strtolower($label) . '. '
                    . 'Cada atendimento conta uma vez por participante, inclusive atribuições herdadas no início. '
                    . 'O período filtra o início do atendimento, não a data de cada transferência. '
                    . 'Os totais somam participações: um atendimento pode contar para mais de um agente/equipe. '
                    . 'O drill-down lista os atendimentos únicos do grupo, com etiquetas e histórico de atribuições. '
                    . 'Nomes idênticos dentro da mesma conta formam um grupo; contas permanecem separadas. '
                    . 'Histórico ausente ou ambíguo não é contado. ACL de atendimentos aplicada a todas as consultas.',
                'entityType' => 'ChatwootConversationEpisode',
                'type' => 'Grid', 'columns' => ['COUNT:id'],
                'groupBy' => ['chatwootAccount', $group],
                'runtimeFilters' => ['startedAt', 'closedAt', 'chatwootAccount', 'inbox', 'boundaryPolicy', 'closeReason'],
                'orderBy' => [], 'depth' => 2, 'chartType' => 'BarGroupedHorizontal',
                'fillEmptyDateBuckets' => false,
                'isInternal' => true, 'internalClassName' => 'Chatwoot:' . $class,
                'isGloballyShared' => true, 'applyAcl' => true,
            ];
        }

        return $reports;
    }

    protected function getConversationReportDefinitions(): array
    {
        $incomingCount = "SUM:IF:(EQUAL:(messageType, 'incoming'), 1, 0)";
        $outgoingCount = "SUM:IF:(EQUAL:(messageType, 'outgoing'), 1, 0)";
        $openedCount = "SUM:IF:(EQUAL:(eventName, 'conversation_opened'), 1, 0)";
        $resolvedCount = "SUM:IF:(EQUAL:(eventName, 'conversation_resolved'), 1, 0)";

        return [
            [
                'staticId' => 'chwRptCvList',
                'name' => 'Chat · Conversas / Base para Exportação',
                'description' =>
                    'Uma linha por conversa sincronizada, com conta, caixa, ' .
                    'identificador, datas, etiquetas, agente e equipe atuais (na última sincronização). O filtro de período ' .
                    'usa a criação no Chat, não a criação no CRM. A contagem ' .
                    'de mensagens é a do histórico sincronizado e pode ser parcial. ' .
                    'Selecione as caixas de produção para excluir sandbox. ' .
                    'Caixa de entrada não determina marca (ex.: Eco/Sanclin). ' .
                    'Cada usuário vê apenas as conversas permitidas pela sua ACL.',
                'entityType' => 'ChatwootConversation',
                'type' => 'List',
                'columns' => [
                    'name', 'chatwootAccount', 'inbox', 'chatwootConversationId',
                    'chatwootCreatedAt', 'status', 'assigneeName', 'currentTags', 'currentTeamName',
                    'lastActivityAt', 'lastMessageReceivedAt', 'lastMessageSentAt',
                    'messagesCount', 'lastSyncedAt',
                ],
                'columnsData' => (object) [
                    'assigneeName' => (object) ['label' => 'Agente atribuído atual'],
                    'messagesCount' => (object) ['label' => 'Mensagens sincronizadas (estado atual)'],
                ],
                'runtimeFilters' => ['chatwootCreatedAt', 'chatwootAccount', 'inbox', 'status'],
                'orderByList' => 'ASC:chatwootCreatedAt',
                'isInternal' => false,
                'isGloballyShared' => true,
                'applyAcl' => true,
            ],
            [
                'staticId' => 'chwRptCvInboxMo',
                'name' => 'Chat · Conversas Criadas / Mês e Caixa',
                'description' =>
                    'Novas conversas sincronizadas, agrupadas pelo mês de criação ' .
                    'no Chat e pela caixa de entrada, no fuso do sistema. ' .
                    'Retornos na mesma conversa não são novas conversas. ' .
                    'Use os filtros de conta, caixas de produção e período. ' .
                    'Cada usuário vê apenas as conversas permitidas pela sua ACL.',
                'entityType' => 'ChatwootConversation',
                'type' => 'Grid',
                'columns' => ['COUNT:id'],
                'columnsData' => (object) [
                    'COUNT:id' => (object) ['label' => 'Conversas criadas'],
                ],
                'groupBy' => ['MONTH:chatwootCreatedAt', 'inbox'],
                'runtimeFilters' => ['chatwootCreatedAt', 'chatwootAccount', 'inbox'],
                'orderBy' => [],
                'depth' => 2,
                'chartType' => 'BarGroupedVertical',
                'fillEmptyDateBuckets' => true,
                'isInternal' => false,
                'isGloballyShared' => true,
                'applyAcl' => true,
            ],
            [
                'staticId' => 'chwRptActInboxMo',
                'name' => 'Chat · Movimento Sincronizado / Mês e Caixa',
                'description' =>
                    'Conversas distintas com mensagens públicas recebidas ou ' .
                    'enviadas no mês, inclusive IA e automações. Exclui notas ' .
                    'privadas e atividades do sistema. O período filtra a data ' .
                    'da mensagem, incluindo retornos de conversas antigas. ' .
                    'Fonte: mensagens sincronizadas no CRM; histórico incompleto ' .
                    'pode subestimar o Chat. O total soma conversas-mês, ' .
                    'não clientes nem conversas únicas de todo o período. ' .
                    'Cada usuário vê apenas as mensagens permitidas pela sua ACL.',
                'entityType' => 'ChatwootMessage',
                'type' => 'Grid',
                'columns' => ['COUNT_DISTINCT:conversationId', $incomingCount, $outgoingCount],
                'columnsData' => (object) [
                    // COUNT_DISTINCT is an ORM function. The native report
                    // engine needs an explicit Summary type to aggregate it.
                    'COUNT_DISTINCT:conversationId' => (object) [
                        'type' => 'Summary',
                        'fieldType' => 'int',
                        'label' => 'Conversas-mês com movimento',
                    ],
                    $incomingCount => (object) ['fieldType' => 'int', 'label' => 'Mensagens recebidas'],
                    $outgoingCount => (object) ['fieldType' => 'int', 'label' => 'Mensagens enviadas'],
                ],
                'groupBy' => ['MONTH:chatwootCreatedAt', 'conversation.inbox'],
                'runtimeFilters' => ['chatwootCreatedAt', 'chatwootAccount', 'conversation.inbox'],
                'orderBy' => [],
                'filtersDataList' => [
                    [
                        'id' => 'publicMessages',
                        'name' => 'isPrivate',
                        'params' => [
                            'type' => 'isFalse',
                            'data' => ['type' => 'isFalse'],
                            'field' => 'isPrivate',
                            'attribute' => 'isPrivate',
                        ],
                    ],
                    [
                        'id' => 'messageDirections',
                        'name' => 'messageType',
                        'params' => [
                            'type' => 'in',
                            'value' => ['incoming', 'outgoing'],
                            'data' => ['type' => 'anyOf', 'value' => ['incoming', 'outgoing']],
                            'field' => 'messageType',
                            'attribute' => 'messageType',
                        ],
                    ],
                ],
                'depth' => 2,
                'chartType' => 'BarGroupedVertical',
                'fillEmptyDateBuckets' => true,
                'isInternal' => false,
                'isGloballyShared' => true,
                'applyAcl' => true,
            ],
            [
                'staticId' => 'chwRptEvtInboxMo',
                'name' => 'Chat · Reaberturas e Encerramentos / Mês e Caixa',
                'description' =>
                    'Eventos registrados no mês, no fuso do sistema: reaberturas ' .
                    'e encerramentos, inclusive automáticos. Uma conversa pode ' .
                    'gerar vários eventos; estes valores não são conversas únicas ' .
                    'nem o status atual. Selecione uma conta e os IDs das caixas ' .
                    'de produção. O ID original da caixa preserva eventos de ' .
                    'conversas/caixas já removidas. Fonte: eventos sincronizados ' .
                    'no CRM, restritos pela ACL do usuário.',
                'entityType' => 'ChatwootReportingEvent',
                'type' => 'Grid',
                'columns' => [$openedCount, $resolvedCount],
                'columnsData' => (object) [
                    $openedCount => (object) ['fieldType' => 'int', 'label' => 'Reaberturas'],
                    $resolvedCount => (object) ['fieldType' => 'int', 'label' => 'Encerramentos'],
                ],
                'groupBy' => ['MONTH:happenedAt', 'chatwootInboxId'],
                'runtimeFilters' => ['happenedAt', 'chatwootAccount', 'chatwootInboxId'],
                'orderBy' => [],
                'filtersDataList' => [
                    [
                        'id' => 'lifecycleEvents',
                        'name' => 'eventName',
                        'params' => [
                            'type' => 'in',
                            'value' => ['conversation_opened', 'conversation_resolved'],
                            'data' => [
                                'type' => 'anyOf',
                                'value' => ['conversation_opened', 'conversation_resolved'],
                            ],
                            'field' => 'eventName',
                            'attribute' => 'eventName',
                        ],
                    ],
                ],
                'depth' => 2,
                'chartType' => 'BarGroupedVertical',
                'fillEmptyDateBuckets' => true,
                'isInternal' => false,
                'isGloballyShared' => true,
                'applyAcl' => true,
            ],
            [
                'staticId' => 'chwRptEvtList',
                'name' => 'Chat · Eventos / Base para Exportação',
                'description' =>
                    'Uma linha por evento sincronizado, com conta, conversa, ' .
                    'ID original da caixa, data e tipo. Use eventName para ' .
                    'selecionar reaberturas ou encerramentos. conversation_created ' .
                    'é um evento sintético; eventos podem permanecer após a ' .
                    'remoção da conversa na origem. O período usa happenedAt. ' .
                    'Cada usuário vê apenas os eventos permitidos pela sua ACL.',
                'entityType' => 'ChatwootReportingEvent',
                'type' => 'List',
                'columns' => [
                    'chatwootAccount', 'conversation', 'chatwootConversationId',
                    'chatwootInboxId', 'happenedAt', 'eventName', 'kind',
                    'chatwootReportingEventId', 'chatwootUserId',
                ],
                'runtimeFilters' => ['happenedAt', 'chatwootAccount', 'chatwootInboxId', 'eventName'],
                'orderByList' => 'ASC:happenedAt',
                'isInternal' => false,
                'isGloballyShared' => true,
                'applyAcl' => true,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function seedReport(array $config, bool $toHash): string
    {
        $staticId = (string) $config['staticId'];
        $reportId = $this->prepareId($staticId, $toHash);

        // Lift any soft-deleted row with this ID so we don't end up with
        // duplicates after the user trash-cans the record manually.
        $this->restoreSoftDeleted($reportId);

        $data = $config;
        unset($data['staticId']);
        $data['id'] = $reportId;

        $existing = $this->entityManager->getEntityById('Report', $reportId);

        if ($existing) {
            try {
                $existing->set($data);

                $this->entityManager->saveEntity($existing, [
                    'modifiedById' => 'system',
                    'skipWorkflow' => true,
                ]);

                $this->syncGloballySharedTeams($existing);

                $this->log->info(
                    "Chatwoot Module: Updated report '{$data['name']}' (ID: '{$reportId}')"
                );

                return 'updated';
            } catch (\Throwable $e) {
                $this->log->error(
                    "Chatwoot Module: Failed to update report '{$data['name']}': " . $e->getMessage()
                );

                return 'skipped';
            }
        }

        try {
            $entity = $this->entityManager->createEntity('Report', $data, [
                'createdById' => 'system',
                'skipWorkflow' => true,
            ]);

            $this->syncGloballySharedTeams($entity);

            $this->log->info(
                "Chatwoot Module: Created report '{$data['name']}' (ID: '{$reportId}')"
            );

            return 'created';
        } catch (\Throwable $e) {
            $this->log->error(
                "Chatwoot Module: Failed to create report '{$data['name']}': " . $e->getMessage()
            );

            return 'skipped';
        }
    }

    /**
     * Idempotent team sync for globally-shared reports.
     *
     * Espo's `Global\Hooks\Common\GlobalSharing::afterSave` is supposed to
     * relate the entity to every team when `isGloballyShared` becomes true,
     * but during CLI rebuild — before the hook cache is rebuilt by later
     * rebuild steps — that hook can no-op silently. This method guarantees
     * the relation by relating any missing team directly through the
     * `teams` link.
     *
     * Cost: at most one `SELECT * FROM team WHERE deleted=0` plus one
     * `isRelated` check per team. Safe to call repeatedly.
     */
    private function syncGloballySharedTeams(\Espo\ORM\Entity $report): void
    {
        if (!(bool) $report->get('isGloballyShared')) {
            return;
        }

        $repository = $this->entityManager->getRDBRepository('Report');
        $relation = $repository->getRelation($report, 'teams');

        $teams = $this->entityManager->getRDBRepository('Team')->find();

        foreach ($teams as $team) {
            if (!$relation->isRelated($team)) {
                $relation->relate($team);
            }
        }
    }

    private function restoreSoftDeleted(string $reportId): void
    {
        try {
            $pdo = $this->entityManager->getPDO();
            $stmt = $pdo->prepare(
                'UPDATE report SET deleted = false WHERE id = :id AND deleted = true'
            );
            $stmt->execute(['id' => $reportId]);

            if ($stmt->rowCount() > 0) {
                $this->log->info(
                    "Chatwoot Module: Restored soft-deleted report with ID '{$reportId}'"
                );
            }
        } catch (\Throwable $e) {
            $this->log->debug(
                "Chatwoot Module: Could not restore soft-deleted report: " . $e->getMessage()
            );
        }
    }

    private function prepareId(string $id, bool $toHash): string
    {
        return $toHash ? md5($id) : $id;
    }
}
