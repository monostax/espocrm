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

namespace Espo\Modules\FeatureSelfService\Tools\Workspace;

use Espo\Modules\FeatureSelfService\Tools\Provisioning\Preview\PreviewCarry;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\Provisioner;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * The monostax.ai site preview a workspace started from, as its provisioning stored it. The onboarding reads its
 * import lines from here: what the visitor saw about their own business keeps showing while the history comes in.
 */
class WorkspacePreview
{
    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function carry(Entity $tenant): ?PreviewCarry
    {
        $record = $this->entityManager
            ->getRDBRepository(Provisioner::ENTITY_TYPE)
            ->where(['tenantId' => $tenant->getId(), 'previewCarry!=' => null])
            ->order('createdAt', 'DESC')
            ->findOne();

        return PreviewCarry::fromStored($record?->get('previewCarry'));
    }
}
