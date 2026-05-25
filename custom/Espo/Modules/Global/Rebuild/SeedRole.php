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

namespace Espo\Modules\Global\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Metadata;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;

/**
 * Rebuild action to seed/update system roles with static IDs.
 * Runs automatically during system rebuild.
 */
class SeedRole implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
        private Log $log
    ) {}

    public function process(): void
    {
        $this->log->info('Global Module: Starting to seed/update system roles...');

        // Check if UUID mode is enabled
        $toHash = $this->metadata->get(['app', 'recordId', 'type']) === 'uuid4' ||
                  $this->metadata->get(['app', 'recordId', 'dbType']) === 'uuid';

        // Define all roles to be seeded
        $roles = $this->getRoleDefinitions();

        $createdCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;

        foreach ($roles as $roleConfig) {
            $result = $this->seedRole($roleConfig, $toHash);
            
            if ($result === 'created') {
                $createdCount++;
            } elseif ($result === 'updated') {
                $updatedCount++;
            } else {
                $skippedCount++;
            }
        }

        $this->log->info(
            "Global Module: Role seeding complete. " .
            "Created: {$createdCount}, Updated: {$updatedCount}, Skipped: {$skippedCount}"
        );
    }

    /**
     * Get the base tenant role configuration.
     * Used as a foundation for tenant and tenant-admin roles.
     */
    protected function getTenantBaseConfig(): array
    {
        return [
            'assignmentPermission' => 'team',
            'userPermission' => 'team',
            'messagePermission' => 'team',
            'portalPermission' => 'not-set',
            'groupEmailAccountPermission' => 'team',
            'exportPermission' => 'not-set',
            'massUpdatePermission' => 'not-set',
            'dataPrivacyPermission' => 'not-set',
            'followerManagementPermission' => 'team',
            'auditPermission' => 'not-set',
            'mentionPermission' => 'team',
            'userCalendarPermission' => 'team',
            'data' => [
                'Import' => true,
                'ExternalAccount' => true,
                'Activities' => true,
                'Appointment' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                ],
                'Meeting' => [
                    'create' => 'yes',
                    'read' => 'own',
                    'edit' => 'own',
                    'delete' => 'own',
                ],
                'Call' => [
                    'create' => 'yes',
                    'read' => 'own',
                    'edit' => 'own',
                    'delete' => 'own',
                ],
                'CredentialHistory' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'CredentialType' => [
                    'create' => 'no',
                    'read' => 'all',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'Credential' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'OAuthProvider' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                ],
                'OAuthAccount' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                ],
                'MsxGoogleCalendar' => true,
                'MsxGoogleCalendarUser' => [
                    'create' => 'yes',
                    'read' => 'own',
                    'edit' => 'own',
                    'delete' => 'own',
                ],
                'ChatwootAccount' => [
                        'create' => 'no',
                        'read' => 'team',
                        'edit' => 'no',
                        'delete' => 'no',
                        'stream' => 'no',
                    ],
                'ChatwootInbox' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                ],
                'ChatwootAccountUserMembership' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                ],
                'ChatwootTeam' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                ],
                'ChatwootLabel' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                ],
                'ChatwootConversation' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                ],
                'ChatwootMessage' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                // Read-only telemetry the platform writes. Tenant users
                // need scope read access so the seeded reports built on
                // these entities (Chatwoot:ConversationsEngagedByTenantPerDay,
                // Chatwoot:ConversationsEngagedPerDay, chwRptOpensDay) pass
                // the Advanced/Report AccessChecker's target-entity gate.
                // Row-level ACL is `team`, so the per-tenant trimming
                // inside the report's withStrictAccessControl() still
                // applies — each user only counts runs/events on their
                // own teams.
                'ChatwootAiAgentRun' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'ChatwootReportingEvent' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'WahaSessionLabel' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'ChatwootUser' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                ],
                'ChatwootInboxIntegration' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                ],
                'ChatwootContactInbox' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'ChatwootContact' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'User' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                ],
                // UserApiKey uses `acl: "boolean"` (scope-level yes/no only).
                // Per-record ownership is enforced by the custom AccessChecker
                // (`Espo\Modules\Global\Classes\Acl\UserApiKey\AccessChecker`),
                // so granting `true` here just opens the scope gate; the
                // checker still limits non-admins to their own keys.
                'UserApiKey' => true,
                'Document' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                    'stream' => 'team',
                ],
                'DocumentFolder' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                    'stream' => 'team',
                ],
                'KnowledgeBaseCategory' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                    'stream' => 'team',
                ],
                'KnowledgeBaseArticle' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                    'stream' => 'team',
                ],
                'Calendar' => true,
                'Contact' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                    'stream' => 'team',
                ],
                'Case' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'own',
                    'delete' => 'own',
                    'stream' => 'team',
                ],
                'Task' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'own',
                    'delete' => 'own',
                    'stream' => 'team',
                ],
                'Opportunity' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                    'stream' => 'team',
                ],
                'Funnel' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                ],
                'Tenant' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'OpportunityStage' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                ],
                // Meta / WhatsApp Business — virtual entities (Graph API proxies).
                // RecordDefs mark these readOnly/createDisabled, so granting only
                // read at the role level keeps the UI consistent with the backend.
                'WhatsAppBusinessAccount' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'WhatsAppBusinessAccountMessageTemplate' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'WhatsAppBusinessAccountPhoneNumber' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'WhatsAppBusinessAccountWebhook' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'WhatsAppCampaign' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                ],

                // Meta / Instagram — virtual entity backed by Graph API.
                'InstagramBusinessAccount' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],

                // Meta / Conversions API. Datasets are admin-managed (tenant-admin
                // role gets CRUD via override below); event log is read-only for
                // every tenant role.
                'MetaCapiDataset' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'MetaCapiEventLog' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],

                // Meta / Lead Ads. Pages and lead forms are configuration entities
                // managed by tenant-admin (overrides below). Leadgen events are
                // a webhook-driven log; tenant-admin can edit to use the
                // `retryLeadgenIngest` mass action (declared with acl=edit in
                // FeatureMetaLeadAds/Resources/metadata/recordDefs/MetaLeadgenEvent.json).
                'MetaFacebookPage' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'MetaLeadForm' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'MetaLeadgenEvent' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],

                // Reports (Advanced module). Full team CRUD at the base level
                // so tenant users can author, view, and manage their own
                // reports — and so the `Report` / `ReportTotalCount` dashlets
                // pass the `acl->tryCheck('Report')` filter in
                // `Espo\Tools\App\MetadataService::process` and remain
                // visible in the client metadata payload.
                'Report' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                ],

                'Unidade' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'Profissional' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'ProcedimentoConsulta' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'TipoProfissional' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'Especialidade' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'CanalAgendamento' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'UnidadeDosagem' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'Jornada' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'own',
                    'stream' => 'team',
                ],
                'Sessao' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'own',
                ],
                'Atendimento' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'own',
                    'stream' => 'team',
                ],
                'ProcedimentoRealizado' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'own',
                ],
                'FeatureClinicaBasePaciente' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'own',
                    'stream' => 'team',
                ],
                'Convenio' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'TabelaDePrecos' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'ProcedimentoInjetavel' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'ProcedimentoImplante' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'ProcedimentoEstetico' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'ProcedimentoAtividadeFisica' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'ConvenioRegra' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'Programa' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'ProgramaItem' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'Prescricao' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'own',
                    'stream' => 'team',
                ],
                'PrescricaoItem' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'own',
                ],
                'Anamnese' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'own',
                    'stream' => 'team',
                ],
                'Prontuario' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'own',
                    'stream' => 'team',
                ],
                'Documento' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'own',
                    'stream' => 'team',
                ],
                'Insumo' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'InsumoLote' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'MovimentacaoEstoque' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'ConsumoInsumoPadrao' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'ConsumoInsumo' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'own',
                ],
                'Orcamento' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'own',
                    'stream' => 'team',
                ],
                'OrcamentoItem' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'own',
                ],
                'LancamentoFinanceiro' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'own',
                    'stream' => 'team',
                ],

            ],
            'fieldData' => [
                'Email' => (object)[],
                'IncomingWebhook' => (object)[],
                'Team' => (object)[],
                'Credential' => (object)[],
                'CredentialHistory' => (object)[],
                'CredentialType' => (object)[],
                'OAuthAccount' => (object)[],
                'MsxGoogleCalendar' => (object)[],
                'MsxGoogleCalendarUser' => (object)[],
                'OAuthProvider' => (object)[
                    'isGloballyShared' => (object)['read' => 'no', 'edit' => 'no'],
                ],
                'User' => (object)[
                    'userName' => (object)['read' => 'yes', 'edit' => 'yes'],
                    'password' => (object)['read' => 'yes', 'edit' => 'yes'],
                    'emailAddress' => (object)['read' => 'yes', 'edit' => 'yes'],
                    'isActive' => (object)['read' => 'yes', 'edit' => 'yes'],
                    'teams' => (object)['read' => 'yes', 'edit' => 'yes'],
                    'defaultTeam' => (object)['read' => 'yes', 'edit' => 'yes'],
                ],
                // Empty fieldData entry — the entity has `aclFieldLevelDisabled: true`
                // in its scopes config so per-field role overrides are
                // irrelevant. Listed here for completeness/grep-ability.
                'UserApiKey' => (object)[],
                'Account' => (object)[],
                'Call' => (object)[],
                'Campaign' => (object)[],
                'ChatwootAccount' => (object)[
                    'apiKey' => (object)['read' => 'no', 'edit' => 'no'],
                ],
                'Case' => (object)[],
                'Contact' => (object)[],
                'DocumentFolder' => (object)[],
                'Document' => (object)[],
                'KnowledgeBaseArticle' => (object)[],
                'KnowledgeBaseCategory' => (object)[],
                'Lead' => (object)[],
                'Meeting' => (object)[],
                'Opportunity' => (object)[
                    'account' => (object)['read' => 'no', 'edit' => 'no'],
                ],
                'TargetListCategory' => (object)[],
                'TargetList' => (object)[],
                'Task' => (object)[],
                'Activities' => (object)[],
                'Funnel' => (object)[],
                'Tenant' => (object)[],
                'OpportunityStage' => (object)[],

                'WhatsAppBusinessAccount' => (object)[],
                'WhatsAppBusinessAccountMessageTemplate' => (object)[],
                'WhatsAppBusinessAccountPhoneNumber' => (object)[],
                'WhatsAppBusinessAccountWebhook' => (object)[],
                'WhatsAppCampaign' => (object)[],

                // Meta / Conversions API + Lead Ads + Instagram.
                // Empty fieldData entries mirror the convention used for every
                // other scope in this map — scope-level grants in `data` above
                // are what matters; sensitive fields like accessToken /
                // pageAccessToken are typed `password` and aren't returned by
                // the API regardless of role.
                'InstagramBusinessAccount' => (object)[],
                'MetaCapiDataset' => (object)[],
                'MetaCapiEventLog' => (object)[],
                'MetaFacebookPage' => (object)[],
                'MetaLeadForm' => (object)[],
                'MetaLeadgenEvent' => (object)[],

                // Empty entry mirrors the convention used for every other
                // scope in this map — no per-field overrides; the scope-level
                // grant in `data.Report` above is what matters.
                'Report' => (object)[],

                'ChatwootPlatform' => (object)[],
                'ChatwootTeam' => (object)[],
                'ChatwootUser' => (object)[],
                'ChatwootAccountWebhook' => (object)[],
                'ChatwootContact' => (object)[],
                'ChatwootContactInbox' => (object)[],
                'ChatwootConversation' => (object)[],
                'ChatwootInbox' => (object)[
                    'channelType' => (object)['read' => 'yes', 'edit' => 'no'],
                ],
                'ChatwootMessage' => (object)[],
                'ChatwootAiAgentRun' => (object)[],
                'ChatwootReportingEvent' => (object)[],
                'ChatwootSyncState' => (object)[],

                'Unidade' => (object)[],
                'Profissional' => (object)[],
                'ProcedimentoConsulta' => (object)[],
                'TipoProfissional' => (object)[],
                'Especialidade' => (object)[],
                'CanalAgendamento' => (object)[],
                'UnidadeDosagem' => (object)[],
                'Jornada' => (object)[],
                'Sessao' => (object)[],
                'Atendimento' => (object)[],
                'ProcedimentoRealizado' => (object)[],
                'FeatureClinicaBasePaciente' => (object)[],
                'Convenio' => (object)[],
                'TabelaDePrecos' => (object)[],
                'ProcedimentoInjetavel' => (object)[],
                'ProcedimentoImplante' => (object)[],
                'ProcedimentoEstetico' => (object)[],
                'ProcedimentoAtividadeFisica' => (object)[],
                'ConvenioRegra' => (object)[],
                'Programa' => (object)[],
                'ProgramaItem' => (object)[],
                'Prescricao' => (object)[],
                'PrescricaoItem' => (object)[],
                'Anamnese' => (object)[],
                'Prontuario' => (object)[],
                'Documento' => (object)[],
                'Insumo' => (object)[],
                'InsumoLote' => (object)[],
                'MovimentacaoEstoque' => (object)[],
                'ConsumoInsumoPadrao' => (object)[],
                'ConsumoInsumo' => (object)[],
                'Orcamento' => (object)[],
                'OrcamentoItem' => (object)[],
                'LancamentoFinanceiro' => (object)[],

            ],
        ];
    }

    /**
     * Define all roles to be seeded.
     * Add new roles here with their configuration.
     */
    protected function getRoleDefinitions(): array
    {
        $tenantBase = $this->getTenantBaseConfig();

        return [

            [
                'staticId' => 'tenant-b2b',
                'name' => 'tenant-b2b',
                'data' => [
                    'Account' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                        'stream' => 'team',
                ]],
                'fieldData' => [
                    'Opportunity' => (object)[
                        'account' => (object)['read' => 'yes', 'edit' => 'yes'],
                    ],
                ],
            ],
            [
                'staticId' => 'tenant-clinica',
                'name' => 'tenant-clinica',
                'data' => [
                    'FeatureClinicaBasePaciente' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'own',
                        'stream' => 'team',
                    ],
                    'FeatureIntegrationClinicaNasNuvensSettings' => [
                        'create' => 'no',
                        'read' => 'team',
                        'edit' => 'no',
                        'delete' => 'no',
                    ],
                    'FeatureIntegrationClinicaNasNuvensConvenioTipo' => [
                        'create' => 'no',
                        'read' => 'team',
                        'edit' => 'no',
                        'delete' => 'no',
                    ],
                    'FeatureIntegrationClinicaNasNuvensConsultaTipo' => [
                        'create' => 'no',
                        'read' => 'team',
                        'edit' => 'no',
                        'delete' => 'no',
                    ],
                    'FeatureIntegrationClinicaNasNuvensProcedimentoTipo' => [
                        'create' => 'no',
                        'read' => 'team',
                        'edit' => 'no',
                        'delete' => 'no',
                    ],
                    'FeatureIntegrationClinicaNasNuvensProcedimentoConvenio' => [
                        'create' => 'no',
                        'read' => 'team',
                        'edit' => 'no',
                        'delete' => 'no',
                    ],
                    'FeatureIntegrationClinicaNasNuvensPaciente' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'own',
                    ],
                    'FeatureIntegrationClinicaNasNuvensProfissional' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'own',
                    ],
                    'FeatureIntegrationClinicaNasNuvensAgendamento' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'own',
                    ],
                    'FeatureIntegrationClinicaNasNuvensAgendamentoProcedimento' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'own',
                    ],
                    'FeatureIntegrationClinicaNasNuvensFaturamento' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'own',
                    ],
                ],
                'fieldData' => [
                    'Opportunity' => (object)[
                        'account' => (object)['read' => 'no', 'edit' => 'no'],
                    ],
                    'FeatureIntegrationClinicaNasNuvensSettings' => (object)[],
                    'FeatureIntegrationClinicaNasNuvensConvenioTipo' => (object)[],
                    'FeatureIntegrationClinicaNasNuvensConsultaTipo' => (object)[],
                    'FeatureIntegrationClinicaNasNuvensProcedimentoTipo' => (object)[],
                    'FeatureIntegrationClinicaNasNuvensProcedimentoConvenio' => (object)[],
                    'FeatureIntegrationClinicaNasNuvensPaciente' => (object)[],
                    'FeatureIntegrationClinicaNasNuvensProfissional' => (object)[],
                    'FeatureIntegrationClinicaNasNuvensAgendamento' => (object)[],
                    'FeatureIntegrationClinicaNasNuvensAgendamentoProcedimento' => (object)[],
                    'FeatureIntegrationClinicaNasNuvensFaturamento' => (object)[],
                ],
            ],
            // Tenant role - base role for tenant users
            [
                'staticId' => 'tenant',
                'name' => 'tenant',
                ...$tenantBase,
            ],
            // Tenant Admin role - inherits from tenant, can manage users in their team
            [
                'staticId' => 'tenant-admin',
                'name' => 'tenant-admin',
                ...$tenantBase,
                'data' => [
                    ...$tenantBase['data'],
                    'MsxGoogleCalendarUser' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'Meeting' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'Call' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'Task' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                        'stream' => 'team',
                    ],
                    'Unidade' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'Profissional' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'ProcedimentoConsulta' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'TipoProfissional' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'Especialidade' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'CanalAgendamento' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'UnidadeDosagem' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'FeatureClinicaBasePaciente' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                        'stream' => 'team',
                    ],
                    'Convenio' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'TabelaDePrecos' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'ProcedimentoInjetavel' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'ProcedimentoImplante' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'ProcedimentoEstetico' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'ProcedimentoAtividadeFisica' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'ConvenioRegra' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'Programa' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'ProgramaItem' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'Insumo' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'InsumoLote' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'MovimentacaoEstoque' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'no',
                        'delete' => 'no',
                    ],
                    'ConsumoInsumoPadrao' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'ConsumoInsumo' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'own',
                    ],
                    'Orcamento' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                        'stream' => 'team',
                    ],
                    'OrcamentoItem' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'LancamentoFinanceiro' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                        'stream' => 'team',
                    ],

                    // Meta / Conversions API — tenant-admin manages datasets
                    // (creates, edits credentials, etc.). Event log stays
                    // read-only since it's an audit trail.
                    'MetaCapiDataset' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],

                    // Meta / Lead Ads — tenant-admin syncs Facebook Pages,
                    // configures lead forms (funnel/stage/fieldMapping), and
                    // can retry failed leadgen ingestions via the mass action
                    // (which requires edit permission on MetaLeadgenEvent).
                    'MetaFacebookPage' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'MetaLeadForm' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'MetaLeadgenEvent' => [
                        'create' => 'no',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'no',
                    ],
                ],
                'fieldData' => [
                    ...$tenantBase['fieldData']
                ]
            ],
            // Tenant User API role - inherits from tenant, adds Webhook access
            [
                'staticId' => 'tenant-user-api',
                'name' => 'tenant-user-api',
                ...$tenantBase,
                'data' => [
                    ...$tenantBase['data'],
                    'Webhook' => true,
                ],
            ]
            // Add more roles here as needed
            // Example:
            // [
            //     'staticId' => 'manager',
            //     'name' => 'manager',
            //     'assignmentPermission' => 'all',
            //     ...
            // ],
        ];
    }

    /**
     * Seed or update a single role.
     * Returns 'created', 'updated', or 'skipped'.
     */
    protected function seedRole(array $roleConfig, bool $toHash): string
    {
        $staticId = $roleConfig['staticId'];
        $roleId = $this->prepareId($staticId, $toHash);
        
        // First, try to restore any soft-deleted role with this ID
        $this->restoreSoftDeletedRole($roleId);

        // Prepare role data (remove staticId from config as it's not a DB field)
        $roleData = $roleConfig;
        unset($roleData['staticId']);
        $roleData['id'] = $roleId;

        // Check if role already exists by ID (should be active after restoration)
        $existingRole = $this->entityManager->getEntityById('Role', $roleId);

        if ($existingRole) {
            // Update existing role
            try {
                $existingRole->set($roleData);
                
                $this->entityManager->saveEntity($existingRole, [
                    'modifiedById' => 'system',
                    'skipWorkflow' => true,
                ]);
                
                $this->log->info("Global Module: Updated role '{$roleData['name']}' (ID: '{$roleId}')");
                return 'updated';
            } catch (\Exception $e) {
                $this->log->error("Global Module: Failed to update role '{$roleData['name']}': " . $e->getMessage());
                return 'skipped';
            }
        } else {
            // Create new role
            try {
                $this->entityManager->createEntity('Role', $roleData, [
                    'createdById' => 'system',
                    'skipWorkflow' => true,
                ]);
                $this->log->info("Global Module: Created role '{$roleData['name']}' (ID: '{$roleId}')");
                return 'created';
            } catch (\Exception $e) {
                $this->log->error("Global Module: Failed to create role '{$roleData['name']}': " . $e->getMessage());
                return 'skipped';
            }
        }
    }

    /**
     * Restore soft-deleted role with the given ID using raw SQL.
     */
    protected function restoreSoftDeletedRole(string $roleId): void
    {
        try {
            $pdo = $this->entityManager->getPDO();
            $sql = "UPDATE `role` SET `deleted` = 0 WHERE `id` = :id AND `deleted` = 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $roleId]);
            
            if ($stmt->rowCount() > 0) {
                $this->log->info("Global Module: Restored soft-deleted Tenant role with ID '{$roleId}'");
            }
        } catch (\Exception $e) {
            $this->log->debug("Global Module: Could not restore soft-deleted role (might not exist): " . $e->getMessage());
        }
    }

    /**
     * Prepare ID for entity.
     * If UUID mode is enabled, returns MD5 hash of the ID.
     * Otherwise, returns the ID as-is.
     */
    protected function prepareId(string $id, bool $toHash): string
    {
        if ($toHash) {
            return md5($id);
        }

        return $id;
    }
}
