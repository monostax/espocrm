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
 * Also disables deprecated/removed jobs to prevent class-not-found crashes.
 * Runs automatically during system rebuild.
 */
class SeedScheduledJobs implements RebuildAction
{
    /**
     * Deprecated job names that should be disabled on rebuild.
     *
     * When jobs are consolidated or removed, add their `job` value here
     * so the scheduler doesn't keep trying to instantiate deleted classes.
     */
    private const DEPRECATED_JOBS = [
        'SyncAgentsFromChatwoot',           // Replaced by SyncAccountUserMembershipsFromChatwoot (Apr 2026)
        'SyncAccountMembersFromChatwoot',   // Replaced by SyncAccountUserMembershipsFromChatwoot (Apr 2026)
    ];

    private const JOBS = [
        [
            'name' => 'Post Overdue Opportunity Activities',
            'job' => 'PostOverdueOpportunityActivities',
            'scheduling' => '* * * * *',
        ],
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
        [
            'name' => 'Sync Account User Memberships from Chatwoot',
            'job' => 'SyncAccountUserMembershipsFromChatwoot',
            'scheduling' => '* * * * *',
        ],
        [
            'name' => 'Sync Inbox Members from Chatwoot',
            'job' => 'SyncInboxMembersFromChatwoot',
            'scheduling' => '*/5 * * * *',
        ],
        [
            'name' => 'Sync Teams from Chatwoot',
            'job' => 'SyncTeamsFromChatwoot',
            'scheduling' => '* * * * *',
        ],
        [
            'name' => 'Sync Labels from Chatwoot',
            'job' => 'SyncLabelsFromChatwoot',
            'scheduling' => '* * * * *',
        ],
        [
            'name' => 'Repair Account User Membership Invariants',
            'job' => 'RepairAccountUserMembershipInvariants',
            'scheduling' => '*/30 * * * *',
        ],
        [
            'name' => 'Enroll New WhatsApp Campaign Contacts',
            'job' => 'EnrollWhatsAppCampaignContacts',
            'scheduling' => '* * * * *',
        ],
        [
            'name' => 'Process WhatsApp Campaign Distributions',
            'job' => 'ProcessWhatsAppCampaignDistributions',
            'scheduling' => '* * * * *',
        ],
    ];

    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    public function process(): void
    {
        $this->disableDeprecatedJobs();

        foreach (self::JOBS as $jobData) {
            $this->upsertJob($jobData);
        }
    }

    /**
     * Disable (and log) any scheduled jobs that have been removed from the codebase.
     *
     * This prevents the EspoCRM scheduler from repeatedly failing with
     * "Class does not exist" errors after a deployment removes a job class.
     */
    private function disableDeprecatedJobs(): void
    {
        foreach (self::DEPRECATED_JOBS as $jobName) {
            $existing = $this->entityManager
                ->getRDBRepository(ScheduledJob::ENTITY_TYPE)
                ->where(['job' => $jobName])
                ->findOne();

            if (!$existing) {
                continue;
            }

            if ($existing->get('status') === ScheduledJob::STATUS_ACTIVE) {
                $existing->set('status', 'Inactive');
                $this->entityManager->saveEntity($existing, [SaveOption::SKIP_ALL => true]);

                $this->log->warning(
                    "SeedScheduledJobs: Disabled deprecated scheduled job '{$jobName}' " .
                    "(class removed from codebase)"
                );
            }
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
