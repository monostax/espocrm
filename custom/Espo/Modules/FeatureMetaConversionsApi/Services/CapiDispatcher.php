<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Services;

use Espo\Core\Utils\Log;
use Espo\Modules\Crm\Entities\Opportunity;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDataset;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiEventLog;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaConversionEvent;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Orchestrates: build payload -> POST to Meta -> log result.
 *
 * Sending is funnel-driven: the sole entry point is dispatch(), invoked from
 *   - Jobs\SendCapiEvent (async path, enqueued by Hooks\Opportunity\SendCapiOnStageChange)
 *   - Controllers\MetaCapiTest (sync test)
 *
 * Stage-change attribution: when the Opportunity originated from a
 * Click-to-WhatsApp / Instagram ad (it has a linked MetaConversionEvent with a
 * ctwaClid/igSid + sourceId), dispatch() builds a `business_messaging` event
 * attributed by those join keys instead of the hashed-PII `system_generated`
 * event. The destination dataset is still the funnel/stage dataset. This is
 * the ONLY path that emits business_messaging events — MetaConversionEvent rows
 * are inbound attribution records and are never dispatched directly.
 */
class CapiDispatcher
{
    public function __construct(
        private EntityManager $entityManager,
        private EventBuilder $eventBuilder,
        private MetaCapiClient $client,
        private DatasetResolver $resolver,
        private Log $log,
    ) {}

    /**
     * @param array<string, mixed> $context  Optional context:
     *      - opportunityStageFromId, opportunityStageToId: stage transition (Opportunity case)
     *      - eventId: override the computed event_id (e.g. cal.com bookingUid)
     *      - customData: extra fields merged into custom_data
     *      - subjectType, subjectId: override the subject linkParent on the log
     *        (e.g. for cal.com bookings where subject is the Contact but log should also reference the booking)
     */
    public function dispatch(
        Entity $subject,
        string $eventName,
        ?MetaCapiDataset $dataset = null,
        array $context = [],
    ): MetaCapiEventLog {
        $dataset = $dataset ?? $this->resolver->resolveForEntity($subject);

        $skipReason = null;

        // Defense in depth: even if a caller passed an explicit $dataset
        // (e.g. cal.com path, admin test endpoint), refuse to dispatch when
        // the subject and dataset are not provably in the same tenant. Without
        // this, an admin or integration bug could silently fire Tenant B's
        // stage change under Tenant A's Pixel using Tenant A's access token.
        if ($dataset && !$this->assertTenantMatch($subject, $dataset)) {
            $dataset = null;
            $skipReason = 'Cross-tenant dispatch refused: subject and MetaCapiDataset '
                . 'are not provably in the same tenant.';
        }

        $logEntity = $this->createLogEntity($subject, $eventName, $dataset, $context);

        if (!$dataset) {
            $logEntity->set('status', MetaCapiEventLog::STATUS_SKIPPED);
            $logEntity->set('errorMessage', $skipReason ?? 'No MetaCapiDataset resolved for subject.');
            $this->entityManager->saveEntity($logEntity);

            return $logEntity;
        }

        $eventTime = time();
        $logEntity->set('eventTime', date('Y-m-d H:i:s', $eventTime));

        /** @var array<string, mixed>|null $extraCustomData */
        $extraCustomData = isset($context['customData']) && is_array($context['customData'])
            ? $context['customData']
            : null;

        $eventIdOverride = isset($context['eventId']) && is_string($context['eventId']) && $context['eventId'] !== ''
            ? $context['eventId']
            : null;

        // Routing for Opportunity stage-change events. Two action sources,
        // chosen per source via MetaCapiDatasetSource.stageEventActionSource:
        //
        //   - system_generated (default): hashed-PII event. Correct for
        //     conversions that happen OUTSIDE the message thread (CRM funnel
        //     changes). Meta attributes it to the originating ad click via the
        //     matched identity. Accepts any event name.
        //
        //   - business_messaging: in-thread event keyed by the conversion's
        //     ctwa_clid/ig_sid + the source's WABA/IG account id. Only valid
        //     for Meta's restricted event vocabulary (e.g. Purchase,
        //     LeadSubmitted). Requires a CTWA/IG-originated Opportunity (a
        //     linked MetaConversionEvent) and a resolvable account id; if
        //     either is missing we fall back to system_generated.
        $useBusinessMessaging = false;
        $ctwaConversion = null;
        $messagingAccountId = null;

        if ($subject instanceof Opportunity) {
            $source = $this->resolveStageEventSource($subject);
            $actionSource = $source
                ? (string) ($source->get('stageEventActionSource') ?: 'system_generated')
                : 'system_generated';

            if ($actionSource === 'business_messaging') {
                $ctwaConversion = $this->findCtwaConversionForOpportunity($subject);

                if ($ctwaConversion) {
                    $channel = (string) ($ctwaConversion->get('channel') ?: 'whatsapp');
                    $messagingAccountId = $this->resolveMessagingAccountId($subject, $channel);
                    $useBusinessMessaging = $messagingAccountId !== null;

                    if (!$useBusinessMessaging) {
                        $this->log->info(sprintf(
                            'MetaCapi dispatch: Opportunity %s source opts into business_messaging but no messaging account id is resolvable; falling back to system_generated.',
                            (string) $subject->getId(),
                        ));
                    }
                }
            }
        }

        if ($useBusinessMessaging && $ctwaConversion && $messagingAccountId !== null) {
            $event = $this->buildBusinessMessagingFromConversion(
                $ctwaConversion,
                $messagingAccountId,
                $eventName,
                $eventTime,
                $eventIdOverride,
            );
        } else {
            $event = $this->eventBuilder->build(
                $subject,
                $eventName,
                (string) ($dataset->get('leadEventSource') ?: 'EspoCRM'),
                $eventTime,
                $extraCustomData,
                $eventIdOverride,
            );
        }

        if (!$event) {
            $logEntity->set('status', MetaCapiEventLog::STATUS_SKIPPED);
            $logEntity->set('errorMessage', 'EventBuilder produced no payload (no identity).');
            $this->entityManager->saveEntity($logEntity);

            return $logEntity;
        }

        try {
            $result = $this->client->sendEvents($dataset, [$event]);
        } catch (Throwable $e) {
            $this->log->error('MetaCapi dispatch exception: ' . $e->getMessage());

            $logEntity->set('status', MetaCapiEventLog::STATUS_FAILED);
            $logEntity->set('errorMessage', $e->getMessage());
            $logEntity->set('requestPayload', ['data' => [$event]]);
            $this->entityManager->saveEntity($logEntity);

            $this->updateDatasetLastSent($dataset, false, $e->getMessage());

            return $logEntity;
        }

        $logEntity->set('httpStatus', $result->httpStatus);
        $logEntity->set('requestPayload', $result->requestPayload);
        $logEntity->set('responsePayload', $result->responsePayload);
        $logEntity->set('eventsReceived', $result->eventsReceived);
        $logEntity->set('fbtraceId', $result->fbtraceId);

        if ($result->success) {
            $logEntity->set('status', MetaCapiEventLog::STATUS_SENT);
        } else {
            $logEntity->set('status', MetaCapiEventLog::STATUS_FAILED);
            $logEntity->set('errorMessage', $result->errorMessage);
        }

        $this->entityManager->saveEntity($logEntity);

        $this->updateDatasetLastSent(
            $dataset,
            $result->success,
            $result->success ? null : $result->errorMessage,
        );

        return $logEntity;
    }

