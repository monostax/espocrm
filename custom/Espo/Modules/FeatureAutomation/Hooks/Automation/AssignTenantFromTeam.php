<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Hooks\Automation;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureAutomation\Entities\Automation as AutomationEntity;
use Espo\Modules\Global\Services\TeamTenantAccess;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Derives `tenantId` from the assigned teams.
 *
 * Resolution lives in TeamTenantAccess so that derivation and the
 * ValidateTeamsTenant authorization check can never disagree about which tenant
 * a team belongs to.
 *
 * @implements BeforeSave<AutomationEntity>
 */
class AssignTenantFromTeam implements BeforeSave
{
    public static int $order = 9;

    public function __construct(
        private TeamTenantAccess $teamTenantAccess,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof AutomationEntity) {
            return;
        }

        if ($options->get('silent') || $entity->get('tenantId')) {
            return;
        }

        $tenantId = $this->teamTenantAccess->deriveTenantId($entity, 'automation');

        if ($tenantId !== null) {
            $entity->set('tenantId', $tenantId);
        }
    }
}
