<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Classes\NextAction;

use Espo\ORM\EntityManager;
use Espo\ORM\Query\SelectBuilder;

/**
 * Where-clause for activities that belong to a tenant. Activity tenancy predates
 * tenantId: Task/Meeting ownership is expressed by the tenant's workspace teams
 * (same rule as the Activities inbox); VoIP Calls may carry tenantId.
 */
final class TenantActivities
{
    public static function where(EntityManager $em, string $type, ?string $tenantId): ?array
    {
        $tenant = $tenantId ? $em->getEntityById('Tenant', $tenantId) : null;
        if (!$tenant) {
            return null;
        }
        $teamIds = [$tenant->get('baseUserTeamId')];
        foreach ($em->getRDBRepository('Tenant')->getRelation($tenant, 'otherUserTeams')->find() as $team) {
            $teamIds[] = $team->getId();
        }
        $teamIds = array_values(array_unique(array_filter($teamIds)));
        if (!$teamIds) {
            return null;
        }
        $where = ['id=s' => SelectBuilder::create()->from($type, 'tenantActivity')->select('id')
            ->join('teams', 'tenantTeam')->where(['tenantTeam.id' => $teamIds])->build()];
        if ($type === 'Call' && $em->getDefs()->getEntity('Call')->hasAttribute('tenantId')) {
            $where = ['OR' => [['tenantId' => $tenantId], ['tenantId' => null, $where]]];
        }
        return $where;
    }
}
