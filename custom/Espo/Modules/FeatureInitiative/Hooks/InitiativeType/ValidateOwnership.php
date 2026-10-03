<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureInitiative\Hooks\InitiativeType;

use Espo\Modules\FeatureInitiative\Services\ValidationError;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\Global\Services\TeamTenantAccess;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

class ValidateOwnership implements BeforeSave
{
    public static int $order = 5;

    public function __construct(private TeamTenantAccess $teamTenantAccess) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if ($entity->has('teamsIds') && !$entity->get('teamsIds')) {
            throw ValidationError::badRequest('teamsRequired', 'An initiative type requires teams belonging to one tenant.');
        }

        $teams = $this->teamTenantAccess->resolveTeamIds($entity, true);
        $tenantId = $this->teamTenantAccess->deriveTenantId($entity, 'initiative type', true, true);

        if (!$tenantId || $teams === []) {
            throw ValidationError::badRequest('teamsRequired', 'An initiative type requires teams belonging to one tenant.');
        }

        // Reject unowned or ambiguously owned teams too: each team grants access.
        foreach ($teams as $teamId) {
            if (array_keys($this->teamTenantAccess->tenantIdsForTeams([$teamId])) !== [$tenantId]) {
                throw ValidationError::badRequest('teamsMismatch', 'Every initiative type team must belong to the same tenant.');
            }
        }

        if (
            (!$entity->isNew() && $entity->getFetched('tenantId') !== $tenantId) ||
            ($entity->get('tenantId') && $entity->get('tenantId') !== $tenantId)
        ) {
            throw ValidationError::badRequest('cannotChangeTenant', 'An initiative type cannot be moved to another tenant.');
        }

        $this->teamTenantAccess->assertCanAssignTeams($teams, $tenantId, 'initiative type');
        $entity->set('tenantId', $tenantId);
    }
}
