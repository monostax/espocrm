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

namespace Espo\Modules\Global\Hooks\OpportunityStage;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Utils\Metadata;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Keeps opportunities aligned when an OpportunityStage definition changes.
 */
class SyncRelatedOpportunities implements AfterSave
{
    public static int $order = 20;

    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (
            !$entity->isNew() &&
            !$entity->isAttributeChanged('probability') &&
            !$entity->isAttributeChanged('name')
        ) {
            return;
        }

        $opportunityList = $this->entityManager
            ->getRDBRepository('Opportunity')
            ->where([
                'opportunityStageId' => $entity->getId(),
                'deleted' => false,
            ])
            ->find();

        $probability = (int) $entity->get('probability');
        $opportunityStageName = $entity->get('name');

        foreach ($opportunityList as $opportunity) {
            $opportunity->set('probability', $probability);
            $opportunity->set('opportunityStageName', $opportunityStageName);

            if ($probability === 100) {
                $opportunity->set('status', 'Won');
                $opportunity->set('stage', 'Closed Won');
            } elseif ($probability === 0) {
                $opportunity->set('status', 'Lost');
                $opportunity->set('stage', 'Closed Lost');
            } else {
                $opportunity->set('status', 'Open');

                $legacyOpenStage = $this->mapProbabilityToLegacyOpenStage($probability);

                if ($legacyOpenStage) {
                    $opportunity->set('stage', $legacyOpenStage);
                }
            }

            $this->entityManager->saveEntity($opportunity);
        }
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
