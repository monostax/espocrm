<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2026 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureEmailCampaign\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureEmailCampaign\Services\EmailCampaignOpportunityService;
use Espo\ORM\EntityManager;

/**
 * Recover stuck Processing rows and retry failed Opportunity attribution.
 */
class RepairEmailCampaignRecipients implements JobDataLess
{
    private const STALE_MINUTES = 15;

    public function __construct(
        private EntityManager $entityManager,
        private EmailCampaignOpportunityService $opportunityService,
        private Log $log,
    ) {}

    public function run(): void
    {
        $cutoff = date('Y-m-d H:i:s', time() - self::STALE_MINUTES * 60);

        $stale = $this->entityManager
            ->getRDBRepository('EmailCampaignContact')
            ->where([
                'status' => 'Processing',
                'claimedAt<' => $cutoff,
            ])
            ->find();

        foreach ($stale as $row) {
            $row->set([
                'status' => 'Failed',
                'failedAt' => date('Y-m-d H:i:s'),
                'failedReason' => 'Stuck in Processing; recovered by repair job (not resent).',
                'opportunityAttributionStatus' => 'NotRequested',
            ]);
            $this->entityManager->saveEntity($row);

            $campaignId = $row->get('emailCampaignId');

            if ($campaignId) {
                $campaign = $this->entityManager->getEntityById('EmailCampaign', $campaignId);

                if ($campaign) {
                    $campaign->set('failedCount', (int) $campaign->get('failedCount') + 1);
                    $this->entityManager->saveEntity($campaign);
                }
            }

            $this->log->warning(
                "RepairEmailCampaignRecipients: Marked stuck recipient {$row->getId()} as Failed."
            );
        }

        $pendingAttr = $this->entityManager
            ->getRDBRepository('EmailCampaignContact')
            ->where([
                'status' => 'Sent',
                'opportunityAttributionStatus' => ['Pending', 'Failed'],
            ])
            ->limit(0, 100)
            ->find();

        foreach ($pendingAttr as $row) {
            try {
                $this->opportunityService->attributeSuccessfulSend($row);
            } catch (\Throwable $e) {
                $this->log->error(
                    "RepairEmailCampaignRecipients: Attribution retry failed for {$row->getId()}: " .
                    $e->getMessage()
                );
            }
        }

        $this->rebuildCounters();
    }

    private function rebuildCounters(): void
    {
        $campaigns = $this->entityManager
            ->getRDBRepository('EmailCampaign')
            ->where(['status' => ['Sending', 'Completed', 'Cancelled']])
            ->find();

        foreach ($campaigns as $campaign) {
            $campaignId = $campaign->getId();

            $sent = $this->entityManager
                ->getRDBRepository('EmailCampaignContact')
                ->where(['emailCampaignId' => $campaignId, 'status' => 'Sent'])
                ->count();

            $failed = $this->entityManager
                ->getRDBRepository('EmailCampaignContact')
                ->where(['emailCampaignId' => $campaignId, 'status' => 'Failed'])
                ->count();

            $bounced = $this->entityManager
                ->getRDBRepository('EmailCampaignContact')
                ->where(['emailCampaignId' => $campaignId, 'status' => 'Bounced'])
                ->count();

            $optedOut = $this->entityManager
                ->getRDBRepository('EmailCampaignContact')
                ->where(['emailCampaignId' => $campaignId, 'status' => 'OptedOut'])
                ->count();

            $total = $this->entityManager
                ->getRDBRepository('EmailCampaignContact')
                ->where(['emailCampaignId' => $campaignId])
                ->count();

            $campaign->set([
                'sentCount' => $sent,
                'failedCount' => $failed,
                'bouncedCount' => $bounced,
                'optedOutCount' => $optedOut,
                'totalRecipients' => $total,
            ]);
            $this->entityManager->saveEntity($campaign);
        }
    }
}
