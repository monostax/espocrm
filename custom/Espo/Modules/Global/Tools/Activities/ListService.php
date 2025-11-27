<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
 * Copyright (C) 2014-2025 EspoCRM, Inc.
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

namespace Espo\Modules\Global\Tools\Activities;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Name\Field;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Activities\List\Params;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Entity;
use Espo\ORM\Query\Select;
use Espo\Core\Acl\Table;
use Espo\Core\Record\Collection as RecordCollection;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\ORM\Query\SelectBuilder;

use PDO;

class ListService
{
    public function __construct(
        private SelectBuilderFactory $selectBuilderFactory,
        private Config $config,
        private Metadata $metadata,
        private Acl $acl,
        private EntityManager $entityManager,
        private ServiceContainer $serviceContainer,
        private User $user,
    ) {}

    /**
     * Get all activities list.
     *
     * @return RecordCollection<Entity>
     * @throws Forbidden
     */
    public function get(Params $params): RecordCollection
    {
        $entityTypeList = $params->entityTypeList ?? $this->config->get('activitiesEntityList', []);
        $orderBy = $params->orderBy ?? 'dateStart';
        $order = $params->order ?? 'desc';

        $queryList = [];

        foreach ($entityTypeList as $entityType) {
            if (
                !$this->metadata->get(['scopes', $entityType, 'activity']) &&
                $entityType !== 'Task'
            ) {
                continue;
            }

            if (!$this->acl->checkScope($entityType, Table::ACTION_READ)) {
                continue;
            }

            $queryList[] = $this->getEntityTypeQuery($entityType, $orderBy);
        }

        if ($queryList === []) {
            return RecordCollection::create(new EntityCollection(), 0);
        }

        $builder = $this->entityManager
            ->getQueryBuilder()
            ->union();

        foreach ($queryList as $query) {
            $builder->query($query);
        }

        $unionCountQuery = $builder->build();

        $countQuery = $this->entityManager->getQueryBuilder()
            ->select()
            ->fromQuery($unionCountQuery, 'c')
            ->select('COUNT:(c.id)', 'count')
            ->build();

        $countSth = $this->entityManager->getQueryExecutor()->execute($countQuery);

        $row = $countSth->fetch(PDO::FETCH_ASSOC);

        $totalCount = $row['count'];

        $offset = $params->offset ?? 0;
        $maxSize = $params->maxSize ?? 20;

        // Build union query with ordering
        $orderField = $orderBy;
        
        // Map common field names
        if ($orderBy === 'dateStart' || $orderBy === 'dateEnd') {
            $orderField = 'orderField';
        }

        $unionQuery = $builder
            ->order($orderField, $order)
            ->order('name')
            ->limit($offset, $maxSize)
            ->build();

        $sth = $this->entityManager->getQueryExecutor()->execute($unionQuery);

        $rows = $sth->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $collection = new EntityCollection();

        foreach ($rows as $row) {
            /** @var string $itemEntityType */
            $itemEntityType = $row['entityType'];
            /** @var string $itemId */
            $itemId = $row['id'];

            $entity = $this->entityManager->getEntityById($itemEntityType, $itemId);

            if (!$entity) {
                $entity = $this->entityManager->getNewEntity($itemEntityType);
                $entity->set('id', $itemId);
            }

            if (
                $entity instanceof CoreEntity &&
                $entity->hasLinkParentField(Field::PARENT)
            ) {
                $entity->loadParentNameField(Field::PARENT);
            }

            $this->serviceContainer->get($itemEntityType)->prepareEntityForOutput($entity);

            $collection->append($entity);
        }

        /** @var RecordCollection<Entity> */
        return RecordCollection::create($collection, $totalCount);
    }

    /**
     * @throws Forbidden
     */
    private function getEntityTypeQuery(string $entityType, string $orderBy): Select
    {
        $builder = $this->selectBuilderFactory
            ->create()
            ->from($entityType)
            ->forUser($this->user)
            ->withStrictAccessControl();

        $queryBuilder = $builder->buildQueryBuilder();

        // Determine the order field based on entity type
        $orderField = $orderBy;
        
        // For Task, use dateEnd if ordering by dateStart
        if ($entityType === 'Task' && $orderBy === 'dateStart') {
            $orderField = 'dateEnd';
        }

        $queryBuilder->select([
            'id',
            'name',
            'dateStart',
            'dateEnd',
            ['"' . $entityType . '"', 'entityType'],
            [$orderField, 'orderField'],
        ]);

        return $queryBuilder->build();
    }
}

