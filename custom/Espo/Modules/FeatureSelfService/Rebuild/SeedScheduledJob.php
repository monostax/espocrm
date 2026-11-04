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
    private const JOBS = [
        'RunSelfServiceProvisionings' => ['Run Self-Service Provisionings', '* * * * *'],
        'ApplySelfServiceWorkspaceAccess' => ['Apply Self-Service Workspace Access', '*/5 * * * *'],
    ];

    public function __construct(private EntityManager $entityManager) {}

    public function process(): void
    {
        foreach (self::JOBS as $job => [$name, $scheduling]) {
            if ($this->entityManager->getRDBRepository('ScheduledJob')->where(['job' => $job])->findOne()) {
                continue;
            }

            $this->entityManager->createEntity('ScheduledJob', [
                'name' => $name,
                'job' => $job,
                'status' => 'Active',
                'scheduling' => $scheduling,
            ], [SaveOption::SKIP_ALL => true]);
        }
    }
}
