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

namespace Espo\Modules\Global\Hooks\Opportunity;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Validates that the OpportunityStage belongs to the selected Funnel
 * before saving an Opportunity.
 *
 * @implements BeforeSave<Entity>
 */
class ValidateStageFunnel implements BeforeSave
{
    public static int $order = 10;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    /**
     * Validate that the stage belongs to the funnel.
     *
     * @throws BadRequest
     */
    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $funnelId = $entity->get('funnelId');
        $stageId = $entity->get('opportunityStageId');

        // Skip validation if either is not set (let required validation handle it)
        if (!$funnelId || !$stageId) {
            return;
        }

        // Only validate if funnel or stage has changed
        if (!$entity->isNew() && !$entity->isAttributeChanged('funnelId') && !$entity->isAttributeChanged('opportunityStageId')) {
            return;
        }

        // Get the stage to check its funnel
        $stage = $this->entityManager->getEntityById('OpportunityStage', $stageId);

        if (!$stage) {
            throw new BadRequest('The selected stage does not exist.');
        }

        $stageFunnelId = $stage->get('funnelId');

        if ($stageFunnelId !== $funnelId) {
            throw new BadRequest('The selected stage does not belong to the selected funnel.');
        }
    }
}
