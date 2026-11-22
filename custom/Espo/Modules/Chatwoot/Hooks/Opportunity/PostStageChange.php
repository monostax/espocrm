<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Hooks\Opportunity;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use Throwable;

/**
 * Every stage movement of an existing opportunity leaves one event on its stream: who moved it, from which stage to
 * which. Whoever can read the opportunity's stream can read it. The mover is the acting user: a person, the AI agent's
 * CRM user or the AI watcher's.
 */
class PostStageChange implements AfterSave
{
    public const TYPE = 'OpportunityStageChanged';

    // After the stage history and status are settled.
    public static int $order = 95;

    public function __construct(private EntityManager $entityManager, private User $user, private Log $log) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if ($entity->isNew() || $options->get('silent') || !$entity->isAttributeChanged('opportunityStageId')) return;
        $toId = $entity->get('opportunityStageId');
        $fromId = $entity->getFetched('opportunityStageId');
        if (!$toId || $toId === $fromId) return;
        try {
            $to = $this->entityManager->getEntityById('OpportunityStage', $toId);
            $from = $fromId ? $this->entityManager->getEntityById('OpportunityStage', $fromId) : null;
            if (!$to) return;
            $this->entityManager->createEntity('Note', [
                'type' => self::TYPE,
                'parentType' => 'Opportunity',
                'parentId' => $entity->getId(),
                'isInternal' => true,
                'createdById' => $options->get('modifiedById') ?: $this->user->getId(),
                'data' => (object) [
                    'fromStageId' => $from?->getId(),
                    'fromStageName' => $from?->get('name'),
                    'toStageId' => $to->getId(),
                    'toStageName' => $to->get('name'),
                    'status' => $entity->get('status'),
                    'fromStatus' => $entity->getFetched('status'),
                ],
            ]);
        } catch (Throwable $e) {
            // The move itself must stand.
            $this->log->warning('PostStageChange: could not record the move of Opportunity/' . $entity->getId() . ': ' . $e->getMessage());
        }
    }
}
