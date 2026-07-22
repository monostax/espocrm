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
use Espo\Modules\FeatureEmailCampaign\Services\EmailCampaignService;
use Espo\ORM\EntityManager;

/**
 * Continuous enrollment sweep for EmailCampaign (Sending + continuousEnrollment).
 */
class EnrollEmailCampaignContacts implements JobDataLess
{
    public function __construct(
        private EntityManager $entityManager,
        private EmailCampaignService $campaignService,
        private Log $log
    ) {}

    public function run(): void
    {
        $campaigns = $this->entityManager
            ->getRDBRepository('EmailCampaign')
            ->where([
                'status' => 'Sending',
                'continuousEnrollment' => true,
            ])
            ->find();

        foreach ($campaigns as $campaign) {
            try {
                $enrolledCount = $this->campaignService->enrollNewContacts($campaign->getId());

                if ($enrolledCount > 0) {
                    $this->log->info(
                        "EnrollEmailCampaignContacts: Enrolled {$enrolledCount} new contacts " .
                        "into campaign {$campaign->getId()}."
                    );
                }
            } catch (\Throwable $e) {
                $this->log->error(
                    "EnrollEmailCampaignContacts: Failed for campaign " .
                    "{$campaign->getId()}: {$e->getMessage()}"
                );
            }
        }
    }
}
