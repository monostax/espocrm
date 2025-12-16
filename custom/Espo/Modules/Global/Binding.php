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
    }
}

