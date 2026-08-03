<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Hooks\AutomationRunItem;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureAutomation\Entities\Automation;
use Espo\Modules\FeatureAutomation\Entities\AutomationRun;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Cascades tenantId + teamsIds onto AutomationRunItem from parent run, then
 * Automation (same grain as FeatureJourney stage/record cascade hooks).
 *
 * @implements BeforeSave<Entity>
 */
class CascadeTenantTeamsFromAutomation implements BeforeSave
{
    public static int $order = 5;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if ($options->get('silent') && $entity->get('tenantId')) {
            return;
        }

        $parent = $this->resolveParent($entity);
        if (!$parent) {
            return;
        }

        if (!$entity->get('tenantId') && $parent->get('tenantId')) {
            $entity->set('tenantId', $parent->get('tenantId'));
        }

        try {
            $teamsIds = $parent->getLinkMultipleIdList('teams') ?: [];
        } catch (\Throwable) {
            $teamsIds = $parent->get('teamsIds') ?: [];
        }

        if (!empty($teamsIds)) {
            $entity->set('teamsIds', $teamsIds);
        }
    }

    private function resolveParent(Entity $entity): ?Entity
    {
        $runId = $entity->get('runId');
        if ($runId) {
            $run = $this->entityManager->getEntityById(
                AutomationRun::ENTITY_TYPE,
                (string) $runId,
            );
            if ($run) {
                return $run;
            }
        }

        $automationId = $entity->get('automationId');
        if (!$automationId) {
            return null;
        }

        return $this->entityManager->getEntityById(
            Automation::ENTITY_TYPE,
            (string) $automationId,
        );
    }
}
