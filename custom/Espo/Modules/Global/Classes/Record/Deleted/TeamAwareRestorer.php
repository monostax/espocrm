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

namespace Espo\Modules\Global\Classes\Record\Deleted;

use Espo\Core\Record\Deleted\DefaultRestorer;
use Espo\Core\Record\Deleted\Restorer;
use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\UpdateBuilder;

/**
 * Restores a soft-deleted entity and re-attaches its team-scoped ACL links
 * that were soft-deleted by {@see \Espo\Modules\Global\Hooks\Common\CascadeDelete}.
 *
 * Restoration is a single UPDATE on the EntityTeam middle table flipping
 * `deleted=1 -> deleted=0` for every row matching the entity's
 * `(entityType, entityId)` pair. This is idempotent: entities without team
 * scoping match zero rows and the UPDATE is a no-op.
 *
 * Caveat (Shape A): EntityTeam has no `deleteId` column, so this restorer
 * cannot distinguish rows soft-deleted by the cascade from rows the user
 * manually unrelated before the cascade. In practice this is acceptable
 * because manual unrelate-then-delete-then-restore is rare in normal CRM
 * workflow and the re-attached team only grants access to its existing
 * members. If/when this becomes a problem, add a `deleteId` column to
 * EntityTeam via the LinkConverter and filter by it here.
 *
 * @implements Restorer<Entity>
 */
class TeamAwareRestorer implements Restorer
{
    public function __construct(
        private DefaultRestorer $defaultRestorer,
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    /**
     * @inheritDoc
     */
    public function restore(Entity $entity): void
    {
        $this->defaultRestorer->restore($entity);

        $this->restoreEntityTeamLinks($entity);
    }

    private function restoreEntityTeamLinks(Entity $entity): void
    {
        $entityType = $entity->getEntityType();
        $entityId = $entity->getId();

        try {
            $query = UpdateBuilder::create()
                ->in('EntityTeam')
                ->where([
                    'entityType' => $entityType,
                    'entityId' => $entityId,
                    'deleted' => true,
                ])
                ->set(['deleted' => false])
                ->build();

            $this->entityManager->getQueryExecutor()->execute($query);
        } catch (\Exception $e) {
            $this->log->error(
                "TeamAwareRestorer: Could not restore EntityTeam rows for {$entityType} {$entityId}: " .
                $e->getMessage()
            );
        }
    }
}
