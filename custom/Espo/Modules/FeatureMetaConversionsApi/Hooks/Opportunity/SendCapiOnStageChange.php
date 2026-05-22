<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Hooks\Opportunity;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\Utils\Log;
use Espo\Modules\Crm\Entities\Opportunity;
use Espo\Modules\FeatureMetaConversionsApi\Jobs\SendCapiEvent;
use Espo\Modules\FeatureMetaConversionsApi\Services\DatasetResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Detects OpportunityStage changes and enqueues a Meta CAPI event per
 * the stage's metaCapiEventName / metaCapiEventNameOnLeave configuration.
 *
 * Runs ASYNC via JobSchedulerFactory to never block the user's save.
 *
 * @implements AfterSave<Opportunity>
 */
class SendCapiOnStageChange implements AfterSave
{
    public static int $order = 20;

    public function __construct(
        private EntityManager $entityManager,
        private JobSchedulerFactory $jobSchedulerFactory,
        private DatasetResolver $resolver,
        private Log $log,
    ) {}

    /**
     * @param Opportunity $entity
     */
    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof Opportunity) {
            return;
        }

        $newStageId = $entity->get('opportunityStageId');
        $oldStageId = $entity->getFetched('opportunityStageId');

        $isNew = $entity->isNew();

        // Nothing changed -> nothing to do.
        if (!$isNew && $oldStageId === $newStageId) {
            return;
        }

        // Resolve dataset early so we can skip cheaply if not configured.
        $dataset = $this->resolver->resolveFromOpportunity($entity);

        if (!$dataset) {
            return;
        }

        $leaveEventName = null;
        if (!$isNew && $oldStageId) {
            $oldStage = $this->entityManager->getEntityById('OpportunityStage', $oldStageId);
            $leaveEventName = $oldStage ? trim((string) $oldStage->get('metaCapiEventNameOnLeave')) : '';
            $leaveEventName = $leaveEventName !== '' ? $leaveEventName : null;
        }

        $enterEventName = null;
        if ($newStageId) {
            $newStage = $this->entityManager->getEntityById('OpportunityStage', $newStageId);
            $enterEventName = $newStage ? trim((string) $newStage->get('metaCapiEventName')) : '';
            $enterEventName = $enterEventName !== '' ? $enterEventName : null;
        }

        if ($leaveEventName === null && $enterEventName === null) {
            return;
        }

        // Fire leave-event first, then enter-event.
        if ($leaveEventName !== null) {
            $this->enqueue($entity->getId(), $leaveEventName, $oldStageId, $newStageId);
        }

        if ($enterEventName !== null) {
            $this->enqueue($entity->getId(), $enterEventName, $oldStageId, $newStageId);
        }
    }

    private function enqueue(
        string $opportunityId,
        string $eventName,
        ?string $stageFromId,
        ?string $stageToId,
    ): void {
        try {
            $this->jobSchedulerFactory
                ->create()
                ->setClassName(SendCapiEvent::class)
                ->setData([
                    'entityType' => Opportunity::ENTITY_TYPE,
                    'entityId'   => $opportunityId,
                    'eventName'  => $eventName,
                    'context'    => [
                        'opportunityStageFromId' => $stageFromId,
                        'opportunityStageToId'   => $stageToId,
                    ],
                ])
                ->setGroup('meta-capi-' . $opportunityId)
                ->schedule();
        } catch (\Throwable $e) {
            $this->log->error(sprintf(
                'MetaCapi: failed to enqueue event %s for Opportunity %s: %s',
                $eventName,
                $opportunityId,
                $e->getMessage(),
            ));
        }
    }
}
