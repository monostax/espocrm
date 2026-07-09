<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Services;

use Espo\Core\Utils\Log;
use Espo\Modules\Crm\Entities\Opportunity;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaConversionEvent;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Creates an Opportunity from an ingested MetaConversionEvent, using the
 * per-source Opportunity-creation config carried on the matching
 * MetaCapiDatasetSource junction row (funnel / opportunityStage /
 * assignedUser / createOpportunity).
 *
 * Mirrors the proven pattern in FeatureMetaLeadAds\Services\LeadgenIngester
 * (createOpportunity / resolveValidStageId / firstStageId): the chosen stage
 * is validated to belong to the chosen funnel, falling back to the funnel's
 * first active stage rather than letting the Opportunity save reject the row.
 *
 * Tenancy: Opportunity has no tenant link in stock metadata — RBAC relies on
 * `teams`. We set teamsIds (from the conversion) and defensively set tenantId.
 *
 * This is independent of the Conversions API send: a source may create
 * Opportunities without a dataset send, or send without creating an
 * Opportunity, per its config.
 */
class OpportunityFactory
{
    public function __construct(
        private EntityManager $entityManager,
        private DatasetResolver $datasetResolver,
        private Log $log,
    ) {}

    /**
     * Create (or skip) an Opportunity for a freshly-persisted conversion.
     *
     * Returns the created Opportunity, or null when creation is disabled,
     * not applicable, or failed. Never throws.
     *
     * @param array<string> $teamsIds Teams to stamp on the Opportunity.
     */
    public function createForConversion(
        MetaConversionEvent $conversion,
        ?string $contactId,
        ?string $tenantId,
        array $teamsIds,
    ): ?Opportunity {
        try {
            $channel = (string) ($conversion->get('channel') ?? '');
            $sourceId = (string) ($conversion->get('sourceId') ?? '');

            // Resolve the source by the inbound conversation's Chatwoot inbox
            // integration. The conversation's inbox uniquely maps to one
            // MetaCapiDatasetSource (via chatwootInboxIntegration), so we never
            // depend on the ad-derived sourceId string (unavailable/unstable
            // for WAHA inboxes).
            $inboxIntegrationId = $this->resolveInboxIntegrationId($conversion);

            if ($inboxIntegrationId === null) {
                $this->log->info(sprintf(
                    'MetaCapi OpportunityFactory: conversion %s has no resolvable Chatwoot inbox integration; skipping Opportunity.',
                    (string) $conversion->getId(),
                ));

                return null;
            }

            $source = $this->datasetResolver->resolveSourceByInboxIntegration($inboxIntegrationId);

            if (!$source) {
                // No source mapped to this inbox integration — nothing to create against.
                $this->log->info(sprintf(
                    'MetaCapi OpportunityFactory: no MetaCapiDatasetSource linked to inbox integration %s; skipping Opportunity for conversion %s.',
                    $inboxIntegrationId,
                    (string) $conversion->getId(),
                ));

                return null;
            }

            // createOpportunity defaults to true; only an explicit false disables.
            if ($source->get('createOpportunity') === false) {
                return null;
            }

            $funnelId = $this->str($source->get('funnelId'));

            if ($funnelId === null) {
                $this->log->info(sprintf(
                    'MetaCapi OpportunityFactory: source %s/%s has createOpportunity on but no funnel; skipping Opportunity for conversion %s.',
                    $channel,
                    $sourceId,
                    (string) $conversion->getId(),
                ));

                return null;
            }

            // Authoritative tenancy comes from the SOURCE row (its teams +
            // tenant are cascaded from the dataset), not from the conversion's
            // ChatwootAccount-derived teams. The source owns the funnel config,
            // so the Opportunity must live in the source's teams.
            $sourceTeamIds = $this->teamIds($source);

            // Cross-tenant guard (runtime). Funnel/OpportunityStage/Opportunity
            // carry NO tenantId — tenancy is via teams. Refuse to create an
            // Opportunity in a funnel that shares no team with this source.
            // Without this, a mis-wired (or hook-bypassed) source could drop a
            // conversion into another tenant's pipeline and trigger a CAPI send
            // under the wrong dataset via SendCapiOnStageChange.
            $funnel = $this->entityManager->getEntityById('Funnel', $funnelId);

            if (!$funnel) {
                $this->log->warning(sprintf(
                    'MetaCapi OpportunityFactory: funnel %s not found for source %s/%s; skipping conversion %s.',
                    $funnelId,
                    $channel,
                    $sourceId,
                    (string) $conversion->getId(),
                ));

                return null;
            }

            $funnelTeamIds = $this->teamIds($funnel);

            if (
                !empty($sourceTeamIds)
                && !empty($funnelTeamIds)
                && array_intersect($sourceTeamIds, $funnelTeamIds) === []
            ) {
                $this->log->error(sprintf(
                    'MetaCapi OpportunityFactory: cross-tenant refused — funnel %s (teams=%s) shares no team with source %s/%s (teams=%s); skipping conversion %s.',
                    $funnelId,
                    implode(',', $funnelTeamIds),
                    $channel,
                    $sourceId,
                    implode(',', $sourceTeamIds),
                    (string) $conversion->getId(),
                ));

                return null;
            }

            $contact = $contactId
                ? $this->entityManager->getEntityById('Contact', $contactId)
                : null;

            /** @var Opportunity $opp */
            $opp = $this->entityManager->getNewEntity(Opportunity::ENTITY_TYPE);

            $opp->set('name', $this->buildName($conversion, $contact));

            if ($contact) {
                $opp->set('contactId', $contact->getId());

                $accountId = $contact->get('accountId');
                if ($accountId) {
                    $opp->set('accountId', $accountId);
                }
            }

            $opp->set('funnelId', $funnelId);

            $stageId = $this->resolveValidStageId(
                $funnelId,
                (string) ($source->get('opportunityStageId') ?? ''),
                (string) ($source->getId() ?? '(new)'),
            );

            if ($stageId === null) {
                $this->log->warning(sprintf(
                    'MetaCapi OpportunityFactory: funnel %s has no active stage; cannot create Opportunity for conversion %s.',
                    $funnelId,
                    (string) $conversion->getId(),
                ));

                return null;
            }

            $opp->set('opportunityStageId', $stageId);

            // Monetary value from the conversion (Opportunity.amount is required).
            $value = $conversion->get('value');
            $opp->set('amount', is_numeric($value) ? (float) $value : 0.0);

            $currency = $this->str($conversion->get('currency'));
            if ($currency !== null) {
                $opp->set('amountCurrency', strtoupper($currency));
            }

            // assignedUser only honored if it shares a team with the source —
            // otherwise we'd assign another tenant's user. Drop silently
            // (Opportunity stays unassigned) rather than leaking the binding.
            $assignedUserId = $this->str($source->get('assignedUserId'));
            if ($assignedUserId !== null && $this->userSharesTeam($assignedUserId, $sourceTeamIds)) {
                $opp->set('assignedUserId', $assignedUserId);
            } elseif ($assignedUserId !== null) {
                $this->log->warning(sprintf(
                    'MetaCapi OpportunityFactory: assignedUser %s on source %s/%s shares no team with the source; leaving Opportunity unassigned.',
                    $assignedUserId,
                    $channel,
                    $sourceId,
                ));
            }

            $opp->set('closeDate', date('Y-m-d', strtotime('+30 days')));

            // RBAC visibility relies on teams. Stamp the SOURCE's teams (the
            // authoritative, dataset-cascaded set). Fall back to the funnel's
            // teams, then the conversion's teams, so the row is never teamless.
            $oppTeamIds = $sourceTeamIds ?: ($funnelTeamIds ?: array_values(array_unique($teamsIds)));

            if (!empty($oppTeamIds)) {
                $opp->set('teamsIds', array_values(array_unique($oppTeamIds)));
            }

            // tenantId is defensive only — Opportunity has no tenant field, so
            // this is a no-op on stock metadata, but harmless and future-proof.
            if ($tenantId) {
                $opp->set('tenantId', $tenantId);
            }

            // Stamp the originating source so the dispatcher can resolve the
            // dataset/channel directly on stage change, without re-scanning
            // MetaConversionEvent rows for this Opportunity.
            $opp->set('metaCapiDatasetSourceId', $source->getId());

            // First-touch attribution. 'CTWA' means click-to-WhatsApp ad —
            // stamp it only for the whatsapp channel. Instagram-originated
            // conversions stay unclassified ('') rather than being mislabeled;
            // the metaCapiDatasetSource link still carries full provenance.
            if ($channel !== MetaConversionEvent::CHANNEL_INSTAGRAM) {
                $opp->set('sourceChannel', 'CTWA');
            }

            // Save WITH hooks — SendCapiOnStageChange fires here. If the
            // landing stage has a metaCapiEventName on a CAPI-enabled funnel,
            // the dispatcher detects this Opportunity's CTWA/IG attribution
            // (via the linked conversion) and emits the business_messaging
            // event. This is the ONLY path that reports the conversion to Meta.
            $this->entityManager->saveEntity($opp);

            // Link the originating ChatwootConversation onto the Opportunity
            // (many-to-many `chatwootConversations`). This is what surfaces the
            // conversation thread on the Opportunity and back-links the
            // Opportunity from the conversation, regardless of whether a Contact
            // was reconciled yet. Best-effort: never fail the creation over it.
            $this->linkChatwootConversation($opp, $conversion);

            return $opp;
        } catch (Throwable $e) {
            $this->log->error(
                'MetaCapi OpportunityFactory: failed to create Opportunity for conversion '
                . (string) $conversion->getId() . ': ' . $e->getMessage()
            );

            return null;
        }
    }