    /**
     * Find the most recent MetaConversionEvent linked to this Opportunity that
     * carries a usable Click-to-WhatsApp / Instagram join key (ctwa_clid /
     * ig_sid). Returns null when the Opportunity is not CTWA/IG-originated, so
     * the caller falls back to the PII build path.
     */
    private function findCtwaConversionForOpportunity(Opportunity $opportunity): ?MetaConversionEvent
    {
        $opportunityId = $opportunity->getId();

        if (!$opportunityId) {
            return null;
        }

        $conversions = $this->entityManager
            ->getRDBRepository(MetaConversionEvent::ENTITY_TYPE)
            ->where([
                'opportunityId' => $opportunityId,
                'deleted'       => false,
            ])
            ->order('createdAt', 'DESC')
            ->find();

        foreach ($conversions as $conversion) {
            if ($this->conversionAttribution($conversion) !== null) {
                return $conversion;
            }
        }

        return null;
    }

    /**
     * Resolve the channel-appropriate attribution key for a conversion:
     * ig_sid for instagram, ctwa_clid otherwise. Null when missing.
     *
     * Note: this validates the join KEY only (ctwa_clid / ig_sid). The
     * messaging account id (whatsapp_business_account_id / instagram_business_
     * account_id) is NOT read from the conversion's ad-derived sourceId — it is
     * resolved authoritatively from the Opportunity's linked
     * MetaCapiDatasetSource -> ChatwootInboxIntegration in dispatch().
     */
    private function conversionAttribution(MetaConversionEvent $conversion): ?string
    {
        $channel = (string) ($conversion->get('channel') ?: 'whatsapp');
        $attribution = $channel === 'instagram'
            ? trim((string) ($conversion->get('igSid') ?? ''))
            : trim((string) ($conversion->get('ctwaClid') ?? ''));

        return $attribution !== '' ? $attribution : null;
    }

