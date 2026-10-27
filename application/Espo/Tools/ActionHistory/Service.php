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

namespace Espo\Tools\ActionHistory;

use Espo\Core\Name\Field;
use Espo\Core\Record\ActionHistory\Action;
use Espo\Core\Record\Collection as RecordCollection;
use Espo\Entities\ActionHistoryRecord;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Metadata;
use Espo\Core\Utils\Util;
use Espo\Entities\User;
use Espo\ORM\Name\Attribute;

class Service
{
    public function __construct(
        private Metadata $metadata,
        private EntityManager $entityManager,
        private User $user,
    ) {}

    /**
     * @return RecordCollection<ActionHistoryRecord>
     */
    public function getLastViewed(?int $maxSize, ?int $offset): RecordCollection
    {
        $scopes = $this->metadata->get('scopes');

        $targetTypeList = array_filter(
            array_keys($scopes),
            function ($item) use ($scopes) {
                return !empty($scopes[$item]['object']) || !empty($scopes[$item]['lastViewed']);
            }
        );

        $maxSize = $maxSize ?? 0;
        $offset = $offset ?? 0;
        $wanted = $offset + $maxSize + 1;

        // Walk the user's history newest-first on (userId, deleted, number) and stop once
        // enough distinct targets are seen. GROUP BY over the whole history read every
        // row the user ever viewed (50k+ for active users) on each call.
        $chunkSize = max(200, $wanted * 10);
        $latest = [];
        $before = null;

        do {
            $where = [
                'userId' => $this->user->getId(),
                'action' => [Action::READ, Action::CREATE],
                'targetType' => $targetTypeList,
            ];

            if ($before !== null) {
                $where['number<'] = $before;
            }

            $rows = $this->entityManager
                ->getRDBRepositoryByClass(ActionHistoryRecord::class)
                ->where($where)
                ->select(['targetId', 'targetType', 'number', Field::CREATED_AT])
                ->order('number', 'DESC')
                ->limit(0, $chunkSize)
                ->find();

            $count = 0;

            foreach ($rows as $row) {
                $count++;
                $before = $row->get('number');
                $key = $row->get('targetType') . ':' . $row->get('targetId');

                if (!isset($latest[$key]) && count($latest) < $wanted) {
                    $latest[$key] = $row;
                }
            }
        } while ($count === $chunkSize && count($latest) < $wanted);

        $rows = array_slice(array_values($latest), $offset, $maxSize + 1);
        $names = $this->loadTargetNames($rows);

        $collection = $this->entityManager->getCollectionFactory()->create(ActionHistoryRecord::ENTITY_TYPE, $rows);

        foreach ($collection as $entity) {
            $entity->set('targetName', $names[$entity->get('targetType')][$entity->get('targetId')] ?? null);
            $entity->set('id', Util::generateId());
        }

        return RecordCollection::createNoCount($collection,  $maxSize);
    }

    /**
     * Target names, including deleted targets, with one query per target type. The list loaders
     * ran two lookups per row (one a full-record select), which dominated this endpoint.
     *
     * @param ActionHistoryRecord[] $rows
     * @return array<string, array<string, string>>
     */
    private function loadTargetNames(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $ids[$row->get('targetType')][] = $row->get('targetId');
        }

        $names = [];

        foreach ($ids as $type => $typeIds) {
            if (
                !$this->entityManager->hasRepository($type) ||
                !$this->entityManager->getDefs()->getEntity($type)->hasAttribute(Field::NAME)
            ) {
                continue;
            }

            $query = $this->entityManager->getQueryBuilder()
                ->select([Attribute::ID, Field::NAME])
                ->from($type)
                ->withDeleted()
                ->where([Attribute::ID => array_values(array_unique($typeIds))])
                ->build();

            foreach ($this->entityManager->getQueryExecutor()->execute($query)->fetchAll() as $item) {
                if ($item[Field::NAME]) {
                    $names[$type][$item[Attribute::ID]] = $item[Field::NAME];
                }
            }
        }

        return $names;
    }
}
