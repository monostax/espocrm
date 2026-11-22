<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDataset;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiEventLog;
use Espo\Modules\FeatureMetaConversionsApi\Services\CapiDispatcher;
use Espo\ORM\EntityManager;

/**
 * Background worker that sends a Meta CAPI event for a CRM entity.
 *
 * Payload (Data):
 *   - entityType: string (e.g. "Opportunity", "Contact")
 *   - entityId:   string
 *   - eventName:  string (Meta event_name, e.g. "Lead", "Purchase", "Schedule")
 *   - datasetId:  string|null  Optional explicit dataset (skips DatasetResolver).
 *                              Used by cal.com webhook where binding is by apiKey.
 *   - context:    array{
 *       opportunityStageFromId?: string,
 *       opportunityStageToId?:   string,
 *       eventId?:   string,                 // override event_id for dedupe
 *       customData?: array<string,mixed>,   // extra custom_data fields
 *     }
 */
class SendCapiEvent implements Job
{
    public function __construct(
        private EntityManager $entityManager,
        private CapiDispatcher $dispatcher,
        private Log $log,
    ) {}

    public function run(Data $data): void
    {
        $entityType = (string) $data->get('entityType');
        $entityId   = (string) $data->get('entityId');
        $eventName  = (string) $data->get('eventName');
        $datasetId  = $data->get('datasetId');
        /** @var array<string, mixed>|null $context */
        $context    = $data->get('context');

        if ($entityType === '' || $entityId === '' || $eventName === '') {
            $this->log->warning('SendCapiEvent: missing required job data (entityType/entityId/eventName).');
            return;
        }

        $entity = $this->entityManager->getEntityById($entityType, $entityId);

        if (!$entity) {
            $this->log->warning(sprintf(
                'SendCapiEvent: %s %s not found; skipping event %s.',
                $entityType,
                $entityId,
                $eventName,
            ));
            return;
        }

        // A deal's stage events go out once each (2026-11-22): a reopened deal that enters the stage again, or leaves
        // it again, sends nothing new. Jobs for one deal run in its own group, one at a time, so the check is not raced.
        if ($entityType === 'Opportunity' && is_array($context) && array_key_exists('opportunityStageToId', $context)
            && $this->alreadySent($entityId, $eventName)) {
            $this->log->info(sprintf('SendCapiEvent: %s already sent for Opportunity %s; skipping.', $eventName, $entityId));
            return;
        }

        $dataset = null;

        if (is_string($datasetId) && $datasetId !== '') {
            $loaded = $this->entityManager->getEntityById('MetaCapiDataset', $datasetId);
            if ($loaded instanceof MetaCapiDataset) {
                $dataset = $loaded;
            } else {
                $this->log->warning(sprintf(
                    'SendCapiEvent: MetaCapiDataset %s not found; falling back to resolver.',
                    $datasetId,
                ));
            }
        }

        $this->dispatcher->dispatch(
            $entity,
            $eventName,
            $dataset,
            is_array($context) ? $context : [],
        );
    }

    private function alreadySent(string $opportunityId, string $eventName): bool
    {
        return (bool) $this->entityManager->getRDBRepository(MetaCapiEventLog::ENTITY_TYPE)
            ->select(['id'])
            ->where([
                'opportunityId' => $opportunityId,
                'eventName' => $eventName,
                'status' => MetaCapiEventLog::STATUS_SENT,
                'deleted' => false,
            ])
            ->findOne();
    }
}
