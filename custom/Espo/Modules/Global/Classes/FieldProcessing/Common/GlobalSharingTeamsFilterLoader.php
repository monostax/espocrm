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

namespace Espo\Modules\Global\Classes\FieldProcessing\Common;

use Espo\Core\FieldProcessing\Loader;
use Espo\Core\FieldProcessing\Loader\Params;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\ORM\Entity;
use stdClass;

/**
 * For entities with isGloballyShared, filters the teams linkMultiple
 * output to only include teams the current user belongs to.
 * Prevents globally-shared entities from leaking team names.
 *
 * @implements Loader<Entity>
 * @noinspection PhpUnused
 */
class GlobalSharingTeamsFilterLoader implements Loader
{
    public function __construct(
        private User $user,
        private Metadata $metadata,
    ) {}

    public function process(Entity $entity, Params $params): void
    {
        if ($this->user->isAdmin()) {
            return;
        }

        if (!$this->isApplicable($entity)) {
            return;
        }

        /** @var string[]|null $teamsIds */
        $teamsIds = $entity->get('teamsIds');

        if (!is_array($teamsIds) || $teamsIds === []) {
            return;
        }

        $userTeamIds = $this->user->getTeamIdList();
        $filteredIds = array_values(array_intersect($teamsIds, $userTeamIds));

        $teamsNames = $entity->get('teamsNames');
        $filteredNames = new stdClass();

        if ($teamsNames instanceof stdClass) {
            foreach ($filteredIds as $id) {
                if (isset($teamsNames->$id)) {
                    $filteredNames->$id = $teamsNames->$id;
                }
            }
        }

        $entity->set('teamsIds', $filteredIds);
        $entity->set('teamsNames', $filteredNames);
    }

    private function isApplicable(Entity $entity): bool
    {
        $entityType = $entity->getEntityType();

        $fieldDef = $this->metadata->get(['entityDefs', $entityType, 'fields', 'isGloballyShared']);

        if (!$fieldDef || ($fieldDef['type'] ?? null) !== 'bool') {
            return false;
        }

        if (!$this->metadata->get(['entityDefs', $entityType, 'links', 'teams'])) {
            return false;
        }

        return (bool) $entity->get('isGloballyShared');
    }
}
