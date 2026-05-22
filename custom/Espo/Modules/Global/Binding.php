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

namespace Espo\Modules\Global;

use Espo\Core\Binding\Binder;
use Espo\Core\Binding\BindingProcessor;
use Espo\Modules\Global\Tools\Kanban\CustomOrderer;
use Espo\Modules\Global\Tools\Kanban\KanbanService;
use Espo\Modules\Global\Tools\Report\Service as ReportService;
use Espo\Modules\Global\Tools\Report\TargetListSyncService as ReportTargetListSyncService;

/**
 * Binding configuration for the Global module.
 */
class Binding implements BindingProcessor
{
    public function process(Binder $binder): void
    {
        // Override the default KanbanService with our custom one that supports
        // entity-specific Kanban classes via metadata configuration.
        $binder->bindImplementation(
            \Espo\Tools\Kanban\KanbanService::class,
            KanbanService::class
        );

        // Override the default Orderer with our custom one that supports
        // OpportunityStage link field validation for Opportunity entity.
        $binder->bindImplementation(
            \Espo\Tools\Kanban\Orderer::class,
            CustomOrderer::class
        );

        // Override Advanced's Report Service so Grid / JointGrid results are
        // post-processed by `DateBucketPadder` (fills empty date buckets when
        // the report has `fillEmptyDateBuckets = true`).
        $binder->bindImplementation(
            \Espo\Modules\Advanced\Tools\Report\Service::class,
            ReportService::class
        );

        // Override Advanced's TargetListSyncService so the report → target
        // list population query is built for the running user, applying the
        // user's row-level ACL on the target entity. Without this, the
        // parent passes `$user = null` to `Service::prepareSelectBuilder`
        // and massRelate copies cross-tenant rows into the TargetList.
        $binder->bindImplementation(
            \Espo\Modules\Advanced\Tools\Report\TargetListSyncService::class,
            ReportTargetListSyncService::class
        );
    }
}


