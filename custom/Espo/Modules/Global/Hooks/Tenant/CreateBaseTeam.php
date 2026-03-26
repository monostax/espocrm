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

namespace Espo\Modules\Global\Hooks\Tenant;

use Espo\Core\Utils\Metadata;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Creates a base Team for a new Tenant and links it.
 */
class CreateBaseTeam
{
    public static int $order = 5;

    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        if (!$entity->isNew()) {
            return;
        }

        if ($entity->get('baseUserTeamId')) {
            return;
        }

        $tenantName = trim((string) ($entity->get('name') ?? ''));

        if ($tenantName === '') {
            return;
        }

        $roleId = $this->getTenantRoleId();

        $team = $this->entityManager->createEntity('Team', [
            'name' => $tenantName,
        ]);

        $this->entityManager
            ->getRDBRepository('Team')
            ->getRelation($team, 'roles')
            ->relateById($roleId, null, ['skipHooks' => true]);

        $entity->set('baseUserTeamId', $team->getId());
    }

    private function getTenantRoleId(): string
    {
        $toHash = $this->metadata->get(['app', 'recordId', 'type']) === 'uuid4' ||
                  $this->metadata->get(['app', 'recordId', 'dbType']) === 'uuid';

        return $toHash ? md5('tenant') : 'tenant';
    }
}
