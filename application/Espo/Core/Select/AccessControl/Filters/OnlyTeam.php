<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
 * Copyright (C) 2014-2026 EspoCRM, Inc.
 * Website: https://www.espocrm.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Core\Select\AccessControl\Filters;

use Espo\Core\Name\Field;
use Espo\Core\Select\AccessControl\Filter;
use Espo\Core\Select\Helpers\FieldHelper;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\ORM\Defs;
use Espo\ORM\Name\Attribute;
use Espo\ORM\Query\SelectBuilder;

/**
 * @noinspection PhpUnused
 */
class OnlyTeam implements Filter
{
    public function __construct(
        private User $user,
        private FieldHelper $fieldHelper,
        private string $entityType,
        private Defs $defs
    ) {}

    public function apply(SelectBuilder $queryBuilder): void
    {
        if (!$this->fieldHelper->hasTeamsField()) {
            $queryBuilder->where([Attribute::ID => null]);

            return;
        }

        // Empty list is converted to false statement by ORM.
        $teamSubQuery = SelectBuilder::create()
            ->select('entityId')
            ->from(Team::RELATIONSHIP_ENTITY_TEAM)
            ->where([
                'teamId' => $this->user->getTeamIdList(),
                'entityType' => $this->entityType,
                'deleted' => false,
            ])
            ->build();

        $orGroup = [
            [Attribute::ID . '=s' => $teamSubQuery],
        ];

        if ($this->fieldHelper->hasAssignedUsersField()) {
            $relationDefs = $this->defs
                ->getEntity($this->entityType)
                ->getRelation(Field::ASSIGNED_USERS);

            $middleEntityType = ucfirst($relationDefs->getRelationshipName());
            $key1 = $relationDefs->getMidKey();
            $key2 = $relationDefs->getForeignMidKey();

            $assignedUsersSubQuery = SelectBuilder::create()
                ->select($key1)
                ->from($middleEntityType)
                ->where([
                    $key2 => $this->user->getId(),
                    'deleted' => false,
                ])
                ->build();

            $orGroup[] = [Attribute::ID . '=s' => $assignedUsersSubQuery];
        } else if ($this->fieldHelper->hasAssignedUserField()) {
            $orGroup[] = ['assignedUserId' => $this->user->getId()];
        } else if ($this->fieldHelper->hasCreatedByField()) {
            $orGroup[] = ['createdById' => $this->user->getId()];
        }

        if ($this->fieldHelper->hasCollaboratorsField()) {
            $relationDefs = $this->defs
                ->getEntity($this->entityType)
                ->getRelation(Field::COLLABORATORS);

            $middleEntityType = ucfirst($relationDefs->getRelationshipName());
            $key1 = $relationDefs->getMidKey();
            $key2 = $relationDefs->getForeignMidKey();

            $collaboratorsSubQuery = SelectBuilder::create()
                ->select($key1)
                ->from($middleEntityType)
                ->where([
                    $key2 => $this->user->getId(),
                    'deleted' => false,
                ])
                ->build();

            $orGroup[] = [Attribute::ID . '=s' => $collaboratorsSubQuery];
        }

        $queryBuilder->where(['OR' => $orGroup]);
    }
}
