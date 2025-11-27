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

namespace Espo\Modules\Global\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\ORM\EntityManager;
use Espo\Core\Acl;
use Espo\Core\FieldProcessing\ListLoadProcessor;
use Espo\Core\FieldProcessing\Loader\Params as LoaderParams;
use Espo\Core\Record\SearchParamsFetcher;
use Espo\Entities\User;
use stdClass;

class Activities
{
    public function __construct(
        private EntityManager $entityManager,
        private SelectBuilderFactory $selectBuilderFactory,
        private Acl $acl,
        private ListLoadProcessor $listLoadProcessor,
        private SearchParamsFetcher $searchParamsFetcher,
        private User $user
    ) {}

    /**
     * Get all activities list (Meeting, Call, Task combined)
     */
    public function getActionAll(Request $request): stdClass
    {
        $searchParams = $this->fetchSearchParamsFromRequest($request);
        
        // Log bool filters for debugging
        $boolFilters = $searchParams->getBoolFilterList();
        error_log("Activities: Current User ID: " . $this->user->getId());
        error_log("Activities: Bool filters: " . json_encode($boolFilters));
        
        $entityTypeList = $request->getQueryParam('entityTypeList');
        
        if (!$entityTypeList) {
            $entityTypeList = ['Meeting', 'Call', 'Task'];
        } else if (is_string($entityTypeList)) {
            $entityTypeList = json_decode($entityTypeList, true) ?? [$entityTypeList];
        }
        
        $offset = $searchParams->getOffset() ?? 0;
        $maxSize = $searchParams->getMaxSize() ?? 20;
        $orderBy = $searchParams->getOrderBy() ?? 'dateStart';
        $order = $searchParams->getOrder() ?? 'desc';
        
        $allRecords = [];
        
        // Fetch more records than needed from each entity type to ensure proper sorting
        $fetchLimit = ($offset + $maxSize) * 2;
        
        // Fetch records from each entity type
        foreach ($entityTypeList as $entityType) {
            if (!$this->acl->checkScope($entityType, 'read')) {
                continue;
            }
            
            try {
                // Create search params without offset/limit for fetching
                $entitySearchParams = $searchParams
                    ->withOffset(0)
                    ->withMaxSize($fetchLimit);
                
                error_log("Activities: Processing $entityType with bool filters: " . 
                    json_encode($entitySearchParams->getBoolFilterList()));
                
                $builder = $this->selectBuilderFactory
                    ->create()
                    ->from($entityType)
                    ->withSearchParams($entitySearchParams)
                    ->withStrictAccessControl();
                
                $query = $builder->build();
                
                error_log("Activities: Query WHERE: " . json_encode($query->getWhere()));
                
                $collection = $this->entityManager
                    ->getRDBRepository($entityType)
                    ->clone($query)
                    ->find();
                
                error_log("Activities: Found " . count($collection) . " records for $entityType");
                
                $loaderParams = LoaderParams::create();
                
                foreach ($collection as $entity) {
                    // Process fields to load related data like assignedUserName
                    $this->listLoadProcessor->process($entity, $loaderParams);
                    
                    // For Meeting and Call, load participants (users and contacts)
                    if ($entityType === 'Meeting' || $entityType === 'Call') {
                        // Load users relationship
                        $users = $this->entityManager
                            ->getRDBRepository($entityType)
                            ->getRelation($entity, 'users')
                            ->select(['id', 'name'])
                            ->find();
                        
                        $usersIds = [];
                        $usersNames = (object)[];
                        foreach ($users as $user) {
                            $usersIds[] = $user->get('id');
                            $usersNames->{$user->get('id')} = $user->get('name');
                        }
                        $entity->set('usersIds', $usersIds);
                        $entity->set('usersNames', $usersNames);
                        
                        // Load contacts relationship
                        $contacts = $this->entityManager
                            ->getRDBRepository($entityType)
                            ->getRelation($entity, 'contacts')
                            ->select(['id', 'name'])
                            ->find();
                        
                        $contactsIds = [];
                        $contactsNames = (object)[];
                        foreach ($contacts as $contact) {
                            $contactsIds[] = $contact->get('id');
                            $contactsNames->{$contact->get('id')} = $contact->get('name');
                        }
                        $entity->set('contactsIds', $contactsIds);
                        $entity->set('contactsNames', $contactsNames);
                    }
                    
                    $record = $entity->getValueMap();
                    $record->_scope = $entityType;
                    $allRecords[] = $record;
                    
                    error_log("Activities: $entityType - " . $entity->get('name') . 
                        " (assigned to: " . ($entity->get('assignedUserId') ?? 'none') . ")");
                }
            } catch (\Exception $e) {
                // Log the error and skip this entity type
                error_log("Activities: ERROR for $entityType: " . $e->getMessage());
                error_log("Activities: Stack trace: " . $e->getTraceAsString());
                continue;
            }
        }
        
        // Sort all records based on the requested order
        usort($allRecords, function ($a, $b) use ($orderBy, $order) {
            $aVal = $a->$orderBy ?? $a->dateStart ?? $a->dateEnd ?? $a->createdAt ?? '';
            $bVal = $b->$orderBy ?? $b->dateStart ?? $b->dateEnd ?? $b->createdAt ?? '';
            
            $result = strcmp($aVal, $bVal);
            
            return $order === 'desc' ? -$result : $result;
        });
        
        // Apply pagination
        $total = count($allRecords);
        $list = array_slice($allRecords, $offset, $maxSize);
        
        return (object) [
            'total' => $total,
            'list' => $list,
        ];
    }

    /**
     * @throws BadRequest
     * @throws Forbidden
     */
    private function fetchSearchParamsFromRequest(Request $request): SearchParams
    {
        return $this->searchParamsFetcher->fetch($request);
    }
}