    /**
     * Build a business_messaging event payload for a stage-change event,
     * attributed via the conversion's CTWA/IG join key + the resolved
     * messaging account id (WABA id / IG business account id) from the
     * Opportunity's linked source integration. Uses the stage-derived
     * $eventName (not the conversion's own eventName) and a stable per-stage
     * event_id so a benign re-save dedupes.
     *
     * @return array<string, mixed>|null
     */
    private function buildBusinessMessagingFromConversion(
        MetaConversionEvent $conversion,
        string $sourceId,
        string $eventName,
        int $eventTime,
        ?string $eventIdOverride,
    ): ?array {
        $channel = (string) ($conversion->get('channel') ?: 'whatsapp');
        $attribution = (string) $this->conversionAttribution($conversion);

        // Dedupe id: prefer caller override, else derive from the conversion +
        // stage event name so repeated saves into the same stage collapse.
        $eventId = $eventIdOverride
            ?? sprintf('%s:%s', (string) $conversion->getId(), $eventName);

        $value = $conversion->get('value');

        return $this->eventBuilder->buildBusinessMessaging(
            $channel,
            $sourceId,
            $attribution,
            $eventName,
            $eventTime,
            $value !== null ? (float) $value : null,
            $conversion->get('currency') ? (string) $conversion->get('currency') : null,
            $eventId,
        );
    }

    /**
     * Resolve the MetaCapiDatasetSource an Opportunity was created from, via
     * its stamped metaCapiDatasetSource link. Returns null when the Opportunity
     * was not created from a CTWA/IG source (e.g. a normal lead). Used to read
     * the per-source stageEventActionSource routing preference.
     */
    private function resolveStageEventSource(Opportunity $opportunity): ?Entity
    {
        $sourceId = $opportunity->get('metaCapiDatasetSourceId');

        if (!$sourceId) {
            return null;
        }

        return $this->entityManager->getEntityById('MetaCapiDatasetSource', (string) $sourceId);
    }

