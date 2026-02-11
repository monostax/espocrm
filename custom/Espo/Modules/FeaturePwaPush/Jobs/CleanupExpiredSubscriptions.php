<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\FeaturePwaPush\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\Modules\FeaturePwaPush\Services\SubscriptionService;

/**
 * Scheduled job to cleanup expired and inactive push subscriptions.
 */
class CleanupExpiredSubscriptions implements JobDataLess
{
    private const DAYS_OLD = 30;

    public function __construct(
        private SubscriptionService $subscriptionService,
        private Log $log
    ) {}

    public function run(): void
    {
        $this->log->debug('CleanupExpiredSubscriptions: Starting job');

        try {
            $count = $this->subscriptionService->cleanupInactiveSubscriptions(self::DAYS_OLD);

            if ($count > 0) {
                $this->log->info("CleanupExpiredSubscriptions: Removed {$count} inactive subscriptions");
            } else {
                $this->log->debug('CleanupExpiredSubscriptions: No inactive subscriptions to remove');
            }
        } catch (\Throwable $e) {
            $this->log->error('CleanupExpiredSubscriptions: Job failed - ' . $e->getMessage());
        }
    }
}
