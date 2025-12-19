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

namespace Espo\Modules\Global\Classes\AppParams;

use Espo\Core\Acl;
use Espo\Core\Utils\Metadata;
use Espo\Entities\StarSubscription;
use Espo\Entities\User;
use Espo\ORM\EntityManager;
use Espo\Tools\App\AppParam;

/**
 * AppParam that returns starred Reports for the current user.
 * This data is included in the /api/v1/App/user response under appParams.starredReports
 */
class StarredReports implements AppParam
{
    public function __construct(
        private EntityManager $entityManager,
        private User $user,
        private Acl $acl,
        private Metadata $metadata
    ) {}

    /**
     * @return array<int, array{id: string, name: string, url: string}>
     */
    public function get(): mixed
    {
        // Check if Report entity exists
        if (!$this->metadata->get(['scopes', 'Report'])) {
            return [];
        }

        // Check if user has access to Report
        if (!$this->acl->check('Report', 'read')) {
            return [];
        }

        // Get starred Report IDs for current user
        $starSubscriptions = $this->entityManager
            ->getRDBRepository(StarSubscription::ENTITY_TYPE)
            ->select(['entityId'])
            ->where([
                'userId' => $this->user->getId(),
                'entityType' => 'Report',
            ])
            ->limit(0, 20)
            ->find();

        $reportIds = [];
        foreach ($starSubscriptions as $subscription) {
            $reportIds[] = $subscription->get('entityId');
        }

        if (empty($reportIds)) {
            return [];
        }

        // Fetch Report details
        $reports = $this->entityManager
            ->getRDBRepository('Report')
            ->select(['id', 'name'])
            ->where(['id' => $reportIds])
            ->order('name')
            ->find();

        $result = [];
        foreach ($reports as $report) {
            $result[] = [
                'id' => $report->getId(),
                'name' => $report->get('name'),
                'url' => '#Report/show/' . $report->getId(),
            ];
        }

        return $result;
    }
}


