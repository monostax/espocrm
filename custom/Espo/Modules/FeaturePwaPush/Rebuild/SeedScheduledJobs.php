<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\FeaturePwaPush\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\ORM\EntityManager;

/**
 * Rebuild action to ensure scheduled jobs are created.
 */
class SeedScheduledJobs implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private ConfigWriter $configWriter
    ) {}

    public function process(): void
    {
        $this->ensureScheduledJob(
            'ProcessPushQueue',
            'Process Push Notification Queue',
            '* * * * *'
        );

        $this->ensureScheduledJob(
            'CleanupExpiredPushSubscriptions',
            'Cleanup Expired Push Subscriptions',
            '0 3 * * *'
        );
    }

    private function ensureScheduledJob(string $jobName, string $name, string $scheduling): void
    {
        $existing = $this->entityManager
            ->getRDBRepository('ScheduledJob')
            ->where(['job' => $jobName])
            ->findOne();

        if ($existing) {
            return;
        }

        $scheduledJob = $this->entityManager->getNewEntity('ScheduledJob');
        $scheduledJob->set([
            'name' => $name,
            'job' => $jobName,
            'status' => 'Active',
            'scheduling' => $scheduling,
        ]);

        $this->entityManager->saveEntity($scheduledJob);
    }
}
