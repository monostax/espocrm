<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureSelfService\Tools\Plans;

use Espo\Entities\User;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\SelectBuilder;

/** Removing teams alone is insufficient: an assigned owner also has CRM access. */
class SeatBoundary
{
    public function __construct(private EntityManager $entityManager) {}

    public function apply(User $user, string $type, SelectBuilder $query): bool
    {
        if (!$user->hasId() || $user->isAdmin() || $user->isSystem()) return false;
        $tenantIds = $teamIds = [];
        foreach ($this->entityManager->getRDBRepository('SelfServiceSeat')
            ->where(['userId' => $user->getId(), 'disabled' => true])->find() as $seat) {
            $tenantIds[] = $seat->get('tenantId');
            $snapshot = (array) $seat->get('accessSnapshot');
            $teamIds = array_merge($teamIds, $snapshot['workspaceTeams'] ?? $snapshot['teams'] ?? []);
            // Teams added after revocation must not reopen assigned-owner access.
            $tenant = $this->entityManager->getEntityById('Tenant', (string) $seat->get('tenantId'));
            if (!$tenant) continue;
            if ($tenant->get('baseUserTeamId')) $teamIds[] = $tenant->get('baseUserTeamId');
            foreach ($this->entityManager->getRDBRepository('Tenant')->getRelation($tenant, 'otherUserTeams')->find() as $team) {
                $teamIds[] = $team->getId();
            }
        }
        if (!$tenantIds) return false;
        $defs = $this->entityManager->getDefs()->getEntity($type);
        if ($type === 'Tenant') $query->where(['id!=' => $tenantIds]);
        if ($defs->hasAttribute('tenantId')) {
            $query->where(['OR' => [['tenantId' => null], ['tenantId!=' => $tenantIds]]]);
        }
        if ($teamIds && $defs->hasRelation('teams')) {
            $query->where(['id!=s' => SelectBuilder::create()->from('EntityTeam')->select('entityId')
                ->where(['entityType' => $type, 'teamId' => array_values(array_unique($teamIds)), 'deleted' => false])->build()]);
        }
        return true;
    }
}
