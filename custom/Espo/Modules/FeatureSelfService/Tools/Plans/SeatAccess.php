<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureSelfService\Tools\Plans;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\FeatureAiUsage\Services\Access as BillingAccess;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Global\Tools\Tenant\TenantAdminTeamProvisioner;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Tenant-scoped, reversible seat removal. Historical users and messages are retained. */
class SeatAccess
{
    public const SAVE_OPTION = 'selfServiceSeatAccess';

    public function __construct(
        private EntityManager $entityManager,
        private SeatRoster $roster,
        private ChatwootApiClient $chatwoot,
        private UserTenantResolver $userTenants,
        private TenantAdminTeamProvisioner $adminTeams,
        private BillingAccess $billingAccess,
        private Log $log,
    ) {}

    public function change(string $tenantId, string $userId, bool $enabled, string $actorId): array
    {
        $this->billingAccess->assertTenant($tenantId);
        $this->entityManager->getTransactionManager()->run(function () use ($tenantId, $userId, $enabled, $actorId): void {
            $this->lock($tenantId);
            $this->billingAccess->assertTenant($tenantId);
            $tenant = $this->entityManager->getEntityById('Tenant', $tenantId);
            if (!$tenant?->get('plan')) throw new NotFound('Self-service workspace not found.');
            $rows = $this->roster->read($tenantId, $actorId);
            $row = array_column($rows['users'], null, 'id')[$userId] ?? null;
            $user = $this->entityManager->getEntityById('User', $userId);
            if (!$row || !$user instanceof User || $user->isAdmin() || $user->isSystem()) throw new Forbidden('User is not a customer seat.');
            if ($userId === $actorId) throw new Conflict('CANNOT_DISABLE_SELF');
            $seat = $this->entityManager->getRDBRepository('SelfServiceSeat')
                ->where(['tenantId' => $tenantId, 'userId' => $userId])->findOne();
            if ((bool) $seat?->get('disabled') === !$enabled) return;
            if ($rows['pending']) throw new Conflict('SEAT_ACCESS_PENDING');

            if ($enabled) {
                if ($rows['enabledUsers'] >= $rows['paidSeats']) throw new Conflict('PAID_SEAT_REQUIRED');
                $seat->set('disabled', false);
                $this->entityManager->saveEntity($seat, ['silent' => true]);
                $this->restore($tenant, $user, (array) $seat->get('accessSnapshot'));
            } else {
                $snapshot = $this->snapshot($tenant, $user);
                $seat ??= $this->entityManager->getNewEntity('SelfServiceSeat');
                $seat->set(['tenantId' => $tenantId, 'userId' => $userId, 'disabled' => true,
                    'accessSnapshot' => (object) $snapshot]);
                $this->entityManager->saveEntity($seat, ['silent' => true]);
                $this->revoke($tenant, $user, $snapshot);
            }
            $seat->set(['changedById' => $actorId, 'changedAt' => gmdate('Y-m-d H:i:s')]);
            $this->entityManager->saveEntity($seat, ['silent' => true]);
        });

        // The durable desired state is retried by the workspace-access job. Checkout
        // refuses a pending change until every Chatwoot account confirms it.
        try {
            $this->syncTenant($tenantId);
        } catch (\Throwable $e) {
            $this->log->error('Seat access sync pending for tenant ' . $tenantId . ': ' . $e->getMessage());
        }

        return $this->roster->read($tenantId, $actorId);
    }

    public function syncTenant(string $tenantId): void
    {
        $this->entityManager->getTransactionManager()->run(function () use ($tenantId): void {
            $this->lock($tenantId);
            foreach ($this->entityManager->getRDBRepository('SelfServiceSeat')->where(['tenantId' => $tenantId])->find() as $seat) {
                $disabled = (bool) $seat->get('disabled');
                if ($disabled === (bool) $seat->get('syncedDisabled')) continue;
                foreach ($this->entityManager->getRDBRepository('ChatwootAccountUserMembership')
                    ->join('chatwootAccount')->join('chatwootUser')
                    ->where(['chatwootAccount.tenantId' => $tenantId, 'chatwootAccount.deleted' => false,
                        'chatwootUser.assignedUserId' => $seat->get('userId'), 'chatwootUser.deleted' => false])->find() as $membership) {
                    $account = $this->entityManager->getEntityById('ChatwootAccount', (string) $membership->get('chatwootAccountId'));
                    $identity = $this->entityManager->getEntityById('ChatwootUser', (string) $membership->get('chatwootUserId'));
                    $platform = $this->entityManager->getEntityById('ChatwootPlatform', (string) $account->get('platformId'));
                    if (!$platform || !$account->get('chatwootAccountId') || !$identity->get('chatwootUserId')) {
                        throw new Conflict('SEAT_ACCESS_PENDING');
                    }
                    $this->chatwoot->setAccountUserBillingAccess((string) $platform->get('backendUrl'),
                        (string) $platform->get('accessToken'), (int) $account->get('chatwootAccountId'),
                        (int) $identity->get('chatwootUserId'), $disabled);
                }
                $seat->set('syncedDisabled', $disabled);
                $this->entityManager->saveEntity($seat, ['silent' => true]);
            }
        });
    }

