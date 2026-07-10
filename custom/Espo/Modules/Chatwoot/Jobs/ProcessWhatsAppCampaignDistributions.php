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
use Espo\Modules\Chatwoot\Services\WhatsAppCampaignDistributionService;
use Espo\ORM\EntityManager;

/**
 * Scheduled sweep for Active WhatsApp campaign distributions.
 *
 * For continuous distributions, re-resolves the audience and enrolls
 * not-yet-enrolled contacts into entry campaigns by deterministic weighted
 * bucket. For non-continuous distributions (one-shot split at activation),
 * auto-stops the distribution once all entry campaigns have left Sending.
 *
 * Enrollment is idempotent: contacts are deduped by contactId/phone across
 * all campaigns of a distribution, and the per-campaign unique DB index
 * (contactId, whatsAppCampaignId) guards against races.
 */
class ProcessWhatsAppCampaignDistributions implements JobDataLess
{
    public function __construct(
        private EntityManager $entityManager,
        private WhatsAppCampaignDistributionService $distributionService,
        private Log $log
    ) {}

    public function run(): void
    {
        $distributions = $this->entityManager
            ->getRDBRepository('WhatsAppCampaignDistribution')
            ->where(['status' => 'Active'])
            ->order('startedAt')
            ->find();

        foreach ($distributions as $distribution) {
            try {
                if ($distribution->get('continuous')) {
                    $this->distributionService->enrollContacts($distribution->getId());

                    continue;
                }

                $this->distributionService->autoStopIfFinished($distribution->getId());
            } catch (\Throwable $e) {
                $this->log->error(
                    "ProcessWhatsAppCampaignDistributions: Failed to process distribution " .
                    "{$distribution->getId()}: {$e->getMessage()}"
                );
            }
        }
    }
}
