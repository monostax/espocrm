<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Hooks\MetaCapiDataset;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDataset;
use Espo\Modules\Global\Services\TeamTenantAccess;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Derives Meta CAPI dataset `tenantId` from the assigned teams.
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
        if (!$entity instanceof MetaCapiDataset) {
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
            'Meta CAPI dataset',
            includePersistedTeams: true,
        );

        if ($tenantId !== null) {
            $entity->set('tenantId', $tenantId);
        }
    }
}
