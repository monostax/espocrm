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

namespace Espo\Modules\Global\Hooks\Common;

use Espo\Core\Utils\Metadata;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * When an entity with isGloballyShared is saved, sync its teams
 * relationship to include all teams (or clear it when unset).
 *
 * Complements Team/GlobalSharing which handles the reverse direction
 * (new team created → shared with globally-shared entities).
 */
class GlobalSharing
{
    public static int $order = 20;

    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
    {
        $entityType = $entity->getEntityType();

        $fieldDef = $this->metadata->get(['entityDefs', $entityType, 'fields', 'isGloballyShared']);

        if (!$fieldDef || ($fieldDef['type'] ?? null) !== 'bool') {
            return;
        }

        if (!$this->metadata->get(['entityDefs', $entityType, 'links', 'teams'])) {
            return;
        }

        $isGloballyShared = (bool) $entity->get('isGloballyShared');
        $wasGloballyShared = (bool) $entity->getFetched('isGloballyShared');

        if ($entity->isNew() && $isGloballyShared) {
            $this->relateToAllTeams($entity, $entityType);
            return;
        }

        if (!$entity->isAttributeChanged('isGloballyShared')) {
            return;
        }

        if ($isGloballyShared && !$wasGloballyShared) {
            $this->relateToAllTeams($entity, $entityType);
        } elseif (!$isGloballyShared && $wasGloballyShared) {
            $this->unrelateFromAllTeams($entity, $entityType);
        }
    }

    private function relateToAllTeams(Entity $entity, string $entityType): void
    {
        $teams = $this->entityManager->getRDBRepository('Team')->find();
        $relation = $this->entityManager->getRDBRepository($entityType)
            ->getRelation($entity, 'teams');

        foreach ($teams as $team) {
            if (!$relation->isRelated($team)) {
                $relation->relate($team);
            }
        }
    }

    private function unrelateFromAllTeams(Entity $entity, string $entityType): void
    {
        $relation = $this->entityManager->getRDBRepository($entityType)
            ->getRelation($entity, 'teams');

        $currentTeams = $relation->find();

        foreach ($currentTeams as $team) {
            $relation->unrelate($team);
        }
    }
}
