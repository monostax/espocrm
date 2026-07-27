<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Hook;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\Global\Services\TeamTenantAccess;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Refuses `teams` assignments that reach into another workspace (tenant).
 *
 * On every tenant-scoped configuration entity, `teams` is the one user-writable
 * field that decides two things at once:
 *
 *  1. The derived `tenantId` (see AssignTenantFromTeam), which is the scope every
 *     tenant-aware query uses. Assigning another workspace's team stamps that
 *     workspace's id onto the record.
 *  2. Read access, because these scopes use team ACL. Assigning a foreign team
 *     shares the record — and anything cascaded from it — with that workspace.
 *
 * Unlike an unresolved tenant, which the consumers treat as a missing key and
 * fail closed on, a *foreign* tenant is fully functional and therefore not
 * self-limiting. This hook is what makes it unreachable.
 *
 * Lives outside `Hooks/` so the hook loader (which maps `Hooks/<EntityType>/`)
 * never tries to instantiate this abstract class as a hook.
 *
 * @implements BeforeSave<Entity>
 */
abstract class ValidateTeamsTenantBase implements BeforeSave
{
    /** After AssignTenantFromTeam, so the derived tenant is validated too. */
    public static int $order = 10;

    public function __construct(
        protected TeamTenantAccess $teamTenantAccess,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if ($options->get('silent')) {
            return;
        }

        if (!$entity->isNew() && !$entity->isAttributeChanged('teamsIds')) {
            return;
        }

        $tenantId = $entity->get('tenantId');
        $tenantId = is_string($tenantId) && $tenantId !== '' ? $tenantId : null;

        $this->teamTenantAccess->assertCanAssignTeams(
            // Deliberately without the persisted-teams fallback: when `teamsIds`
            // was not part of this save we have already returned above.
            $this->teamTenantAccess->resolveTeamIds($entity),
            $tenantId,
            $this->label($entity),
        );
    }

    protected function label(Entity $entity): string
    {
        return $entity->getEntityType();
    }
}
