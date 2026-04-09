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

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Utils\Metadata;
use Espo\Modules\Crm\Entities\Opportunity;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Syncs Opportunity status, probability and legacy stage from OpportunityStage.
 * Bridges OpportunityStage with core EspoCRM won/lost logic.
 *
 * @implements BeforeSave<Opportunity>
 */
class SyncFromOpportunityStage implements BeforeSave
{
    public static int $order = 6; // Run before core Probability hook (7)

    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata
    ) {}

    /**
     * @param Opportunity $entity
     */
    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity->isNew() && !$entity->isAttributeChanged('opportunityStageId')) {
            return;
        }

        $stageId = $entity->get('opportunityStageId');

        if (!$stageId) {
            return;
        }

        $stage = $this->entityManager->getEntityById('OpportunityStage', $stageId);

        if (!$stage) {
            return;
        }

        // Get probability from OpportunityStage
        $probability = (int) $stage->get('probability');

        // Set probability on Opportunity
        $entity->set('probability', $probability);

        // Determine and set status based on probability
        if ($probability === 100) {
            $entity->set('status', 'Won');
            $entity->set('stage', 'Closed Won'); // Core compatibility
        } elseif ($probability === 0) {
            $entity->set('status', 'Lost');
            $entity->set('stage', 'Closed Lost'); // Core compatibility
        } else {
            $entity->set('status', 'Open');
            $legacyOpenStage = $this->mapProbabilityToLegacyOpenStage($probability);

            if ($legacyOpenStage) {
                $entity->set('stage', $legacyOpenStage);
            } else {
                $currentStage = $entity->get('stage');

                if (!$currentStage || in_array($currentStage, ['Closed Won', 'Closed Lost'], true)) {
                    $entity->set('stage', 'Prospecting');
                }
            }
        }

        // Store the stage name for display purposes
        $entity->set('stageName', $stage->get('name'));
    }

    private function mapProbabilityToLegacyOpenStage(int $probability): ?string
    {
        $stageList = $this->metadata->get('entityDefs.Opportunity.fields.stage.options') ?? [];
        $probabilityMap = $this->metadata->get('entityDefs.Opportunity.fields.stage.probabilityMap') ?? [];

        $bestStage = null;
        $bestDistance = null;
        $bestProbability = -1;

        foreach ($stageList as $stage) {
            $value = $probabilityMap[$stage] ?? null;

            if ($value === null) {
                continue;
            }

            $value = (int) $value;

            if ($value <= 0 || $value >= 100) {
                continue;
            }

            $distance = abs($value - $probability);

            if (
                $bestDistance === null ||
                $distance < $bestDistance ||
                ($distance === $bestDistance && $value > $bestProbability)
            ) {
                $bestStage = $stage;
                $bestDistance = $distance;
                $bestProbability = $value;
            }
        }

        return $bestStage;
    }
}
