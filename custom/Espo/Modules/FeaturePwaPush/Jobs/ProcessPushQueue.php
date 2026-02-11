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
use Espo\Modules\FeaturePwaPush\Services\PushNotificationService;

/**
 * Scheduled job to process pending push notifications from the queue.
 */
class ProcessPushQueue implements JobDataLess
{
    private const BATCH_SIZE = 50;

    public function __construct(
        private PushNotificationService $pushNotificationService,
        private Log $log
    ) {}

    public function run(): void
    {
        $this->log->debug('ProcessPushQueue: Starting job');

        try {
            $result = $this->pushNotificationService->processQueue(self::BATCH_SIZE);

            $this->log->info(sprintf(
                'ProcessPushQueue: Processed %d notifications - Sent: %d, Failed: %d',
                $result['processed'],
                $result['sent'],
                $result['failed']
            ));
        } catch (\Throwable $e) {
            $this->log->error('ProcessPushQueue: Job failed - ' . $e->getMessage());
        }
    }
}
