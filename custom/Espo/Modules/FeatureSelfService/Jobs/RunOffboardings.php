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

declare(strict_types=1);

namespace Espo\Modules\FeatureSelfService\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Modules\FeatureSelfService\Tools\Offboarding\Offboarder;

/**
 * Every minute: continue due offboardings, retry failed ones on their
 * backoff and take over those whose worker died. Runs whatever the
 * self-service switch says: it governs new workspaces, not removing them.
 */
class RunOffboardings implements JobDataLess
{
    private const BATCH = 3;

    public function __construct(private Offboarder $offboarder) {}

    public function run(): void
    {
        foreach ($this->offboarder->findDueIds(self::BATCH) as $id) {
            $this->offboarder->run($id);
        }
    }
}
