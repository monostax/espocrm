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

namespace Espo\Modules\FeatureSelfService\Classes\Record\Deleted;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Record\Deleted\DefaultRestorer;
use Espo\Core\Record\Deleted\Restorer;
use Espo\Modules\FeatureSelfService\Tools\Offboarding\Offboarder;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * An offboarded tenant can't be restored: its Chatwoot accounts, WhatsApp
 * sessions, users and teams are gone, so the record would come back empty.
 * Tenants removed before offboarding existed restore as before.
 *
 * @implements Restorer<Entity>
 */
class TenantRestorer implements Restorer
{
    public function __construct(
        private DefaultRestorer $defaultRestorer,
        private EntityManager $entityManager,
    ) {}

    public function restore(Entity $entity): void
    {
        $offboarded = $this->entityManager
            ->getRDBRepository(Offboarder::ENTITY_TYPE)
            ->where(['tenantId' => $entity->getId()])
            ->findOne();

        if ($offboarded) {
            throw new Conflict('This workspace was offboarded and cannot be restored.');
        }

        $this->defaultRestorer->restore($entity);
    }
}
