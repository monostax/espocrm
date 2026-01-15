<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Rebuild;

use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\Entities\ScheduledJob;
use Espo\ORM\EntityManager;

/**
 * Rebuild action to seed Chatwoot scheduled jobs.
 * Creates the scheduled jobs if they don't exist.
 * Runs automatically during system rebuild.
 */
class SeedScheduledJobs implements RebuildAction
{
    private const JOBS = [
        [
            'name' => 'Sync Inboxes from Chatwoot',
            'job' => 'SyncInboxesFromChatwoot',
            'scheduling' => '* * * * *',
        ],
        [
            'name' => 'Sync Contacts from Chatwoot',
            'job' => 'SyncContactsFromChatwoot',
            'scheduling' => '* * * * *',
        ],
        [
            'name' => 'Sync Conversations from Chatwoot',
            'job' => 'SyncConversationsFromChatwoot',
            'scheduling' => '* * * * *',
        ],
        [
            'name' => 'Auto-Pending Conversations',
            'job' => 'AutoPendingConversations',
            'scheduling' => '* * * * *',
        ],
    ];

    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    public function process(): void
    {
        foreach (self::JOBS as $jobData) {
            $this->upsertJob($jobData);
        }
    }

    private function upsertJob(array $jobData): void
    {
        $existing = $this->entityManager
            ->getRDBRepository(ScheduledJob::ENTITY_TYPE)
            ->where(['job' => $jobData['job']])
            ->findOne();

        if ($existing) {
            $existing->set('name', $jobData['name']);
            $existing->set('scheduling', $jobData['scheduling']);
            $existing->set('status', ScheduledJob::STATUS_ACTIVE);

            $this->entityManager->saveEntity($existing, [SaveOption::SKIP_ALL => true]);

            $this->log->info("SeedScheduledJobs: Updated scheduled job '{$jobData['job']}'");

            return;
        }

        $this->entityManager->createEntity(ScheduledJob::ENTITY_TYPE, [
            'name' => $jobData['name'],
            'job' => $jobData['job'],
            'status' => ScheduledJob::STATUS_ACTIVE,
            'scheduling' => $jobData['scheduling'],
        ], [SaveOption::SKIP_ALL => true]);

        $this->log->info("SeedScheduledJobs: Created scheduled job '{$jobData['job']}'");
    }
}
