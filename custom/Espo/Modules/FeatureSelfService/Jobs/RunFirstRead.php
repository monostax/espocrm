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

namespace Espo\Modules\FeatureSelfService\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureSelfService\Tools\Onboarding\FirstRead;
use Throwable;

/**
 * The first read of a new workspace (FirstRead), scheduled when its onboarding opens. Reschedules itself while the
 * CRM is still receiving the imported conversations.
 */
class RunFirstRead implements Job
{
    public function __construct(
        private FirstRead $firstRead,
        private Log $log,
    ) {}

    public function run(Data $data): void
    {
        $tenantId = (string) $data->get('tenantId');

        try {
            $poll = $data->get('poll');
            $result = $this->firstRead->run($tenantId, (int) ($data->get('attempt') ?? 0), true,
                is_array($poll) ? array_values(array_map(fn ($subject) => array_values((array) $subject), $poll)) : []);
        } catch (Throwable $e) {
            // The deck analyses lazily anyway; a failed first read only means the first cards load on open.
            $this->log->error("SelfService: first read of tenant {$tenantId} failed: " . $e->getMessage());

            return;
        }

        if ($result !== null) {
            $this->log->info("SelfService: first read of tenant {$tenantId}: " . json_encode($result));
        }
    }
}
