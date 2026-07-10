<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\WhatsAppCampaignService;
use Espo\ORM\EntityManager;

/**
 * Scheduled job to enroll newly added contacts into running WhatsApp
 * campaigns with continuous enrollment enabled.
 *
 * For each campaign in Sending status with continuousEnrollment = true,
 * re-resolves the audience (target lists + manual contacts, honoring all
 * opt-out and exclusion rules) and enrolls only contacts not yet present
 * in the campaign. Enrollment is idempotent: dedupe by contactId/phone and
 * a unique DB index (contactId, whatsAppCampaignId) guard against races.
 */
class EnrollWhatsAppCampaignContacts implements JobDataLess
{
    public function __construct(
        private EntityManager $entityManager,
        private WhatsAppCampaignService $campaignService,
        private Log $log
    ) {}

    public function run(): void
    {
        $campaigns = $this->entityManager
            ->getRDBRepository('WhatsAppCampaign')
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
                        "EnrollWhatsAppCampaignContacts: Enrolled {$enrolledCount} new contacts " .
                        "into campaign {$campaign->getId()}."
                    );
                }
            } catch (\Throwable $e) {
                $this->log->error(
                    "EnrollWhatsAppCampaignContacts: Failed to enroll contacts for campaign " .
                    "{$campaign->getId()}: {$e->getMessage()}"
                );
            }
        }
    }
}
