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

namespace Espo\Modules\FeatureSelfService\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOption;

class SeedScheduledJob implements RebuildAction
{
    private const JOB = 'RunSelfServiceProvisionings';

    public function __construct(private EntityManager $entityManager) {}

    public function process(): void
    {
        if ($this->entityManager->getRDBRepository('ScheduledJob')->where(['job' => self::JOB])->findOne()) {
            return;
        }

        $this->entityManager->createEntity('ScheduledJob', [
            'name' => 'Run Self-Service Provisionings',
            'job' => self::JOB,
            'status' => 'Active',
            'scheduling' => '* * * * *',
        ], [SaveOption::SKIP_ALL => true]);
    }
}
