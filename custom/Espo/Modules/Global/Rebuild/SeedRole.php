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
            // Lets users send via Group SMTP accounts shared with their team.
            // (InboundEmail CRUD itself stays admin-only in core controller.)
            'groupEmailAccountPermission' => 'team',
            'exportPermission' => 'not-set',
            'massUpdatePermission' => 'yes',
            'dataPrivacyPermission' => 'not-set',
            'followerManagementPermission' => 'team',
            'auditPermission' => 'not-set',
            'mentionPermission' => 'team',
            'userCalendarPermission' => 'team',
            'data' => [
                'Import' => true,
                'ExternalAccount' => true,
                'Activities' => true,
                // Personal mailbox settings (IMAP/SMTP + Gmail OAuth connect).
                // Scope is boolean (scopes/EmailAccountScope.json); entity EmailAccount
                // has acl:false and is gated only through this flag + ownership.
                'EmailAccountScope' => true,
                // CRM Email entity (compose/read/archive). Required for the Email
                // tab and for sending once a personal/group account is linked.
                'Email' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'own',
                    'stream' => 'team',
                ],
                'EmailTemplate' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                ],
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
                // VoIP calls are mirrored from Chatwoot by the system user and
                // carry the tenant's team but no assignedUser, so an `own`-level
                // read would hide every mirrored Call. Use `team` so tenant
                // users see the calls shared with their team.
                'Call' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
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
                // Google Meet — virtual entities (Meet/Graph API proxies).
                // Services throw Forbidden without read access and the
                // RecordDefs mark these read-only, so grant read-only `team`
                // at the role level to keep the UI consistent with the backend.
                'GoogleMeetSpace' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'GoogleMeetConferenceRecord' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
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
                // these entities (Chatwoot:ConversationsEngaged*,
                // Chatwoot:BillingPacks*, Chatwoot:BillingExtra049*,
                // chwRptOpensDay) pass the Advanced/Report AccessChecker's
                // target-entity gate.
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
                // FeatureTrackingEvent — platform-written telemetry. The
                // Contact detail view has a read-only `trackingEvents`
                // bottom panel; without scope read access the metadata
                // filter strips the link and the panel view throws
                // "Link 'trackingEvents' is not defined in model 'Contact'".
                // Row-level ACL stays `team`, so per-tenant trimming still
                // applies.
                'TrackingEvent' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'TrackingEventType' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'TrackingSource' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                // TrackingLink: regular tenant users can see team links
                // (e.g. to copy the short URL); management is tenant-admin
                // (override below).
                'TrackingLink' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                // FeatureJourney — agents can view journeys/records; authoring
                // is tenant-admin (override below). Record/Log ACL delegates
                // to parent Journey via AccessChecker.
                'Journey' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'JourneyStage' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'JourneyStageAction' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'JourneyTransition' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'JourneyRecord' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'JourneyRecordLog' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                // FeatureAutomation — mirrors FeatureJourney: agents can view
                // automations and their run history; authoring is tenant-admin
                // (override below). Runs/items are an append-only ledger.
                //
                // Reads stay tenant-safe because Automation.tenant is readOnly
                // (derived from Teams) and RunAsUserAccess forbids a non-instance
                // -admin from delegating to an ACL-bypassing admin identity.
                'Automation' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'AutomationRun' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'AutomationRunItem' => [
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
                'ContactChannelIdentity' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
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
                // Tenant-scoped custom field schema (admin panel: Custom Field Groups / Fields).
                // Values on Contact/Account live in customFields jsonObject and follow host ACL.
                'CustomFieldGroup' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                ],
                'CustomFieldDef' => [
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
                // Allocation layer for WhatsApp campaigns: splits one audience
                // across N campaigns by weighted buckets. Entry rows
                // (WhatsAppCampaignDistributionEntry) follow this scope via a
                // custom AccessChecker, so no role grant is needed for them.
                'WhatsAppCampaignDistribution' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                ],
                'TargetList' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                ],
                'TargetListCategory' => [
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
                // Dataset↔source bindings (WABA / IG account → dataset, plus the
                // per-source Opportunity-creation config) are admin-managed too;
                // tenant-admin gets CRUD via the override below.
                'MetaCapiDatasetSource' => [
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
                // Click-to-WhatsApp / Instagram conversion events: a webhook-driven
                // audit/send log (scopes mark it create:no / edit:no). Read-only
                // for every tenant role; row-level ACL stays `team`.
                'MetaConversionEvent' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],

                // Google Ads offline conversions. Destinations and mappings are
                // tenant-admin managed; uploads are an immutable delivery log.
                'GoogleAdsDestination' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'GoogleAdsConversionMapping' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'GoogleAdsConversionUpload' => [
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
                // FeatureMetaLeadAds — the Opportunity and Contact detail views
                // have a read-only `metaLeadgenAnswers` bottom panel; without
                // scope read access the metadata filter strips the link and the
                // panel view throws "Link 'metaLeadgenAnswers' is not defined
                // in model 'Opportunity'". Row-level ACL stays `team`.
                'MetaLeadgenAnswer' => [
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

                // FeatureAgentbox — virtual catalogs (FS-backed). Scope ACL is
                // open for tenant users so the CRM UI can load; workspaceKind
                // write gates live in CatalogAuth + backend middleware:
                // regular tenant → own user pack only; tenant-admin → also
                // tenant-shared + membership; Espo admin → contact / crm-global.
                'AgentSkill' => [
                    'create' => 'yes',
                    'read' => 'yes',
                    'edit' => 'yes',
                    'delete' => 'yes',
                ],
                'AgentMode' => [
                    'create' => 'yes',
                    'read' => 'yes',
                    'edit' => 'yes',
                    'delete' => 'yes',
                ],

            ],
            'fieldData' => [
                'Email' => (object)[],
                'EmailTemplate' => (object)[],
                'IncomingWebhook' => (object)[],
                'Team' => (object)[],
                'Credential' => (object)[],
                'CredentialHistory' => (object)[],
                'CredentialType' => (object)[],
                'OAuthAccount' => (object)[],
                'MsxGoogleCalendar' => (object)[],
                'MsxGoogleCalendarUser' => (object)[],
                'GoogleMeetSpace' => (object)[],
                'GoogleMeetConferenceRecord' => (object)[],
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
                'CustomFieldGroup' => (object)[],
                'CustomFieldDef' => (object)[],
                'Tenant' => (object)[],
                'OpportunityStage' => (object)[],

                'WhatsAppBusinessAccount' => (object)[],
                'WhatsAppBusinessAccountMessageTemplate' => (object)[],
                'WhatsAppBusinessAccountPhoneNumber' => (object)[],
                'WhatsAppBusinessAccountWebhook' => (object)[],
                'WhatsAppCampaign' => (object)[],
                'WhatsAppCampaignDistribution' => (object)[],

                // Meta / Conversions API + Lead Ads + Instagram.
                // Empty fieldData entries mirror the convention used for every
                // other scope in this map — scope-level grants in `data` above
                // are what matters; sensitive fields like accessToken /
                // pageAccessToken are typed `password` and aren't returned by
                // the API regardless of role.
                'InstagramBusinessAccount' => (object)[],
                'MetaCapiDataset' => (object)[],
                'MetaCapiDatasetSource' => (object)[],
                'MetaCapiEventLog' => (object)[],
                'MetaConversionEvent' => (object)[],
                'GoogleAdsDestination' => (object)[],
                'GoogleAdsConversionMapping' => (object)[],
                'GoogleAdsConversionUpload' => (object)[],
                'MetaFacebookPage' => (object)[],
                'MetaLeadForm' => (object)[],
                'MetaLeadgenEvent' => (object)[],
                'MetaLeadgenAnswer' => (object)[],

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
                'ContactChannelIdentity' => (object)[],
                'ChatwootConversation' => (object)[],
                'ChatwootInbox' => (object)[
                    'channelType' => (object)['read' => 'yes', 'edit' => 'no'],
                    // Linking Chatwoot teams to an inbox GRANTS the team's
                    // members access to that inbox in Chatwoot (department-
                    // scoped inbox privacy). Regular tenant users may see the
                    // links but only tenant-admin may change them (override
                    // in the tenant-admin role below).
                    'chatwootTeams' => (object)['read' => 'yes', 'edit' => 'no'],
                ],
                'ChatwootInboxIntegration' => (object)[
                    // Same rationale as ChatwootInbox.chatwootTeams — teams
                    // set here are auto-linked to the inbox at provisioning.
                    'chatwootTeams' => (object)['read' => 'yes', 'edit' => 'no'],
                ],
                'ChatwootMessage' => (object)[],
                'ChatwootAiAgentRun' => (object)[],
                'ChatwootReportingEvent' => (object)[],
                'ChatwootSyncState' => (object)[],

                'TrackingEvent' => (object)[],
                'TrackingEventType' => (object)[],
                'TrackingSource' => (object)[],
                'TrackingLink' => (object)[],
                'Journey' => (object)[],
                'JourneyStage' => (object)[],
                'JourneyStageAction' => (object)[],
                'JourneyTransition' => (object)[],
                'JourneyRecord' => (object)[],
                'JourneyRecordLog' => (object)[],
                'Automation' => (object)[],
                'AutomationRun' => (object)[],
                'AutomationRunItem' => (object)[],

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

                'AgentSkill' => (object)[],
                'AgentMode' => (object)[],

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
                    // tenant-admin binds WABA / IG business accounts to a dataset
                    // and configures the per-source Opportunity creation
                    // (funnel / stage / assignedUser). MetaConversionEvent stays
                    // read-only (audit/send log) for tenant-admin too.
                    'MetaCapiDatasetSource' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],

                    // Google Ads destinations and mappings are configurable;
                    // upload records remain read-only delivery history.
                    'GoogleAdsDestination' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'GoogleAdsConversionMapping' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'GoogleAdsConversionUpload' => [
                        'create' => 'no',
                        'read' => 'team',
                        'edit' => 'no',
                        'delete' => 'no',
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

                    // FeatureTrackingEvent — tenant-admin manages ingestion
                    // configuration through the Configurations panel
                    // (adminForUserPanel): creates Tracking Sources (ingest
                    // URL + signing secret rotation) and curates the
                    // TrackingEventType dictionary. TrackingEvent itself
                    // stays read-only for every tenant role (see base) —
                    // it's an append-only ledger enforced by the BlockWrite/
                    // BlockDelete record hooks regardless of ACL.
                    'TrackingSource' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'TrackingEventType' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'TrackingLink' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],

                    // FeatureJourney — tenant-admin authors journeys/stages/
                    // transitions/actions; can edit active records (pause);
                    // logs remain read-only (append-only ledger).
                    'Journey' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'JourneyStage' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'JourneyStageAction' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'JourneyTransition' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'JourneyRecord' => [
                        'create' => 'no',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'no',
                    ],
                    'JourneyRecordLog' => [
                        'create' => 'no',
                        'read' => 'team',
                        'edit' => 'no',
                        'delete' => 'no',
                    ],

                    // FeatureAutomation — tenant-admin authors automations and may
                    // cancel/pause runs; run items stay read-only (append-only ledger).
                    'Automation' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'AutomationRun' => [
                        'create' => 'no',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'no',
                    ],
                    'AutomationRunItem' => [
                        'create' => 'no',
                        'read' => 'team',
                        'edit' => 'no',
                        'delete' => 'no',
                    ],

                    // Custom Fields — tenant-admin manages schema (groups +
                    // typed defs) via the Configurations / admin-for-user
                    // panel. Values on Contact/Lead/Account/Opportunity ride
                    // host-entity ACL and need no separate grant here. Base
                    // tenant role gets no CustomField* access so agents do
                    // not see schema admin links.
                    'CustomFieldGroup' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                    'CustomFieldDef' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                    ],
                ],
                'fieldData' => [
                    ...$tenantBase['fieldData'],
                    'GoogleAdsDestination' => (object)[],
                    'GoogleAdsConversionMapping' => (object)[],
                    'GoogleAdsConversionUpload' => (object)[],
                    // Tenant-admin manages inbox↔team links (grants Chatwoot
                    // inbox access to department teams); base tenant role is
                    // read-only on these fields.
                    'ChatwootInbox' => (object)[
                        'channelType' => (object)['read' => 'yes', 'edit' => 'no'],
                        'chatwootTeams' => (object)['read' => 'yes', 'edit' => 'yes'],
                    ],
                    'ChatwootInboxIntegration' => (object)[
                        'chatwootTeams' => (object)['read' => 'yes', 'edit' => 'yes'],
                    ],
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
            $sql = "UPDATE role SET deleted = false WHERE id = :id AND deleted = true";
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
