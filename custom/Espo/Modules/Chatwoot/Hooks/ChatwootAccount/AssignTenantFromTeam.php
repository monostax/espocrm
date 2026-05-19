<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Hooks\ChatwootAccount;

use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Derives ChatwootAccount.tenant from the account's teams.
 *
 * Every Tenant points at a base Team via Tenant.baseUserTeam. ChatwootAccount
 * carries the same Team via the entityTeam linkMultiple (typically a single
 * tenant-scoped team). This hook resolves tenantId from that team list when
 * the field is left empty, so callers don't have to set it explicitly and
 * existing creation paths (seeds, UI, API) keep working unchanged.
 *
 * Runs after CascadeTeamsFromAccount (order=1) and before
 * ValidateBeforeSync (order=9) so teams are already populated but the
 * platform validation has not yet fired.
 *
 * Idempotent: never overwrites an explicitly-set tenantId, never logs
 * (beyond a single warning) when ambiguity prevents resolution.
 */
class AssignTenantFromTeam
{
    public static int $order = 5;

    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    /**
     * @param Entity $entity
     * @param array<string, mixed> $options
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        if (!empty($options['silent'])) {
            return;
        }

        // Respect explicit tenant assignments.
        if ($entity->get('tenantId')) {
            return;
        }

        $teamIds = $this->resolveTeamIds($entity);
        if (empty($teamIds)) {
            return;
        }

        $tenants = $this->entityManager
            ->getRDBRepository('Tenant')
            ->where(['baseUserTeamId' => $teamIds])
            ->find();

        $tenantIds = [];
        foreach ($tenants as $tenant) {
            $tenantIds[$tenant->getId()] = true;
        }

        if (count($tenantIds) === 0) {
            return;
        }

        if (count($tenantIds) > 1) {
            $this->log->warning(
                'AssignTenantFromTeam: ChatwootAccount ' . ($entity->getId() ?? '(new)') .
                ' resolves to multiple tenants via teams ' . implode(',', $teamIds) .
                '; leaving tenant unset.'
            );
            return;
        }

        $entity->set('tenantId', array_key_first($tenantIds));
    }

    /**
     * Read the in-memory team id list, falling back to whatever is already
     * persisted for updates that didn't touch the teams field.
     *
     * @return list<string>
     */
    private function resolveTeamIds(Entity $entity): array
    {
        $ids = [];

        if (method_exists($entity, 'getLinkMultipleIdList')) {
            try {
                $ids = $entity->getLinkMultipleIdList('teams') ?: [];
            } catch (\Throwable) {
                $ids = [];
            }
        }

        if (!empty($ids)) {
            return array_values(array_unique($ids));
        }

        $teamsIds = $entity->get('teamsIds');
        if (is_array($teamsIds) && !empty($teamsIds)) {
            return array_values(array_unique($teamsIds));
        }

        // Updates that didn't mutate the teams field — re-read from DB.
        if (!$entity->isNew() && $entity->getId()) {
            $existing = $this->entityManager->getEntityById($entity->getEntityType(), $entity->getId());
            if ($existing && method_exists($existing, 'getLinkMultipleIdList')) {
                try {
                    return array_values(array_unique($existing->getLinkMultipleIdList('teams') ?: []));
                } catch (\Throwable) {
                    return [];
                }
            }
        }

        return [];
    }
}
