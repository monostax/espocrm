<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Services;

use Espo\Core\Utils\Log;
use Espo\Modules\Crm\Entities\Opportunity;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDataset;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiEventLog;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Orchestrates: build payload -> POST to Meta -> log result.
 *
 * Called from:
 *   - Jobs\SendCapiEvent (async path)
 *   - Controllers\MetaCapiTest (sync test)
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

        // Defense in depth: even if a caller passed an explicit $dataset
        // (e.g. cal.com path, admin test endpoint), refuse to dispatch when
        // the subject and dataset belong to different tenants. Without this,
        // an admin or integration bug could silently fire Tenant B's stage
        // change under Tenant A's Pixel using Tenant A's access token.
        if ($dataset && !$this->assertTenantMatch($subject, $dataset)) {
            // Fall through to Skipped log with the cross-tenant error message.
            $dataset = null;
        }

        $logEntity = $this->createLogEntity($subject, $eventName, $dataset, $context);

        if (!$dataset) {
            $logEntity->set('status', MetaCapiEventLog::STATUS_SKIPPED);
            $logEntity->set('errorMessage', 'No MetaCapiDataset resolved for subject.');
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

        $event = $this->eventBuilder->build(
            $subject,
            $eventName,
            (string) ($dataset->get('leadEventSource') ?: 'EspoCRM'),
            $eventTime,
            $extraCustomData,
            $eventIdOverride,
        );

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
     * Returns true iff the subject and dataset belong to the same tenant
     * (or either side has no tenant set, in which case we can't assert
     * mismatch — those edge cases are logged separately when they arise).
     */
    private function assertTenantMatch(Entity $subject, MetaCapiDataset $dataset): bool
    {
        $subjectTenantId = (string) ($subject->get('tenantId') ?? '');
        $datasetTenantId = (string) ($dataset->get('tenantId') ?? '');

        if ($subjectTenantId === '' || $datasetTenantId === '') {
            // One side has no tenant — can't prove mismatch, defer to caller
            // (resolveFromOpportunity already short-circuits the case it
            // controls; admin test endpoints may legitimately use untenanted
            // entities during testing).
            return true;
        }

        if ($subjectTenantId === $datasetTenantId) {
            return true;
        }

        $this->log->error(sprintf(
            'MetaCapi dispatch refused: %s %s tenant=%s vs dataset %s tenant=%s.',
            $subject->getEntityType(),
            (string) $subject->getId(),
            $subjectTenantId,
            (string) $dataset->getId(),
            $datasetTenantId,
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