    /**
     * Resolve the messaging account id (whatsapp_business_account_id for
     * whatsapp, instagram_business_account_id for instagram) for an
     * Opportunity, from its linked MetaCapiDatasetSource.
     *
     * The authoritative value is the source's own `sourceId` — the admin
     * configures the real WABA id / IG business account id there (it is the
     * value Meta's CAPI expects as user_data.whatsapp_business_account_id /
     * instagram_business_account_id). This works even for QR-only WAHA inboxes,
     * because the id lives on the source, not on the inbox integration.
     *
     * Falls back to the linked ChatwootInboxIntegration's channel id only when
     * the source's sourceId is empty. Returns null when neither is available.
     */
    private function resolveMessagingAccountId(Opportunity $opportunity, string $channel): ?string
    {
        $sourceId = $opportunity->get('metaCapiDatasetSourceId');

        if (!$sourceId) {
            return null;
        }

        $source = $this->entityManager->getEntityById('MetaCapiDatasetSource', (string) $sourceId);

        if (!$source) {
            return null;
        }

        // Primary: the admin-configured account id on the source itself.
        $accountId = trim((string) ($source->get('sourceId') ?? ''));

        if ($accountId !== '') {
            return $accountId;
        }

        // Fallback: derive from the linked inbox integration's channel id.
        $integrationId = $source->get('chatwootInboxIntegrationId');

        if (!$integrationId) {
            return null;
        }

        $integration = $this->entityManager->getEntityById('ChatwootInboxIntegration', (string) $integrationId);

        if (!$integration) {
            return null;
        }

        $accountId = $channel === 'instagram'
            ? trim((string) ($integration->get('instagramId') ?? ''))
            : trim((string) ($integration->get('businessAccountId') ?? ''));

        return $accountId !== '' ? $accountId : null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function createLogEntity(
        Entity $subject,
        string $eventName,
        ?MetaCapiDataset $dataset,
        array $context,
    ): MetaCapiEventLog {
        /** @var MetaCapiEventLog $entity */
        $entity = $this->entityManager->getNewEntity(MetaCapiEventLog::ENTITY_TYPE);

        $entity->set('eventName', $eventName);
        $entity->set('status', MetaCapiEventLog::STATUS_PENDING);
        $entity->set('name', sprintf('%s — %s', $eventName, $subject->getEntityType()));

        if ($dataset) {
            $entity->set('metaCapiDatasetId', $dataset->getId());

            // Normal path: CascadeTeamsFromMetaCapiDataset (order 1) and
            // CascadeTenantFromMetaCapiDataset (order 1) will populate
            // teamsIds/tenantId at BeforeSave from the dataset.
        } else {
            // Skipped path (no dataset resolved, or cross-tenant refused).
            //
            // The cascade hooks early-return when metaCapiDatasetId is empty,
            // so without this we'd persist an orphan log row with empty
            // teamsIds/tenantId. EspoCRM's ORM saveEntity bypasses
            // FieldValidationManager (only Core\Record\Service runs it),
            // so the row would persist silently and be invisible to all
            // team-scoped ACL queries.
            //
            // Propagate teams + tenant from the subject (Opportunity/Contact)
            // so the audit log row stays correctly tenant-scoped even when
            // dispatch is refused.
            $this->propagateTenancyFromSubject($entity, $subject);
        }

        $entity->set('subjectType', $subject->getEntityType());
        $entity->set('subjectId', $subject->getId());

        if ($subject instanceof Opportunity) {
            $entity->set('opportunityId', $subject->getId());

            $contactId = $subject->get('contactId');
            if ($contactId) {
                $entity->set('contactId', $contactId);
            }
        } elseif ($subject->getEntityType() === 'Contact') {
            $entity->set('contactId', $subject->getId());
        }

        if (!empty($context['opportunityStageFromId'])) {
            $entity->set('opportunityStageFromId', $context['opportunityStageFromId']);
        }
        if (!empty($context['opportunityStageToId'])) {
            $entity->set('opportunityStageToId', $context['opportunityStageToId']);
        }

        return $entity;
    }

    private function updateDatasetLastSent(MetaCapiDataset $dataset, bool $success, ?string $error): void
    {
        $dataset->set('lastEventSentAt', date('Y-m-d H:i:s'));
        $dataset->set('lastEventStatus', $success
            ? MetaCapiDataset::STATUS_SUCCESS
            : MetaCapiDataset::STATUS_FAILED);
        $dataset->set('lastEventError', $error);

        try {
            // Status-only write — skipHooks=true skips EncryptAccessToken
            // (idempotent but wasteful — would re-restore the ciphertext
            // every time) and AssignTenantFromTeam (tenant doesn't change
            // on a status update). silent=true suppresses Stream / audit
            // noise for these mechanical writes.
            $this->entityManager->saveEntity($dataset, ['skipHooks' => true, 'silent' => true]);
        } catch (Throwable $e) {
            $this->log->warning('MetaCapi: failed to update dataset lastEvent fields: ' . $e->getMessage());
        }
    }

    /**
     * Returns true iff the subject and dataset are both known to belong to the
     * SAME tenant. Fails closed: an unresolvable tenant on either side is a
     * refusal, because same-tenancy cannot be proven and the payload carries
     * hashed PII to a third party under the dataset's access token.
     */
    private function assertTenantMatch(Entity $subject, MetaCapiDataset $dataset): bool
    {
        $subjectTenantId = (string) ($subject->get('tenantId') ?? '');
        $datasetTenantId = (string) ($dataset->get('tenantId') ?? '');

        if ($subjectTenantId !== '' && $subjectTenantId === $datasetTenantId) {
            return true;
        }

        $this->log->error(sprintf(
            'MetaCapi dispatch refused: %s %s tenant=%s vs dataset %s tenant=%s (both must be set and equal).',
            $subject->getEntityType(),
            (string) $subject->getId(),
            $subjectTenantId !== '' ? $subjectTenantId : '(unset)',
            (string) $dataset->getId(),
            $datasetTenantId !== '' ? $datasetTenantId : '(unset)',
        ));

        return false;
    }

    /**
     * Set teamsIds + tenantId on a MetaCapiEventLog from its subject entity
     * (Opportunity / Contact). Only used on the Skipped path where no
     * parent dataset is available; the normal path leaves these to the
     * Cascade*FromMetaCapiDataset hooks.
     */
    private function propagateTenancyFromSubject(MetaCapiEventLog $entity, Entity $subject): void
    {
        $tenantId = $subject->get('tenantId');

        if ($tenantId) {
            $entity->set('tenantId', $tenantId);
        }

        $teamIds = [];

        try {
            if (method_exists($subject, 'getLinkMultipleIdList')) {
                $teamIds = $subject->getLinkMultipleIdList('teams') ?: [];
            }
        } catch (Throwable) {
            $teamIds = [];
        }

        if (empty($teamIds)) {
            $teamsAttr = $subject->get('teamsIds');
            if (is_array($teamsAttr) && !empty($teamsAttr)) {
                $teamIds = $teamsAttr;
            }
        }

        if (!empty($teamIds)) {
            $entity->set('teamsIds', array_values(array_unique($teamIds)));
        }
    }
}
