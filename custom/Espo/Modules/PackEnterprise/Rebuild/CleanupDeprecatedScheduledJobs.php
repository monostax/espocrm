<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\PackEnterprise\Rebuild;

use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\Entities\ScheduledJob;
use Espo\ORM\EntityManager;

/**
 * Deactivates deprecated scheduled jobs that have been replaced by custom module jobs.
 *
 * - SynchronizeEventsWithGoogleCalendar: replaced by SyncMsxGoogleCalendar (PackEnterprise).
 */
class CleanupDeprecatedScheduledJobs implements RebuildAction
{
    private const DEPRECATED_JOBS = [
        'SynchronizeEventsWithGoogleCalendar',
    ];

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function process(): void
    {
        foreach (self::DEPRECATED_JOBS as $jobName) {
            $existing = $this->entityManager
                ->getRDBRepository(ScheduledJob::ENTITY_TYPE)
                ->where(['job' => $jobName])
                ->findOne();

            if ($existing && $existing->get('status') === ScheduledJob::STATUS_ACTIVE) {
                $existing->set('status', 'Inactive');
                $this->entityManager->saveEntity($existing, [SaveOption::SKIP_ALL => true]);

                $this->log->info("CleanupDeprecatedScheduledJobs: Deactivated '{$jobName}'.");
            }
        }
    }
}
