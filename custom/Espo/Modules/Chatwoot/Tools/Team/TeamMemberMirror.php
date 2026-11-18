<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools\Team;

use Espo\Core\Acl\Cache\Clearer;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use RuntimeException;

/** Projects one authoritative Chatwoot roster onto the local chat team and its keyed CRM Team. */
class TeamMemberMirror
{
    public function __construct(
        private EntityManager $entityManager,
        private TenantTeamMirror $mirror,
        private TenantResolver $tenants,
        private Clearer $aclCache,
    ) {}

    /** @param list<int> $remoteUserIds Chatwoot platform user IDs, not account_user IDs. */
    public function sync(Entity $account, Entity $chatTeam, array $remoteUserIds): void
    {
        if ($chatTeam->get('accountId') !== $account->getId()) {
            throw new RuntimeException('Chatwoot team does not belong to the syncing account.');
        }

        [$memberships, $userIds] = $this->resolveMembers($account, $remoteUserIds);
        $this->syncAgentLinks($chatTeam, $memberships);
        $this->syncUsers($account, $chatTeam, $userIds);
    }

    /** @return array{list<string>, list<string>} Local account membership IDs and assigned CRM user IDs. */
    private function resolveMembers(Entity $account, array $remoteUserIds): array
    {
        if (!$remoteUserIds) {
            return [[], []];
        }
        $query = $this->entityManager->getQueryBuilder()->select()->from('ChatwootAccountUserMembership')
            ->join('chatwootUser')
            ->where([
                'chatwootAccountId' => $account->getId(),
                'chatwootUser.platformId' => $account->get('platformId'),
                'chatwootUser.chatwootUserId' => $remoteUserIds,
            ])
            ->select(['id', 'chatwootUserId', ['chatwootUser.chatwootUserId', 'remoteId'],
                ['chatwootUser.assignedUserId', 'crmUserId']])
            ->build();
        $rows = $this->entityManager->getQueryExecutor()->execute($query)->fetchAll(\PDO::FETCH_ASSOC);
        $counts = array_count_values(array_column($rows, 'remoteId'));
        $memberships = [];
        $userIds = [];
        foreach ($rows as $row) {
            // Ambiguous/stale identity links must not grant access to both people.
            if ($counts[$row['remoteId']] !== 1) {
                continue;
            }
            $memberships[] = $row['id'];
            if ($row['crmUserId'] && $row['chatwootUserId'] !== $account->get('conciergeUserId')) {
                $userIds[] = $row['crmUserId'];
            }
        }

        return [$memberships, $userIds];
    }

    private function syncAgentLinks(Entity $chatTeam, array $memberships): void
    {
        $agents = $this->entityManager->getRDBRepository('ChatwootTeam')->getRelation($chatTeam, 'accountUserMemberships');
        $current = [];
        foreach ($agents->find() as $member) {
            $current[] = $member->getId();
        }
        // The ordinary chat-team relation hooks push changes to Chatwoot; this is the pull path.
        foreach (array_diff($current, $memberships) as $id) {
            $agents->unrelateById($id, ['skipHooks' => true]);
        }
        foreach (array_diff($memberships, $current) as $id) {
            $agents->relateById($id, null, ['skipHooks' => true]);
        }
    }

    private function syncUsers(Entity $account, Entity $chatTeam, array $userIds): void
    {
        $teamId = $this->mirror->findTeamId($chatTeam);
        if (!$teamId && $userIds) {
            $teamId = $this->mirror->ensureFor($chatTeam);
        }
        if (!$teamId) {
            return;
        }
        $team = $this->entityManager->getEntityById('Team', $teamId);
        if (!$team) {
            return;
        }

        $desired = [];
        $eligibleTeams = $userIds ? $this->eligibleAccountTeams($account, $teamId) : [];
        if ($eligibleTeams) {
            $users = $this->entityManager->getRDBRepository('User')->join('teams')->distinct()->where([
                'id' => $userIds,
                'type' => [User::TYPE_REGULAR, User::TYPE_ADMIN],
                'isActive' => true,
                'teams.id' => $eligibleTeams,
            ])->find();
            foreach ($users as $user) {
                $desired[$user->getId()] = $user;
            }
        }

        $users = $this->entityManager->getRDBRepository('Team')->getRelation($team, 'users');
        foreach ($users->find() as $user) {
            if (isset($desired[$user->getId()])) {
                unset($desired[$user->getId()]);
                continue;
            }
            $users->unrelate($user);
            $this->aclCache->clearForUser($user);
        }
        foreach ($desired as $user) {
            // Keep seat/ACL hooks; don't provision another Chatwoot account membership.
            $users->relate($user, null, ['skipChatwootProvisioning' => true]);
            $this->aclCache->clearForUser($user);
        }
    }

    /** A mirror must never become the reason a user qualifies for a workspace. */
    private function eligibleAccountTeams(Entity $account, string $mirrorId): array
    {
        $accountTeamIds = $account->getLinkMultipleIdList('teams');
        if (!$accountTeamIds) {
            return [];
        }
        $tenantId = $account->get('tenantId') ?? $this->tenants->resolveUniqueFromTeamIds($accountTeamIds);
        if (!$tenantId || $this->tenants->resolveUniqueFromTeamIds([$mirrorId]) !== $tenantId) {
            return [];
        }
        $tenant = $this->entityManager->getEntityById('Tenant', $tenantId);
        if (!$tenant) {
            return [];
        }
        $teamIds = [$tenant->get('baseUserTeamId')];
        $otherTeams = $this->entityManager->getRDBRepository('Tenant')->getRelation($tenant, 'otherUserTeams')
            ->where(['id' => $accountTeamIds]);
        foreach ($otherTeams->find() as $other) {
            if (!str_starts_with((string) $other->get('provisioningKey'), TenantTeamMirror::KEY_PREFIX)) {
                $teamIds[] = $other->getId();
            }
        }

        return array_values(array_intersect($accountTeamIds, array_diff($teamIds, [$mirrorId])));
    }
}
