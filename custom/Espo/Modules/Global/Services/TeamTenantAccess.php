<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Services;

use Espo\Core\ApplicationState;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\Global\Classes\Utils\TenantRoleAuth;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Guards the `teams` field on tenant-scoped configuration entities
 * (Automation, Journey).
 *
 * `teams` is the source of truth for two things at once:
 *
 *  1. AssignTenantFromTeam derives `tenantId` from it, and `tenantId` is what
 *     every read predicate scopes on. Assigning another workspace's team is
 *     therefore a way to *name* a foreign tenant and read its records.
 *  2. It is cascaded onto derived rows — JourneyRecord
 *     (CascadeTenantTeamsFromJourney) and AutomationRun / AutomationRunItem
 *     (AutomationRunner) — which carry record payloads. Assigning a foreign
 *     team publishes this workspace's data to that workspace via team ACL.
 *
 * Rule: a non-instance-admin may only assign teams that resolve to tenants they
 * themselves belong to. Teams that belong to no tenant at all are left alone —
 * they cannot name a foreign tenant, they yield a null `tenantId`, and the read
 * paths already fail closed on that. This keeps single-tenant and
 * functional-team deployments working while closing the cross-tenant case.
 */
class TeamTenantAccess
{
    public function __construct(
        private EntityManager $entityManager,
        private ApplicationState $applicationState,
        private TenantResolver $tenantResolver,
        private UserTenantResolver $userTenantResolver,
        private Log $log,
    ) {}

    /**
     * @param list<string> $teamIds
     * @param string $label Entity type name used in the error message.
     *
     * @throws Forbidden
     */
    public function assertCanAssignTeams(array $teamIds, ?string $entityTenantId, string $label): void
    {
        // Unauthenticated context (cron, CLI, system rebuild) has no actor to
        // scope against. Those paths save with `silent` and are trusted.
        if (!$this->applicationState->isLogged()) {
            return;
        }

        $actor = $this->applicationState->getUser();

        if (TenantRoleAuth::isInstanceAdmin($actor)) {
            return;
        }

        $actorTenantIds = $this->userTenantResolver->resolveTenantIds($actor);

        $requestedTenantIds = $this->tenantIdsForTeams($teamIds);

        // The tenant the entity will actually run as counts too: it is what the
        // read predicates use, whether it came from these teams or was already set.
        if ($entityTenantId !== null && $entityTenantId !== '') {
            $requestedTenantIds[$entityTenantId] = true;
        }

        $foreign = array_values(array_diff(array_keys($requestedTenantIds), $actorTenantIds));

        if ($foreign === []) {
            return;
        }

        $this->log->warning(
            'TeamTenantAccess: user ' . $actor->getId() . ' attempted to assign ' . $label .
            ' to foreign tenant(s) ' . implode(', ', $foreign) .
            ' via teams [' . implode(', ', $teamIds) . '].'
        );

        throw new Forbidden(
            'The selected teams belong to another workspace (tenant). A ' . $label .
            ' may only be assigned to teams in your own workspace.'
        );
    }

    /**
     * Derive the owning tenant from assigned teams.
     *
     * Resolves via base user team AND other user teams: a tenant owns both, so
     * matching only the base team left legitimate other-user-team assignments
     * with a null tenant — which the fail-closed read paths then refuse, making
     * the record silently invisible or inert.
     *
     * @param string $label Entity type name used in the error message.
     * @param bool $strictAmbiguity Refuse the save when teams span several
     *        tenants. Pass false only where the caller has a legitimate
     *        disambiguating fallback (e.g. CustomFieldDef's linked group).
     * @param bool $includePersistedTeams Re-read teams from the database when the
     *        payload did not carry them, so updates that leave `teams` untouched
     *        can still backfill a missing tenant.
     *
     * @throws BadRequest when the teams span more than one tenant and
     *         $strictAmbiguity is true.
     */
    public function deriveTenantId(
        Entity $entity,
        string $label,
        bool $strictAmbiguity = true,
        bool $includePersistedTeams = false,
    ): ?string {
        $teamIds = $this->resolveTeamIds($entity, $includePersistedTeams);

        if ($teamIds === []) {
            return null;
        }

        $tenantIds = array_keys($this->tenantIdsForTeams($teamIds));

        if ($tenantIds === []) {
            return null;
        }

        if (count($tenantIds) > 1) {
            $this->log->warning(
                'TeamTenantAccess: ' . $label . ' ' . ($entity->getId() ?? '(new)') .
                ' resolves to multiple tenants (' . implode(', ', $tenantIds) . ').'
            );

            if (!$strictAmbiguity) {
                return null;
            }

            // Leaving this unset would persist a record that no tenant-scoped read
            // can ever match, and for some consumers an empty tenant also defeats
            // hand-written `A !== '' && B !== ''` cross-tenant guards. Refuse the
            // save so the ambiguity is fixed instead of failing silently later.
            throw new BadRequest(
                'The selected teams belong to more than one workspace (tenant), so the '
                . 'owning workspace for this ' . $label . ' is ambiguous. Select teams '
                . 'from a single workspace.'
            );
        }

        return $tenantIds[0];
    }

    /**
     * Tenants owning any of the given teams, as base user team or other user team.
     *
     * Delegates to TenantResolver so the team -> tenant edge is defined in exactly
     * one place; kept as a set because callers test membership.
     *
     * @param list<string> $teamIds
     *
     * @return array<string, true> Set of tenant ids.
     */
    public function tenantIdsForTeams(array $teamIds): array
    {
        return array_fill_keys($this->tenantResolver->resolveAllFromTeamIds($teamIds), true);
    }

    /**
     * Resolve assigned team ids, matching how the derivation reads them so
     * validation and derivation can never disagree.
     *
     * @param bool $includePersisted Fall back to the stored teams when the payload
     *        carried none. Only useful for backfilling a missing tenant; the
     *        authorization path never needs it, because it exits early when
     *        `teamsIds` was not part of the save.
     *
     * @return list<string>
     */
    public function resolveTeamIds(Entity $entity, bool $includePersisted = false): array
    {
        if ($entity instanceof CoreEntity) {
            try {
                $ids = $entity->getLinkMultipleIdList('teams') ?: [];
                if ($ids !== []) {
                    return array_values(array_unique($ids));
                }
            } catch (\Throwable) {
            }
        }

        $teamsIds = $entity->get('teamsIds');
        if (is_array($teamsIds) && $teamsIds !== []) {
            return array_values(array_unique($teamsIds));
        }

        if ($includePersisted && !$entity->isNew() && $entity->hasId()) {
            $existing = $this->entityManager->getEntityById($entity->getEntityType(), $entity->getId());

            if ($existing instanceof CoreEntity) {
                try {
                    return array_values(array_unique($existing->getLinkMultipleIdList('teams') ?: []));
                } catch (\Throwable) {
                }
            }
        }

        return [];
    }
}
