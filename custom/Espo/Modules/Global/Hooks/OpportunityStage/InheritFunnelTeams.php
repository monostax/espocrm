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

namespace Espo\Modules\Global\Hooks\OpportunityStage;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Modules\Global\Tools\Acl\TeamsAccess;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Inherit `teams` from the parent Funnel when the stage has none of its own.
 *
 * OpportunityStage access is defined by the funnel that owns it, so its ACL
 * teams are a mirror of the funnel's rather than an independently managed set.
 * Funnel/SyncStageTeams keeps existing stages aligned when a funnel's teams
 * change; this hook fills the gap for stages saved through the ORM (duplicates,
 * imports, other services), so a row is never persisted teamless — and thus
 * never invisible.
 *
 * Scope limitation: this is a repository hook, so it runs inside
 * EntityManager::saveEntity(). Record\Service::create() validates at :670,
 * *before* saveEntity() at :683, so this hook cannot satisfy the `required`
 * check for creation through the record service (REST / relationship panel).
 * That path needs an earlyBeforeCreate record hook instead — see the note in
 * recordDefs/OpportunityStage.json.
 *
 * Runs at order 5, before the tenant derivation that reads `teams`.
 *
 * @implements BeforeSave<Entity>
 */
class InheritFunnelTeams implements BeforeSave
{
    public static int $order = 5;

    public function __construct(
        private EntityManager $entityManager,
        private TeamsAccess $teamsAccess,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof CoreEntity) {
            return;
        }

        // Only fill a gap; never override an explicit assignment.
        if ($this->teamsAccess->entityTeamIds($entity) !== []) {
            return;
        }

        $funnelId = $entity->get('funnelId');

        if (!is_string($funnelId) || trim($funnelId) === '') {
            return;
        }

        $funnel = $this->entityManager->getEntityById('Funnel', $funnelId);

        if (!$funnel) {
            return;
        }

        $funnelTeamIds = $this->teamsAccess->entityTeamIds($funnel);

        if ($funnelTeamIds === []) {
            return;
        }

        $entity->set('teamsIds', $funnelTeamIds);
    }
}
