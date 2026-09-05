<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureSimpleJourney\Services;

use Espo\Core\ApplicationState;
use Espo\Core\Record\Hook\SaveHook;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Runs before REST required-field validation, and for ORM creates. */
class Defaults implements SaveHook
{
    public function __construct(
        private ApplicationState $applicationState,
        private UserTenantResolver $userTenantResolver,
        private TenantResolver $tenantResolver,
        private EntityManager $entityManager,
        private JourneyAccess $journeyAccess,
    ) {}

    public function process(Entity $entity): void
    {
        if (!$entity->isNew()) {
            return;
        }

        if ($entity->getEntityType() !== 'SimpleJourney') {
            $this->journeyAccess->requireParent(
                $entity,
                $entity->getEntityType() === 'SimpleJourneyStage' ? 'edit' : 'read',
            );

            return;
        }

        if (!$entity->get('tenantId')) {
            // Respect an explicitly selected set of teams before falling back to the actor.
            $teamIds = $entity->get('teamsIds');
            $tenantId = is_array($teamIds) && $teamIds !== []
                ? $this->tenantResolver->resolveUniqueFromTeamIds($teamIds)
                : null;

            if (!$tenantId && $this->applicationState->isLogged()) {
                $user = $this->applicationState->getUser();
                $tenants = $this->userTenantResolver->resolveTenantIds($user);

                if (count($tenants) === 1) {
                    $tenantId = $tenants[0];
                } else {
                    $defaultTeam = $user->get('defaultTeamId');
                    $defaultTenant = $defaultTeam
                        ? $this->tenantResolver->resolveUniqueFromTeamIds([$defaultTeam])
                        : null;

                    if ($defaultTenant && in_array($defaultTenant, $tenants, true)) {
                        $tenantId = $defaultTenant;
                    }
                }
            }

            if ($tenantId) {
                $entity->set('tenantId', $tenantId);
            }
        }

        $tenantId = $entity->get('tenantId');
        $tenant = is_string($tenantId) && $tenantId !== ''
            ? $this->entityManager->getEntityById('Tenant', $tenantId)
            : null;

        if (!$tenant) {
            return; // Required validation / ownership validation will reject unresolved tenancy.
        }

        $entity->set('tenantName', $tenant->get('name'));

        // An explicitly empty team list is not a request for defaults.
        if (!$entity->has('teamsIds') && $tenant->get('baseUserTeamId')) {
            $entity->set('teamsIds', [$tenant->get('baseUserTeamId')]);
        }
    }
}
