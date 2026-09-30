<?php

declare(strict_types=1);

namespace Espo\Modules\FeaturePlaybook\Hooks\Task;

use Espo\Core\Acl;
use Espo\Core\ApplicationState;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Modules\FeaturePlaybook\Services\Progress;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class PlaybookProgress
{
    public static int $order = 90;

    public function __construct(
        private EntityManager $entityManager,
        private Progress $progress,
        private Acl $acl,
        private ApplicationState $applicationState,
    ) {}

    private function step(Entity $task): ?Entity
    {
        if (!$task->hasId()) {
            return null;
        }
        return $this->entityManager->getRDBRepository('PlaybookRunStep')->where(['taskId' => $task->getId()])->findOne();
    }

    public function beforeSave(Entity $entity, array $options): void
    {
        $step = $this->step($entity);
        if (!$step) {
            return;
        }
        $run = $this->lockRun($step);
        if ($entity->get('parentType') !== 'Opportunity' || $entity->get('parentId') !== $run->get('opportunityId')) {
            throw new Conflict('A playbook Task cannot be moved to a different parent.');
        }
        if ($entity->isAttributeChanged('status') &&
            in_array($run->get('status'), ['Stopped', 'Cancelled'], true) && $entity->get('status') !== 'Canceled') {
            throw new Conflict('Resume the playbook before changing the Task status.');
        }
    }

    public function afterSave(Entity $entity, array $options): void
    {
        if (!$entity->isAttributeChanged('status')) {
            return;
        }
        $step = $this->step($entity);
        if (!$step) {
            return;
        }
        $status = match ($entity->get('status')) {
            'Completed' => 'Completed',
            'Canceled' => 'Cancelled',
            default => 'Pending',
        };
        $this->progress->setStep($step, $status);
        $this->progress->refresh($step->get('runId'));
    }

    public function afterRemove(Entity $entity, array $options): void
    {
        $step = $this->step($entity);
        if ($step && $step->get('status') !== 'Completed') {
            $this->progress->setStep($step, 'Cancelled');
            $this->progress->refresh($step->get('runId'));
        }
    }

    public function beforeRemove(Entity $entity, array $options): void
    {
        $step = $this->step($entity);
        if (!$step) {
            return;
        }
        $this->lockRun($step);
    }

    private function lockRun(Entity $step): Entity
    {
        $run = $this->entityManager->getEntityById('PlaybookRun', $step->get('runId'));
        if (!$run) {
            throw new Conflict('The playbook run no longer exists.');
        }
        // Serialize Task edits with apply/stop/step operations for this opportunity.
        $opportunity = $this->entityManager->getRDBRepository('Opportunity')
            ->where(['id' => $run->get('opportunityId')])->forUpdate()->findOne();
        if (!$opportunity || ($this->applicationState->isLogged() &&
            (!$this->acl->checkEntityRead($opportunity) || !$this->acl->checkEntityEdit($opportunity)))) {
            throw new Forbidden('The playbook opportunity cannot be edited.');
        }
        return $this->entityManager->getRDBRepository('PlaybookRun')
            ->where(['id' => $run->getId()])->forUpdate()->findOne() ?? throw new Conflict();
    }
}
