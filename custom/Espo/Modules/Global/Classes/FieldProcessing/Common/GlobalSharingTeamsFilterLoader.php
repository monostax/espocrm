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
use Espo\Core\Record\Output\Filter;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\ORM\Entity;
use stdClass;

/**
 * For entities with isGloballyShared, filters the teams linkMultiple
 * output to only include teams the current user belongs to.
 * Prevents globally-shared entities from leaking team names.
 *
 * Registered as an output filter (read, list, related and save responses)
 * and a list loader (export and reports skip output filters). Never as a read
 * loader: Record\Service::update() runs read loaders on the entity it saves,
 * so a filtered `teamsIds` there would unlink every team the editor is not in.
 *
 * @implements Loader<Entity>
 * @implements Filter<Entity>
 * @noinspection PhpUnused
 */
class GlobalSharingTeamsFilterLoader implements Loader, Filter
{
    public function __construct(
        private User $user,
        private Metadata $metadata,
    ) {}

    public function process(Entity $entity, Params $params): void
    {
        $this->filter($entity);
    }

    public function filter(Entity $entity): void
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
