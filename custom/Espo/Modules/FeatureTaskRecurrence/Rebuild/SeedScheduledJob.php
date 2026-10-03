<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTaskRecurrence\Rebuild;

use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Rebuild\RebuildAction;
use Espo\ORM\EntityManager;

class SeedScheduledJob implements RebuildAction
{
    public function __construct(private EntityManager $entityManager) {}

    public function process(): void
    {
        if ($this->entityManager->getRDBRepository('ScheduledJob')->where(['job' => 'ProcessTaskRecurrences'])->findOne()) return;
        $this->entityManager->createEntity('ScheduledJob', [
            'name' => 'Process Task Recurrences', 'job' => 'ProcessTaskRecurrences',
            'status' => 'Active', 'scheduling' => '* * * * *',
        ], [SaveOption::SKIP_ALL => true]);
    }
}
