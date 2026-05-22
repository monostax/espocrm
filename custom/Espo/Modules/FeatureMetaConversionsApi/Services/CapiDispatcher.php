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
            $this->entityManager->saveEntity($dataset, ['skipHooks' => true, 'silent' => true]);
        } catch (Throwable $e) {
            $this->log->warning('MetaCapi: failed to update dataset lastEvent fields: ' . $e->getMessage());
        }
    }
}
