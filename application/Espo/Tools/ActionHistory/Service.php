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
use Espo\Core\FieldProcessing\ListLoadProcessor;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Metadata;
use Espo\Core\Utils\Util;
use Espo\Entities\User;

class Service
{
    public function __construct(
        private Metadata $metadata,
        private EntityManager $entityManager,
        private User $user,
        private ListLoadProcessor $listLoadProcessor
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

        $collection = $this->entityManager->getCollectionFactory()->create(
            ActionHistoryRecord::ENTITY_TYPE,
            array_slice(array_values($latest), $offset, $maxSize + 1)
        );

        foreach ($collection as $entity) {
            $this->listLoadProcessor->process($entity);

            $entity->set('id', Util::generateId());
        }

        return RecordCollection::createNoCount($collection,  $maxSize);
    }
}
