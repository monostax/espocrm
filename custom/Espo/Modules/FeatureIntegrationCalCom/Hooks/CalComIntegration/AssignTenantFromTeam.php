<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureIntegrationCalCom\Hooks\CalComIntegration;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureIntegrationCalCom\Entities\CalComIntegration;
use Espo\Modules\Global\Services\TeamTenantAccess;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Derives cal.com integration `tenantId` from the assigned teams.
 *
 * Resolution lives in TeamTenantAccess, which matches a tenant's base user team
 * AND its other user teams. Matching only the base team used to leave a null
 * tenant for legitimate secondary-team assignments, and every consumer treats a
 * null tenant as a missing key — so the record silently stopped working.
 *
 * @implements BeforeSave<Entity>
 */
class AssignTenantFromTeam implements BeforeSave
{
    public static int $order = 9;

    public function __construct(
        private TeamTenantAccess $teamTenantAccess,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof CalComIntegration) {
            return;
        }

        if ($options->get('silent')) {
            return;
        }

        // Respect explicit tenant assignments.
        if ($entity->get('tenantId')) {
            return;
        }

        $tenantId = $this->teamTenantAccess->deriveTenantId(
            $entity,
            'cal.com integration',
            includePersistedTeams: true,
        );

        if ($tenantId !== null) {
            $entity->set('tenantId', $tenantId);
        }
    }
}
