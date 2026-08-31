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

namespace Espo\Modules\Global\Hooks\Funnel;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Modules\Global\Tools\Acl\TeamsAccess;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Propagate a Funnel's `teams` down to its OpportunityStages.
 *
 * OpportunityStage ACL is a mirror of its funnel's teams (see
 * OpportunityStage/InheritFunnelTeams). Without this, re-teaming a funnel
 * would silently leave its stages — and therefore the pipeline UI — visible
 * to the old teams and invisible to the new ones.
 *
 * Runs at order 20, after the tenant derivation at order 5.
 *
 * @implements AfterSave<Entity>
 */
class SyncStageTeams implements AfterSave
{
    public static int $order = 20;

    public function __construct(
        private EntityManager $entityManager,
        private TeamsAccess $teamsAccess,
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof CoreEntity) {
            return;
        }

        // `teamsIds` is not a stored column, so isAttributeChanged() is not
        // reliable for link-multiple fields; reconcile on every save instead
        // and skip the write when a stage already matches.
        $funnelTeamIds = $this->teamsAccess->entityTeamIds($entity);

        if ($funnelTeamIds === []) {
            return;
        }

        $stages = $this->entityManager
            ->getRDBRepository('OpportunityStage')
            ->where([
                'funnelId' => $entity->getId(),
                'deleted' => false,
            ])
            ->find();

        foreach ($stages as $stage) {
            $current = $this->teamsAccess->entityTeamIds($stage);

            if ($this->sameSet($current, $funnelTeamIds)) {
                continue;
            }

            $stage->set('teamsIds', $funnelTeamIds);

            // skipHooks: this is an ACL mirror, not a stage definition change;
            // it must not re-trigger SyncRelatedOpportunities.
            $this->entityManager->saveEntity($stage, ['skipHooks' => true]);
        }
    }

    /**
     * @param string[] $a
     * @param string[] $b
     */
    private function sameSet(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }

        sort($a);
        sort($b);

        return $a === $b;
    }
}
