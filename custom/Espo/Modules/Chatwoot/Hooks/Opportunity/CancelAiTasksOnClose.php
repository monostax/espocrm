<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Hooks\Opportunity;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata;
use Espo\Modules\Global\Tools\Opportunity\StageRequirements;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use Throwable;

/**
 * A deal that moves to Won or Lost, whoever moves it, cancels the open tasks an AI made for it (the opportunity watcher
 * or an AI agent: `aiTaskKey` set). Tasks a person made stay, as possible post-sale work; calls and meetings, which can
 * be the booked appointment, are never touched. A cancelled task that was the deal's next step leaves it (user
 * decisions, 2026-11-23). Reopening restores nothing: the watcher plans again.
 */
class CancelAiTasksOnClose implements AfterSave
{
    // After the stage history, status and stage pill are settled.
    public static int $order = 96;

    private const CLOSED = ['Won', 'Lost'];

    public function __construct(private EntityManager $entityManager, private Metadata $metadata, private Log $log) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if ($entity->isNew() || !$entity->isAttributeChanged('status') || !in_array($entity->get('status'), self::CLOSED, true)) {
            return;
        }
        try {
            $finished = $this->metadata->get(['entityDefs', 'Task', 'fields', 'status', 'notActualOptions']) ?? ['Completed', 'Canceled'];
            $tasks = $this->entityManager->getRDBRepository('Task')->where([
                'parentType' => 'Opportunity',
                'parentId' => $entity->getId(),
                'status!=' => $finished,
                'aiTaskKey!=' => null,
            ])->find();
            $cancelled = [];
            foreach ($tasks as $task) {
                $task->set('status', 'Canceled');
                $this->entityManager->saveEntity($task);
                $cancelled[] = $task->getId();
            }
            if (!$cancelled) return;
            $deal = $this->entityManager->getEntityById('Opportunity', $entity->getId());
            if ($deal && $deal->get('nextActionType') === 'Task' && in_array($deal->get('nextActionId'), $cancelled, true)) {
                $deal->set(['nextActionId' => null, 'nextActionType' => null]);
                $this->entityManager->saveEntity($deal, [StageRequirements::BOOKKEEPING => true]);
            }
        } catch (Throwable $e) {
            // The close itself must stand.
            $this->log->warning('CancelAiTasksOnClose: Opportunity/' . $entity->getId() . ': ' . $e->getMessage());
        }
    }
}