    /**
     * Relate the originating ChatwootConversation to the Opportunity over the
     * many-to-many `chatwootConversations` link. Best-effort: a failure here
     * must not undo a successfully created Opportunity, so it is logged and
     * swallowed.
     */
    private function linkChatwootConversation(Opportunity $opp, MetaConversionEvent $conversion): void
    {
        $conversationId = $this->str($conversion->get('chatwootConversationId'));

        if ($conversationId === null) {
            return;
        }

        try {
            $this->entityManager
                ->getRDBRepository(Opportunity::ENTITY_TYPE)
                ->getRelation($opp, 'chatwootConversations')
                ->relateById($conversationId);
        } catch (Throwable $e) {
            $this->log->warning(sprintf(
                'MetaCapi OpportunityFactory: failed to link ChatwootConversation %s onto Opportunity %s: %s',
                $conversationId,
                (string) $opp->getId(),
                $e->getMessage(),
            ));
        }
    }

    /**
     * Resolve the ChatwootInboxIntegration id for a conversion by walking
     * conversation -> inbox -> chatwootInboxIntegration. Returns null when any
     * hop is missing (e.g. conversion not linked to a Chatwoot conversation,
     * or the inbox has no integration mapped).
     */
    private function resolveInboxIntegrationId(MetaConversionEvent $conversion): ?string
    {
        $conversationId = $this->str($conversion->get('chatwootConversationId'));

        if ($conversationId === null) {
            return null;
        }

        $conversation = $this->entityManager->getEntityById('ChatwootConversation', $conversationId);

        if (!$conversation) {
            return null;
        }

        $inboxId = $this->str($conversation->get('inboxId'));

        if ($inboxId === null) {
            return null;
        }

        $inbox = $this->entityManager->getEntityById('ChatwootInbox', $inboxId);

        if (!$inbox) {
            return null;
        }

        return $this->str($inbox->get('chatwootInboxIntegrationId'));
    }

