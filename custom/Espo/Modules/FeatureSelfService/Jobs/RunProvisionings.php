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

use Espo\Core\Job\JobDataLess;
use Espo\Modules\FeatureSelfService\Tools\Policy\SelfServicePolicy;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\Provisioner;

/**
 * Every minute: run due provisionings, retry failed ones on their backoff,
 * re-check paused ones and take over those whose worker died. Does nothing
 * while self-service is off; paused records then keep their progress.
 */
class RunProvisionings implements JobDataLess
{
    private const BATCH = 10;

    public function __construct(
        private SelfServicePolicy $policy,
        private Provisioner $provisioner,
    ) {}

    public function run(): void
    {
        if (!$this->policy->isEnabled()) {
            return;
        }

        foreach ($this->provisioner->findDueIds(self::BATCH) as $id) {
            $this->provisioner->run($id);
        }
    }
}