    public function syncPending(): void
    {
        $tenants = [];
        foreach ($this->entityManager->getRDBRepository('SelfServiceSeat')->find() as $seat) {
            if ((bool) $seat->get('disabled') !== (bool) $seat->get('syncedDisabled')) $tenants[$seat->get('tenantId')] = true;
        }
        foreach (array_keys($tenants) as $tenantId) {
            try {
                $this->syncTenant($tenantId);
            } catch (\Throwable $e) {
                $this->log->error('Seat access sync pending for tenant ' . $tenantId . ': ' . $e->getMessage());
            }
        }
    }

    private function lock(string $tenantId): void
    {
        $query = $this->entityManager->getPDO()->prepare('SELECT id FROM tenant WHERE id = ? AND deleted = FALSE FOR UPDATE');
        $query->execute([$tenantId]);
        if (!$query->fetchColumn()) throw new NotFound('Workspace not found.');
    }

    private function snapshot(Entity $tenant, User $user): array
    {
        $teamIds = array_filter([(string) $tenant->get('baseUserTeamId'), (string) $this->adminTeams->findAdminTeamId($tenant)]);
        foreach ($this->entityManager->getRDBRepository('Tenant')->getRelation($tenant, 'otherUserTeams')->find() as $team) {
            $teamIds[] = (string) $team->getId();
        }
        $owned = [];
        foreach (array_unique($teamIds) as $teamId) {
            $team = $this->entityManager->getEntityById('Team', $teamId);
            if (!$team) continue;
            if (!$this->entityManager->getRDBRepository('User')->getRelation($user, 'teams')->isRelatedById($teamId)) continue;
            // Shared teams cannot be removed without affecting another workspace.
            if ($this->entityManager->getRDBRepository('Tenant')->where(['baseUserTeamId' => $teamId, 'id!=' => $tenant->getId()])->findOne() ||
                $this->entityManager->getRDBRepository('Team')->getRelation($team, 'tenantsAsOtherUserTeam')
                    ->where(['id!=' => $tenant->getId()])->findOne()) throw new Conflict('SHARED_WORKSPACE_TEAM');
            $owned[] = $teamId;
        }

        return ['teams' => $owned, 'workspaceTeams' => array_values(array_unique($teamIds)),
            'defaultTeamId' => $user->get('defaultTeamId'), 'wasActive' => $user->isActive(),
            'tenantRelated' => $this->entityManager->getRDBRepository('Tenant')->getRelation($tenant, 'users')->isRelatedById($user->getId()),
            'deactivateUser' => $user->isActive() && array_diff($this->userTenants->resolveTenantIds($user), [$tenant->getId()]) === []];
    }

    private function revoke(Entity $tenant, User $user, array $snapshot): void
    {
        $options = [self::SAVE_OPTION => true];
        $relation = $this->entityManager->getRDBRepository('Tenant')->getRelation($tenant, 'users');
        if ($snapshot['tenantRelated']) $relation->unrelateById($user->getId(), $options);
        $teams = $this->entityManager->getRDBRepository('User')->getRelation($user, 'teams');
        foreach ($snapshot['teams'] as $id) $teams->unrelateById($id, $options);
        $user = $this->entityManager->getEntityById('User', $user->getId());
        if (in_array($user->get('defaultTeamId'), $snapshot['teams'], true)) $user->set('defaultTeamId', null);
        if ($snapshot['deactivateUser']) $user->set('isActive', false);
        $this->entityManager->saveEntity($user, $options);
    }

    private function restore(Entity $tenant, User $user, array $snapshot): void
    {
        $options = [self::SAVE_OPTION => true];
        if ($snapshot['tenantRelated'] ?? false) {
            $this->entityManager->getRDBRepository('Tenant')->getRelation($tenant, 'users')->relateById($user->getId(), null, $options);
        }
        $teams = $this->entityManager->getRDBRepository('User')->getRelation($user, 'teams');
        $currentTeams = array_filter([(string) $tenant->get('baseUserTeamId'), (string) $this->adminTeams->findAdminTeamId($tenant)]);
        foreach ($this->entityManager->getRDBRepository('Tenant')->getRelation($tenant, 'otherUserTeams')->find() as $team) {
            $currentTeams[] = $team->getId();
        }
        foreach ($snapshot['teams'] ?? [] as $id) {
            if (in_array($id, $currentTeams, true) && $this->entityManager->getEntityById('Team', $id)) $teams->relateById($id, null, $options);
        }
        if (($snapshot['defaultTeamId'] ?? null) && !$user->get('defaultTeamId') && $teams->isRelatedById($snapshot['defaultTeamId'])) {
            $user->set('defaultTeamId', $snapshot['defaultTeamId']);
        }
        if ($snapshot['wasActive'] ?? $snapshot['deactivateUser'] ?? false) $user->set('isActive', true);
        $this->entityManager->saveEntity($user, $options);
    }
}