    private function buildName(MetaConversionEvent $conversion, ?Entity $contact): string
    {
        $who = 'New Conversion';

        if ($contact) {
            $name = $contact->get('name');
            if (!$name) {
                $name = trim(
                    ((string) ($contact->get('firstName') ?? '')) . ' '
                    . ((string) ($contact->get('lastName') ?? ''))
                );
            }
            if ($name !== '') {
                $who = $name;
            }
        }

        $eventName = (string) ($conversion->get('eventName') ?? 'Conversion');

        return sprintf('%s — %s', $who, $eventName);
    }

    private function firstStageId(string $funnelId): ?string
    {
        if ($funnelId === '') {
            return null;
        }

        $stage = $this->entityManager
            ->getRDBRepository('OpportunityStage')
            ->where(['funnelId' => $funnelId, 'isActive' => true, 'deleted' => false])
            ->order('order', 'ASC')
            ->findOne();

        return $stage ? $stage->getId() : null;
    }

    /**
     * Pick a stage that actually belongs to the funnel. If the configured
     * stage is stale (deleted, or belongs to a different funnel), fall back to
     * the funnel's first active stage rather than failing the Opportunity save.
     */
    private function resolveValidStageId(string $funnelId, string $configuredStageId, string $sourceIdForLog): ?string
    {
        if ($funnelId === '') {
            return null;
        }

        if ($configuredStageId !== '') {
            $stage = $this->entityManager->getEntityById('OpportunityStage', $configuredStageId);

            if (
                $stage
                && (string) ($stage->get('funnelId') ?? '') === $funnelId
                && !$stage->get('deleted')
            ) {
                return $configuredStageId;
            }

            $this->log->warning(sprintf(
                'MetaCapi OpportunityFactory: source %s has opportunityStageId=%s which does not belong to funnelId=%s; falling back to first active stage.',
                $sourceIdForLog,
                $configuredStageId,
                $funnelId,
            ));
        }

        return $this->firstStageId($funnelId);
    }

    private function str(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }

        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }

    /**
     * Read an entity's team id list defensively.
     *
     * @return list<string>
     */
    private function teamIds(Entity $entity): array
    {
        try {
            $ids = $entity->getLinkMultipleIdList('teams') ?: [];
        } catch (Throwable) {
            $ids = [];
        }

        return array_values(array_unique(array_map('strval', $ids)));
    }

    /**
     * True when the user shares at least one team with the given set, or when
     * the comparison cannot be made (no source teams / no user teams) — in the
     * inconclusive case we defer to the config-time ValidateOpportunityConfigTenant
     * hook rather than silently dropping a legitimately-configured owner.
     *
     * @param list<string> $sourceTeamIds
     */
    private function userSharesTeam(string $userId, array $sourceTeamIds): bool
    {
        if ($sourceTeamIds === []) {
            return true;
        }

        $user = $this->entityManager->getEntityById('User', $userId);

        if (!$user) {
            return false;
        }

        $userTeamIds = $this->teamIds($user);

        if ($userTeamIds === []) {
            return true;
        }

        return array_intersect($userTeamIds, $sourceTeamIds) !== [];
    }
}
