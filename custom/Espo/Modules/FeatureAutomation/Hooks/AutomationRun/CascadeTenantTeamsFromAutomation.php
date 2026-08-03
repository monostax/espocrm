<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Hooks\AutomationRun;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureAutomation\Entities\Automation;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Cascades tenantId + teamsIds from parent Automation onto AutomationRun.
 *
 * Mirrors FeatureJourney JourneyRecord/CascadeTenantTeamsFromJourney so runs
 * stay team/tenant scoped for ACL even when created outside AutomationRunner
 * (or when runner save paths omit teams).
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

        $automationId = $entity->get('automationId');
        if (!$automationId) {
            return;
        }

        $automation = $this->entityManager->getEntityById(
            Automation::ENTITY_TYPE,
            (string) $automationId,
        );
        if (!$automation) {
            return;
        }

        if (!$entity->get('tenantId') && $automation->get('tenantId')) {
            $entity->set('tenantId', $automation->get('tenantId'));
        }

        try {
            $teamsIds = $automation->getLinkMultipleIdList('teams') ?: [];
        } catch (\Throwable) {
            $teamsIds = $automation->get('teamsIds') ?: [];
        }

        if (!empty($teamsIds)) {
            $entity->set('teamsIds', $teamsIds);
        }
    }
}
