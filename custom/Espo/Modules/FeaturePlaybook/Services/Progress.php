<?php

declare(strict_types=1);

namespace Espo\Modules\FeaturePlaybook\Services;

use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class Progress
{
    public function __construct(private EntityManager $entityManager, private User $user) {}

    public function setStep(Entity $step, string $status): void
    {
        if ($step->get('status') === $status) {
            return;
        }
        $step->set('status', $status);
        $step->set('completedAt', $status === 'Completed' ? gmdate('Y-m-d H:i:s') : null);
        $step->set('completedById', $status === 'Completed' ? $this->user->getId() : null);
        $this->entityManager->saveEntity($step);
    }

    public function refresh(string $runId): void
    {
        $run = $this->entityManager->getRDBRepository('PlaybookRun')->where(['id' => $runId])->forUpdate()->findOne();
        if (!$run || in_array($run->get('status'), ['Stopped', 'Cancelled'], true)) {
            return;
        }
        $steps = $this->entityManager->getRDBRepository('PlaybookRunStep')->where(['runId' => $runId])->find();
        $total = 0;
        $resolved = 0;
        foreach ($steps as $step) {
            $total++;
            if (in_array($step->get('status'), ['Completed', 'Skipped'], true)) {
                $resolved++;
            }
        }
        $status = $total > 0 && $total === $resolved ? 'Completed' : 'Active';
        if ($run->get('status') !== $status) {
            $run->set('status', $status);
            $this->entityManager->saveEntity($run);
        }
    }
}
