<?php

declare(strict_types=1);

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

use Espo\Core\Utils\Log;
use Espo\Modules\Global\Tools\Tenant\TenantAdminTeamProvisioner;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Propagates a Tenant rename to its provisioned Teams:
 *
 *   base team  "{oldName}"           -> "{newName}"
 *   admin team "{oldName} / Admin"   -> "{newName} / Admin"
 *
 * Renames are CONSERVATIVE: a team is only renamed when its current name still
 * equals the name that would have been derived from the OLD tenant name. If an
 * admin has deliberately renamed a team, that choice is left alone rather than
 * being clobbered on the next tenant rename.
 *
 * The admin team is located by role (see TenantAdminTeamProvisioner), not by
 * name, so this still works when the admin team was renamed by hand — it simply
 * declines to touch it.
 *
 * Runs at $order = 8, after the provisioning hooks (5, 6) and after
 * SyncAclTeamsFromBaseUserTeam (7), so a create-and-rename in one request
 * cannot race the initial naming.
 */
class RenameTeamsOnTenantRename
{
    public static int $order = 8;

    public function __construct(
        private EntityManager $entityManager,
        private TenantAdminTeamProvisioner $adminTeamProvisioner,
        private Log $log,
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
    {
        if ($entity->isNew()) {
            return;
        }

        if (!empty($options['skipTenantTeamRename'])) {
            return;
        }

        if (!$entity->isAttributeChanged('name')) {
            return;
        }

        $newName = trim((string) ($entity->get('name') ?? ''));
        $oldName = trim((string) ($entity->getFetched('name') ?? ''));

        if ($newName === '' || $oldName === '' || $newName === $oldName) {
            return;
        }

        $baseTeamId = $entity->get('baseUserTeamId');

        if (is_string($baseTeamId) && $baseTeamId !== '') {
            $this->renameIfUnchanged($baseTeamId, $oldName, $newName, 'base');
        }

        $adminTeamId = $this->adminTeamProvisioner->findAdminTeamId($entity);

        if ($adminTeamId !== null) {
            $this->renameIfUnchanged(
                $adminTeamId,
                $this->adminTeamProvisioner->adminTeamName($oldName),
                $this->adminTeamProvisioner->adminTeamName($newName),
                'admin',
            );
        }
    }

    private function renameIfUnchanged(
        string $teamId,
        string $expectedCurrentName,
        string $desiredName,
        string $kind
    ): void {
        try {
            $team = $this->entityManager->getEntityById('Team', $teamId);

            if (!$team) {
                return;
            }

            $currentName = trim((string) ($team->get('name') ?? ''));

            if ($currentName === $desiredName) {
                return;
            }

            if ($currentName !== $expectedCurrentName) {
                $this->log->info(sprintf(
                    'Global Module: leaving %s team %s named "%s" — it no longer matches the '
                    . 'derived name "%s", so it looks intentionally customised.',
                    $kind,
                    $teamId,
                    $currentName,
                    $expectedCurrentName,
                ));

                return;
            }

            $team->set('name', $desiredName);

            $this->entityManager->saveEntity($team, ['skipHooks' => true, 'silent' => true]);

            $this->log->info(sprintf(
                'Global Module: renamed %s team %s from "%s" to "%s".',
                $kind,
                $teamId,
                $currentName,
                $desiredName,
            ));
        } catch (Throwable $e) {
            // A rename is cosmetic; never fail the Tenant save over it.
            $this->log->error(sprintf(
                'Global Module: failed to rename %s team %s: %s',
                $kind,
                $teamId,
                $e->getMessage(),
            ));
        }
    }
}
