<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Hooks\Common;

use Espo\Core\Hook\Hook\AfterRemove;
use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Job\QueueName;
use Espo\Modules\Chatwoot\Jobs\BroadcastOpportunityUpdate;
use Espo\Modules\Chatwoot\Services\OpportunityStreamEvents;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\RemoveOptions;
use Espo\ORM\Repository\Option\SaveOptions;

/** Persist the notification in the same transaction as the opportunity change. */
class PublishOpportunityUpdate implements AfterSave, AfterRemove
{
    public static int $order = 99;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        $this->schedule($entity);
    }

    public function afterRemove(Entity $entity, RemoveOptions $options): void
    {
        $this->schedule($entity);
    }

    private function schedule(Entity $entity): void
    {
        $type = $entity->getEntityType();
        if ($type === 'Opportunity') {
            $opportunity = $entity;
        } elseif ($type === 'OpportunityReadState') {
            $opportunity = $this->entityManager->getEntityById('Opportunity', $entity->get('opportunityId'));
        } elseif ($type === 'Note' && $entity->get('parentType') === 'Opportunity' &&
            in_array($entity->get('type'), OpportunityStreamEvents::TYPES, true)) {
            $opportunity = $this->entityManager->getEntityById('Opportunity', $entity->get('parentId'));
        } else {
            return;
        }

        if (!$opportunity || !$opportunity->get('tenantId')) {
            return;
        }

        // Also invalidate the old tenant after a move, without exposing the record ID to either account.
        $tenantIds = array_values(array_unique(array_filter([
            $opportunity->get('tenantId'),
            $type === 'Opportunity' ? $opportunity->getFetched('tenantId') : null,
        ])));

        // DB-backed jobs cannot be consumed before commit and disappear on rollback.
        // Never perform network I/O while a Note/read-state transaction holds its locks.
        $this->entityManager->createEntity('Job', [
            'name' => BroadcastOpportunityUpdate::class,
            'className' => BroadcastOpportunityUpdate::class,
            'queue' => QueueName::Q0,
            'attempts' => 3,
            'data' => (object) [
                'opportunityId' => $opportunity->getId(),
                'tenantIds' => $tenantIds,
                'userId' => $type === 'OpportunityReadState' ? $entity->get('userId') : null,
            ],
        ]);
    }
}
