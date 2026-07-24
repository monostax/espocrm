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
 * Rebuild action that seeds (creates or updates) Chatwoot's internal-class
 * reports with deterministic IDs.
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
                'name' => 'AI Agent Run (Uso de Tokens por Ambiente / Por Dia)',
                'description' =>
                    'Consumo de tokens LLM (entrada, entrada em cache, ' .
                    'saída) e número de execuções do Agente IA, agrupado ' .
                    'por dia e por Ambiente. Use para acompanhar o gasto ' .
                    'diário por tenant. ACL-strict: cada usuário vê ' .
                    'somente as execuções permitidas por sua ACL em ' .
                    'ChatwootAiAgentRun.',
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
                'name' => 'AI Agent Run (Uso de Tokens por Ambiente / Total)',
                'description' =>
                    'Total de tokens LLM (total, entrada, entrada em ' .
                    'cache, saída) e execuções do Agente IA por Ambiente, ' .
                    'ordenado do maior para o menor consumo. Use o filtro ' .
                    'de período (runAt) para limitar a janela analisada. ' .
                    'ACL-strict: cada usuário vê somente as execuções ' .
                    'permitidas por sua ACL em ChatwootAiAgentRun.',
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
                // Pricing matrix: Tenant × Model with the three billable
                // token classes. This is the direct input for a cost /
                // price-sheet calculation, since LLM pricing is per
                // model and per token class:
                //
                //   cost ≈ (input − cached) × p_in
                //        + cached × p_cacheRead   (discounted)
                //        + output × p_out         (the expensive side)
                //
                // Grid engine caps grouping at 2 dimensions, so the
                // date window is a runtime filter (`runAt`) rather than
                // a group. Use `kind` to isolate follow-up spend.
                'staticId' => 'chwRptTokTnMdl',
                'name' => 'AI Agent Run (Uso de Tokens por Ambiente / Por Modelo)',
                'description' =>
                    'Matriz Ambiente × Modelo LLM com tokens de entrada, ' .
                    'entrada em cache e saída — a base para cálculo de ' .
                    'custo, já que o preço é por modelo e por classe de ' .
                    'token. Use o filtro de período (runAt) para limitar ' .
                    'a janela. ACL-strict: cada usuário vê somente as ' .
                    'execuções permitidas por sua ACL em ChatwootAiAgentRun.',
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
                'name' => 'AI Agent Run (Uso de Tokens por Tipo / Por Dia)',
                'description' =>
                    'Consumo de tokens LLM e execuções por dia, dividido ' .
                    'por tipo de gatilho: Mensagem do Cliente, @Menção em ' .
                    'Nota Privada, @Menção Pública, Mensagem Agendada e ' .
                    'Disparador de Follow-up. Use para separar o gasto ' .
                    'gerado por clientes do uso interno (@menções de ' .
                    'agentes) e de follow-ups proativos — a base para ' .
                    'cobrança por tipo de uso. ACL-strict: cada usuário ' .
                    'vê somente as execuções permitidas por sua ACL em ' .
                    'ChatwootAiAgentRun.',
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
                'name' => 'AI Agent Run (Conversas Engajadas por Ambiente / Por Dia)',
                'description' =>
                    'Distinct ChatwootConversation count engaged by the AI agent ' .
                    'per Tenant (Ambiente) per day. ACL-strict: each viewer only ' .
                    'sees runs they can read on ChatwootAiAgentRun.',
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
                'name' => 'AI Agent Run (Conversas Engajadas / Por Dia)',
                'description' =>
                    'Distinct ChatwootConversation count engaged by the AI agent ' .
                    'per day. Tenancy is not a grouping dimension — each viewer ' .
                    'sees only the count their ACL on ChatwootAiAgentRun permits.',
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
                'name' => 'IA Faturamento · Pacotes / Por Dia',
                'description' =>
                    'Modelo pacote: preço por bloco de N turnos de IA por ' .
                    'conversa e por dia civil (fuso do sistema). Todo tipo ' .
                    'de execução conta como turno (mensagem do cliente, ' .
                    'menção, follow-up, agendada). Valores e tamanho do ' .
                    'bloco vêm do Faturamento IA de cada Ambiente (Tenant); ' .
                    'sem override usa o padrão da plataforma. Colunas: ' .
                    'Valor, Pacotes, Turnos, Conversas-dia. ACL-strict em ' .
                    'ChatwootAiAgentRun. Drill-down lista as conversas do balde.',
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
                'name' => 'IA Faturamento · Pacotes / Ambiente / Por Dia',
                'description' =>
                    'Modelo pacote agrupado por Ambiente e dia civil. Preço ' .
                    'do pacote e turnos por bloco são por Ambiente (Tenant → ' .
                    'Faturamento IA); label do Ambiente inclui a moeda do ' .
                    'acordo. Use para faturar multi-tenant ou comparar ' .
                    'receita estimada entre ambientes. ACL-strict.',
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
            // base once per conversation-day + unit × (customer overages
            // + non-customer runs). Rates from Tenant AI Billing.
            // ------------------------------------------------------------------
            [
                'staticId' => 'chwRptBillE49Day',
                'name' => 'IA Faturamento · Base + Extra / Por Dia',
                'description' =>
                    'Modelo de negociação: base por conversa engajada no dia ' .
                    'civil (inclui até N turnos de mensagem do cliente) + ' .
                    'preço unitário por engajamento extra (turnos de cliente ' .
                    'além do incluído e cada execução que não seja mensagem ' .
                    'do cliente: menção, follow-up, agendada, etc.). Valores ' .
                    'e N vêm do Faturamento IA de cada Ambiente; sem override ' .
                    'usa o padrão da plataforma. Colunas: Valor, Bases, ' .
                    'Extras de turno, Extras de tipo, Extras totais. ' .
                    'ACL-strict. Para comparar side-by-side com o modelo pacote.',
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
                'name' => 'IA Faturamento · Base + Extra / Ambiente / Por Dia',
                'description' =>
                    'Modelo de negociação (base + extra) por Ambiente e dia ' .
                    'civil. Tarifas por Ambiente (Tenant → Faturamento IA). ' .
                    'Ideal para propostas comerciais e comparativo de fatura ' .
                    'estimada com o modelo pacote. ACL-strict.',
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
                // Counts AI-agent-emitted follow-up messages per day,
                // grouped by AI Agent (Chatwoot account/user membership).
                // The metric is "messages actually sent", NOT "follow-up
                // runs that fired" — `sentFollowupMessage` is producer-
                // derived from the run's tool-call list and is true iff
                // the `send_followup_message` tool was invoked at least
                // once. This intentionally excludes follow-up runs that
                // terminated via a deliberate opportunity update
                // (Ended / ActionNeeded) or a silent-done failure, both
                // of which the AI takes as alternative outcomes to
                // sending a message.
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
                'name' => 'AI Agent Run (Mensagens de Follow-up Enviadas / Por Dia / Por Agente IA)',
                'description' =>
                    'Número de mensagens de follow-up efetivamente enviadas ' .
                    'pelo Agente IA, agrupado por dia e por agente. Exclui ' .
                    'execuções de follow-up que terminaram em atualização ' .
                    'deliberada de oportunidade (Ended / ActionNeeded) ou ' .
                    'em silent-done. ACL-strict: cada usuário vê somente ' .
                    'as execuções permitidas por sua ACL em ChatwootAiAgentRun.',
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
