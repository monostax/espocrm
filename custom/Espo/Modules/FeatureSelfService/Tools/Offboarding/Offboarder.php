<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

declare(strict_types=1);

namespace Espo\Modules\FeatureSelfService\Tools\Offboarding;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Chatwoot\Services\ManagedIdentityPolicy;
use Espo\Modules\Chatwoot\Tools\WahaSessionTeardown;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\LeaseLost;
use Espo\Modules\Global\Tools\Tenant\TenantAdminTeamProvisioner;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Throwable;

/**
 * Removes a tenant and what it owns, outside and inside the CRM. A Tenant
 * can't be removed any other way (Hooks\Tenant\RequireOffboarding).
 *
 * Steps, in order:
 *
 * 1. `access`: users who belong only to this tenant are deactivated (and
 *    removed in `users`); everyone else, instance admins included, loses
 *    the tenant and its teams. Managed AI agent users are left to their
 *    identity's retirement.
 * 2. `channels`: every WAHA session of the tenant's Chatwoot accounts is
 *    ended, so no WhatsApp number stays logged in.
 * 3. `chatwootRemote`: each ChatwootAccount is suspended locally (the sync
 *    jobs skip it) and its Chatwoot account is deleted.
 * 4. `chatwootLocal`: the local mirror is removed in batches without remote
 *    calls, then the ChatwootAccount row.
 * 5. `records`: every tenant-scoped record (TenantDataMap), children first,
 *    except billing and usage history.
 * 6. `sharedRecords`: records whose only teams are this tenant's teams
 *    (tasks, meetings, emails, ...).
 * 7. `teams`: the tenant's teams that no other tenant uses.
 * 8. `users`: the users from step 1, and their Chatwoot users when no other
 *    account has them.
 * 9. `tenant`: the Tenant, whose native cascade sweeps CRM data added since.
 *
 * Every step checks its own result, and batch steps keep a cursor, so a rerun
 * continues where the last one stopped. A record a remove hook refuses
 * (Forbidden, Conflict, BadRequest) is kept and counted; other errors retry
 * with backoff. One worker holds the lease at a time, as in Provisioner.
 *
 * Starting refuses while the tenant has an open subscription: cancel it
 * first, so the payer is not charged for a removed workspace.
 */
class Offboarder
{
    public const ENTITY_TYPE = 'SelfServiceOffboarding';

    /** Remove option that lets RequireOffboarding allow a Tenant removal. */
    public const REMOVE_OPTION = 'tenantOffboarding';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_FAILED = 'failed';
    public const STATUS_COMPLETED = 'completed';

    private const STEP_NEXT = [
        'access' => 'channels',
        'channels' => 'chatwootRemote',
        'chatwootRemote' => 'chatwootLocal',
        'chatwootLocal' => 'records',
        'records' => 'sharedRecords',
        'sharedRecords' => 'teams',
        'teams' => 'users',
        'users' => 'tenant',
        'tenant' => 'done',
    ];

    /** Checkout states of a subscription that can still charge (PlanCheckout::OPEN). */
    private const OPEN_SUBSCRIPTION_STATES = ['accepted', 'creating', 'unknown', 'pending', 'active'];

    /**
     * Removing the mirror after its Chatwoot account is gone: no hook calls
     * Chatwoot or WAHA again (each hook skips on one of these).
     */
    private const MIRROR_OPTIONS = ['cascadeParent' => true, 'skipChatwootSync' => true, 'skipChatwootDelete' => true];

    private const BATCH = 100;
    private const RUN_SECONDS = 240;
    private const LEASE_SECONDS = 600;
    private const MAX_ATTEMPTS = 8;
    private const MAX_RETRY_DELAY_SECONDS = 1800;

    public function __construct(
        private EntityManager $entityManager,
        private TenantDataMap $map,
        private TenantAdminTeamProvisioner $adminTeams,
        private UserTenantResolver $userTenants,
        private WahaSessionTeardown $waha,
        private ChatwootApiClient $chatwoot,
        private ManagedIdentityPolicy $identities,
        private Log $log,
    ) {}

    /**
     * Record the offboarding of a tenant; the job runs it. Starting a failed
     * one again retries it now. The tenant may already be soft-deleted: its
     * leftovers are removed the same way.
     *
     * @throws NotFound
     * @throws BadRequest SLUG_MISMATCH
     * @throws Conflict OPEN_SUBSCRIPTION
     */
    public function start(string $tenantId, string $confirmSlug, string $requestedById): Entity
    {
        $tenant = $this->findTenant($tenantId) ?? throw new NotFound('Tenant not found.');

        if (trim($confirmSlug) === '' || !hash_equals((string) $tenant->get('slug'), trim($confirmSlug))) {
            throw new BadRequest('SLUG_MISMATCH');
        }

        $existing = $this->findByTenant($tenantId);

        if ($existing) {
            return $this->retryIfFailed($existing);
        }

        if ($this->hasOpenSubscription($tenantId)) {
            throw new Conflict('OPEN_SUBSCRIPTION');
        }

        try {
            return $this->entityManager->createEntity(self::ENTITY_TYPE, [
                'name' => $tenant->get('name') . ' (' . $tenant->get('slug') . ')',
                'tenantId' => $tenantId,
                'tenantName' => $tenant->get('name'),
                'tenantSlug' => $tenant->get('slug'),
                'requestedById' => $requestedById,
                'status' => self::STATUS_PENDING,
                'step' => 'access',
                'state' => (object) [],
                'nextAttemptAt' => $this->at(0),
            ], ['silent' => true]);
        } catch (Throwable $e) {
            // A concurrent start() inserted it first (unique tenantId).
            return $this->findByTenant($tenantId) ?? throw $e;
        }
    }

    public function findByTenant(string $tenantId): ?Entity
    {
        return $this->entityManager->getRDBRepository(self::ENTITY_TYPE)->where(['tenantId' => $tenantId])->findOne();
    }

    /**
     * Run the offboarding for up to a few minutes, then leave the rest to the
     * next job run. Safe to call again and from several workers.
     */
    public function run(string $id): ?Entity
    {
        $lease = $this->claim($id);
        $record = $this->entityManager->getEntityById(self::ENTITY_TYPE, $id);

        if ($lease === null || !$record) {
            return $record;
        }

        try {
            $this->runSteps($record, $lease);
        } catch (LeaseLost) {
            $this->log->warning("SelfService: lost the lease on offboarding {$id}; another worker continues it.");
        } catch (OffboardingFailure $e) {
            $this->fail($record, $lease, $e, false);
        } catch (Throwable $e) {
            $this->fail($record, $lease, $e, true);
        }

        return $this->entityManager->getEntityById(self::ENTITY_TYPE, $id);
    }

    /**
     * @return list<string>
     */
    public function findDueIds(int $limit): array
    {
        $now = $this->at(0);

        $records = $this->entityManager
            ->getRDBRepository(self::ENTITY_TYPE)
            ->select(['id'])
            ->where([
                'OR' => [
                    ['status' => [self::STATUS_PENDING, self::STATUS_FAILED], 'nextAttemptAt<=' => $now],
                    ['status' => self::STATUS_RUNNING, 'leaseUntil<' => $now],
                ],
            ])
            ->order('nextAttemptAt')
            ->limit(0, $limit)
            ->find();

        $ids = [];

        foreach ($records as $record) {
            $ids[] = (string) $record->getId();
        }

        return $ids;
    }

    private function runSteps(Entity $record, string $lease): void
    {
        $deadline = time() + self::RUN_SECONDS;

        while (($step = (string) $record->get('step')) !== 'done') {
            $finished = match ($step) {
                'access' => $this->revokeAccess($record, $lease),
                'channels' => $this->endWahaSessions($record, $lease),
                'chatwootRemote' => $this->deleteChatwootAccounts($record, $lease),
                'chatwootLocal' => $this->removeChatwootMirror($record, $lease),
                'records' => $this->removeTenantRecords($record, $lease),
                'sharedRecords' => $this->removeTeamOnlyRecords($record, $lease),
                'teams' => $this->removeTeams($record, $lease),
                'users' => $this->removeUsers($record, $lease),
                'tenant' => $this->removeTenant($record),
                default => throw new OffboardingFailure("Unknown step '{$step}'."),
            };

            if ($finished) {
                $this->persist($record, $lease, [
                    'step' => self::STEP_NEXT[$step],
                    'attempts' => 0,
                    'leaseUntil' => $this->at(self::LEASE_SECONDS),
                ]);
            }

            if (time() >= $deadline && $record->get('step') !== 'done') {
                $this->persist($record, $lease, [
                    'status' => self::STATUS_PENDING,
                    'nextAttemptAt' => $this->at(0),
                    'leaseToken' => null,
                    'leaseUntil' => null,
                ]);

                return;
            }
        }

        $this->persist($record, $lease, [
            'status' => self::STATUS_COMPLETED,
            'completedAt' => $this->at(0),
            'lastError' => null,
            'nextAttemptAt' => null,
            'leaseToken' => null,
            'leaseUntil' => null,
        ]);
    }

    // Step 1 ------------------------------------------------------------------

    private function revokeAccess(Entity $record, string $lease): bool
    {
        $tenant = $this->tenant($record);
        $tenantId = (string) $tenant->getId();
        $teamIds = $this->tenantTeamIds($tenant);

        $state = $this->state($record);
        $state['teamIds'] = $teamIds;
        $state['accountIds'] = $this->chatwootAccountIds($tenantId);
        $state['userIds'] ??= [];

        foreach ($this->tenantUserIds($tenant, $teamIds) as $userId) {
            $user = $this->entityManager->getEntityById(User::ENTITY_TYPE, $userId);

            if (!$user instanceof User || $this->isManagedAgentUser($userId)) {
                continue;
            }

            $otherTenants = array_diff($this->userTenants->resolveTenantIds($user), [$tenantId]);

            if ($user->isAdmin() || $user->isSystem() || $otherTenants !== []) {
                $this->unlink($user, $tenant, $teamIds);

                continue;
            }

            if ($user->isActive()) {
                $user->set('isActive', false);
                $this->entityManager->saveEntity($user);
            }

            $state['userIds'] = array_values(array_unique([...$state['userIds'], $userId]));
        }

        $this->persistState($record, $lease, $state);

        return true;
    }

    /**
     * The user keeps their other workspaces and loses this one.
     *
     * @param list<string> $teamIds
     */
    private function unlink(User $user, Entity $tenant, array $teamIds): void
    {
        $userId = (string) $user->getId();
        $tenantUsers = $this->entityManager->getRDBRepository('Tenant')->getRelation($tenant, 'users');

        // From the Tenant side, so Global SyncUserTeams drops its base and other teams.
        if ($tenantUsers->isRelatedById($userId)) {
            $tenantUsers->unrelateById($userId);
        }

        $teams = $this->entityManager->getRDBRepository(User::ENTITY_TYPE)->getRelation($user, 'teams');

        foreach ($teamIds as $teamId) {
            if ($teams->isRelatedById($teamId)) {
                $teams->unrelateById($teamId);
            }
        }

        $user = $this->entityManager->getEntityById(User::ENTITY_TYPE, $userId);

        if ($user && in_array($user->get('defaultTeamId'), $teamIds, true)) {
            $remaining = array_values(array_diff($user->getLinkMultipleIdList('teams'), $teamIds));
            $user->set('defaultTeamId', $remaining[0] ?? null);
            $this->entityManager->saveEntity($user);
        }
    }

    // Step 2 ------------------------------------------------------------------

    private function endWahaSessions(Entity $record, string $lease): bool
    {
        $state = $this->state($record);
        $state['wahaDone'] ??= [];
        $state['wahaFailed'] ??= [];

        // Channels of accounts removed before offboarding existed still count:
        // their sessions were left live. Those are ended on a best-effort basis.
        $accountIds = $this->chatwootAccountIds((string) $record->get('tenantId'), true);

        if ($accountIds === []) {
            return true;
        }

        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from('ChatwootInboxIntegration')
            ->where(['chatwootAccountId' => $accountIds])
            ->withDeleted()
            ->build();

        $integrations = $this->entityManager->getRDBRepository('ChatwootInboxIntegration')->clone($query)->find();

        foreach ($integrations as $integration) {
            $id = (string) $integration->getId();

            if (in_array($id, $state['wahaDone'], true) || !WahaSessionTeardown::hasSession($integration)) {
                continue;
            }

            try {
                $this->waha->tearDown($integration);
            } catch (Throwable $e) {
                if (!$integration->get('deleted')) {
                    throw $e;
                }

                $this->log->warning("SelfService offboarding: WAHA session of removed channel {$id} not ended: " . $e->getMessage());
                $state['wahaFailed'][] = $id;
            }

            $state['wahaDone'][] = $id;
            $this->persistState($record, $lease, $state);
        }

        return true;
    }

    // Step 3 ------------------------------------------------------------------

    private function deleteChatwootAccounts(Entity $record, string $lease): bool
    {
        $state = $this->state($record);
        $state['remoteDeleted'] ??= [];
        $state['chatwootUserIds'] ??= [];

        foreach ($state['accountIds'] ?? [] as $accountId) {
            if (in_array($accountId, $state['remoteDeleted'], true)) {
                continue;
            }

            $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);

            if ($account) {
                if ($account->get('status') !== 'suspended') {
                    $account->set('status', 'suspended');
                    $this->entityManager->saveEntity($account, [SaveOption::SKIP_HOOKS => true, SaveOption::SILENT => true]);
                }

                foreach ($this->entityManager
                    ->getRDBRepository('ChatwootAccountUserMembership')
                    ->select(['chatwootUserId'])
                    ->where(['chatwootAccountId' => $accountId])
                    ->find() as $membership) {
                    $state['chatwootUserIds'][] = (string) $membership->get('chatwootUserId');
                }

                $state['chatwootUserIds'] = array_values(array_unique(array_filter($state['chatwootUserIds'])));
                $this->deleteRemoteAccount($account);
            }

            $state['remoteDeleted'][] = $accountId;
            $this->persistState($record, $lease, $state);
        }

        return true;
    }

    private function deleteRemoteAccount(Entity $account): void
    {
        $remoteId = (int) $account->get('chatwootAccountId');

        if ($remoteId <= 0) {
            return;
        }

        $platform = $this->entityManager->getEntityById('ChatwootPlatform', (string) $account->get('platformId'));

        if (!$platform || !$platform->get('backendUrl') || !$platform->get('accessToken')) {
            throw new OffboardingFailure(
                "ChatwootAccount {$account->getId()}: its Chatwoot platform is missing or has no URL or token."
            );
        }

        try {
            $this->chatwoot->deleteAccount((string) $platform->get('backendUrl'), (string) $platform->get('accessToken'), $remoteId);
        } catch (Error $e) {
            if (!str_contains($e->getMessage(), 'HTTP 404')) {
                throw $e;
            }
        }
    }

    // Step 4 ------------------------------------------------------------------

    private function removeChatwootMirror(Entity $record, string $lease): bool
    {
        $state = $this->state($record);
        $state['mirrorDone'] ??= [];

        foreach ($state['accountIds'] ?? [] as $accountId) {
            if (in_array($accountId, $state['mirrorDone'], true)) {
                continue;
            }

            foreach ($this->map->chatwootAccountChildren() as $child) {
                if ($this->removeBatch($record, $lease, $state, $child['entityType'], [$child['column'] => $accountId], self::MIRROR_OPTIONS, "mirror:{$accountId}")) {
                    return false;
                }
            }

            $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);

            if ($account) {
                $this->removeCounted($state, $account, ['skipChatwootDelete' => true]);
            }

            $state['mirrorDone'][] = $accountId;
            $this->persistState($record, $lease, $state);
        }

        return true;
    }

    // Step 5 ------------------------------------------------------------------

    private function removeTenantRecords(Entity $record, string $lease): bool
    {
        $state = $this->state($record);
        $tenantId = (string) $record->get('tenantId');

        foreach ($this->map->tenantScopedTypes() as $type) {
            if ($this->removeBatch($record, $lease, $state, $type['entityType'], [$type['column'] => $tenantId], [], 'records')) {
                return false;
            }
        }

        return true;
    }

    // Step 6 ------------------------------------------------------------------

    /**
     * Records with teams but no tenant link (tasks, meetings, emails, ...)
     * whose every team is one of this tenant's. A record also shared with
     * another team stays.
     */
    private function removeTeamOnlyRecords(Entity $record, string $lease): bool
    {
        $state = $this->state($record);
        $teamIds = $state['teamIds'] ?? [];

        if ($teamIds === []) {
            return true;
        }

        $cursor = $state['shared'] ?? ['entityType' => '', 'entityId' => ''];

        $rows = $this->entityManager
            ->getRDBRepository('EntityTeam')
            ->select(['entityType', 'entityId'])
            ->where([
                'teamId' => $teamIds,
                'OR' => [
                    ['entityType>' => $cursor['entityType']],
                    ['entityType' => $cursor['entityType'], 'entityId>' => $cursor['entityId']],
                ],
            ])
            ->order('entityType')
            ->order('entityId')
            ->limit(0, self::BATCH)
            ->find();

        $pairs = [];

        foreach ($rows as $row) {
            $pairs[$row->get('entityType') . "\0" . $row->get('entityId')] = [(string) $row->get('entityType'), (string) $row->get('entityId')];
        }

        if ($pairs === []) {
            return true;
        }

        $skip = ['ChatwootAccount', 'Team', 'User', 'Tenant', self::ENTITY_TYPE];

        foreach ($pairs as [$entityType, $entityId]) {
            $state['shared'] = ['entityType' => $entityType, 'entityId' => $entityId];

            if (
                in_array($entityType, $skip, true) ||
                $this->map->isRetained($entityType) ||
                !$this->entityManager->hasRepository($entityType) ||
                $this->sharedWithOtherTeams($entityType, $entityId, $teamIds)
            ) {
                continue;
            }

            $entity = $this->entityManager->getEntityById($entityType, $entityId);

            if ($entity) {
                $this->removeCounted($state, $entity, []);
            }
        }

        $this->persistState($record, $lease, $state);

        return false;
    }

    /**
     * @param list<string> $teamIds
     */
    private function sharedWithOtherTeams(string $entityType, string $entityId, array $teamIds): bool
    {
        return (bool) $this->entityManager
            ->getRDBRepository('EntityTeam')
            ->select(['id'])
            ->where(['entityType' => $entityType, 'entityId' => $entityId, 'teamId!=' => $teamIds])
            ->findOne();
    }

    // Step 7 ------------------------------------------------------------------

    private function removeTeams(Entity $record, string $lease): bool
    {
        $state = $this->state($record);
        $tenantId = (string) $record->get('tenantId');

        foreach ($state['teamIds'] ?? [] as $teamId) {
            $team = $this->entityManager->getEntityById('Team', $teamId);

            if ($team && !$this->usedByOtherTenant($teamId, $tenantId)) {
                $this->removeCounted($state, $team, []);
            }
        }

        $this->persistState($record, $lease, $state);

        return true;
    }

    // Step 8 ------------------------------------------------------------------

    private function removeUsers(Entity $record, string $lease): bool
    {
        $state = $this->state($record);
        $tenantId = (string) $record->get('tenantId');
        $userIds = $state['userIds'] ?? [];

        foreach ($userIds as $userId) {
            $user = $this->entityManager->getEntityById(User::ENTITY_TYPE, $userId);

            if (!$user instanceof User) {
                continue;
            }

            // Joined another workspace while this one was being removed.
            if (array_diff($this->userTenants->resolveTenantIds($user), [$tenantId]) !== []) {
                $user->set('isActive', true);
                $this->entityManager->saveEntity($user);

                continue;
            }

            $this->removeCounted($state, $user, []);
        }

        $chatwootUserIds = $state['chatwootUserIds'] ?? [];

        if ($userIds !== []) {
            foreach ($this->entityManager
                ->getRDBRepository('ChatwootUser')
                ->select(['id'])
                ->where(['assignedUserId' => $userIds])
                ->find() as $chatwootUser) {
                $chatwootUserIds[] = (string) $chatwootUser->getId();
            }
        }

        foreach (array_unique($chatwootUserIds) as $chatwootUserId) {
            $chatwootUser = $this->entityManager->getEntityById('ChatwootUser', $chatwootUserId);

            if (
                !$chatwootUser ||
                // Retired AI identities are kept: their emails are never reissued.
                $this->identities->forUser($chatwootUserId) ||
                // The Chatwoot user of someone who keeps their CRM user.
                ($chatwootUser->get('assignedUserId') && !in_array($chatwootUser->get('assignedUserId'), $userIds, true)) ||
                $this->entityManager->getRDBRepository('ChatwootAccountUserMembership')
                    ->where(['chatwootUserId' => $chatwootUserId])->findOne()
            ) {
                continue;
            }

            // Deletes the Chatwoot user too (ChatwootUser\DeleteFromChatwootLegacy).
            $this->removeCounted($state, $chatwootUser, []);
        }

        $this->persistState($record, $lease, $state);

        return true;
    }

    // Step 9 ------------------------------------------------------------------

    private function removeTenant(Entity $record): bool
    {
        $tenant = $this->entityManager->getEntityById('Tenant', (string) $record->get('tenantId'));

        if ($tenant) {
            $this->entityManager->removeEntity($tenant, [self::REMOVE_OPTION => true]);
        }

        return true;
    }

    // Helpers -----------------------------------------------------------------

    /**
     * Remove up to one batch of `$entityType` matching `$where`, after the
     * cursor kept under `$scope`. Returns true when it removed a batch (the
     * caller returns and is called again), false once none is left.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $where
     * @param array<string, mixed> $options
     */
    private function removeBatch(Entity $record, string $lease, array &$state, string $entityType, array $where, array $options, string $scope): bool
    {
        if (!$this->entityManager->hasRepository($entityType)) {
            return false;
        }

        $key = "{$scope}:{$entityType}";

        // Finished: what is left was kept by a remove hook, and is not retried.
        if (in_array($key, $state['finished'] ?? [], true)) {
            return false;
        }

        $lastId = ($state['cursor']['key'] ?? null) === $key ? (string) $state['cursor']['lastId'] : '';

        $batch = $this->entityManager
            ->getRDBRepository($entityType)
            ->where($where + ['id>' => $lastId])
            ->order('id')
            ->limit(0, self::BATCH)
            ->find();

        $found = false;

        foreach ($batch as $entity) {
            $found = true;
            $this->removeCounted($state, $entity, $options);
            $state['cursor'] = ['key' => $key, 'lastId' => (string) $entity->getId()];
        }

        if (!$found) {
            $state['finished'][] = $key;

            return false;
        }

        $this->persistState($record, $lease, $state);

        return true;
    }

    /**
     * Remove, counting the result. A remove hook that refuses the record
     * (it must be retained, or still has dependants) keeps it; any other
     * error stops the step so it retries.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     */
    private function removeCounted(array &$state, Entity $entity, array $options): void
    {
        $entityType = $entity->getEntityType();

        try {
            $this->entityManager->removeEntity($entity, $options);
            $state['removed'][$entityType] = ($state['removed'][$entityType] ?? 0) + 1;
        } catch (Forbidden | Conflict | BadRequest $e) {
            $state['kept'][$entityType] = ($state['kept'][$entityType] ?? 0) + 1;
            $this->log->info("SelfService offboarding: kept {$entityType} {$entity->getId()}: " . $e->getMessage());
        }
    }

    /**
     * Base, admin and other user teams, unless another live tenant uses them.
     *
     * @return list<string>
     */
    private function tenantTeamIds(Entity $tenant): array
    {
        $tenantId = (string) $tenant->getId();
        $ids = array_filter([(string) $tenant->get('baseUserTeamId'), (string) $this->adminTeams->findAdminTeamId($tenant)]);

        foreach ($this->entityManager->getRDBRepository('Tenant')->getRelation($tenant, 'otherUserTeams')->find() as $team) {
            $ids[] = (string) $team->getId();
        }

        foreach ($this->entityManager
            ->getRDBRepository('Team')
            ->select(['id'])
            ->where(['provisioningKey' => ["tenant-base:{$tenantId}", "tenant-admin:{$tenantId}"]])
            ->find() as $team) {
            $ids[] = (string) $team->getId();
        }

        return array_values(array_filter(
            array_unique($ids),
            fn (string $teamId) => !$this->usedByOtherTenant($teamId, $tenantId),
        ));
    }

    private function usedByOtherTenant(string $teamId, string $tenantId): bool
    {
        $repository = $this->entityManager->getRDBRepository('Tenant');

        if ($repository->where(['baseUserTeamId' => $teamId, 'id!=' => $tenantId])->findOne()) {
            return true;
        }

        $team = $this->entityManager->getEntityById('Team', $teamId);

        return $team !== null && (bool) $this->entityManager
            ->getRDBRepository('Team')
            ->getRelation($team, 'tenantsAsOtherUserTeam')
            ->where(['id!=' => $tenantId])
            ->findOne();
    }

    /**
     * @param list<string> $teamIds
     * @return list<string>
     */
    private function tenantUserIds(Entity $tenant, array $teamIds): array
    {
        $ids = [];

        foreach ($this->entityManager->getRDBRepository('Tenant')->getRelation($tenant, 'users')->find() as $user) {
            $ids[] = (string) $user->getId();
        }

        foreach ($teamIds as $teamId) {
            $team = $this->entityManager->getEntityById('Team', $teamId);

            if (!$team) {
                continue;
            }

            foreach ($this->entityManager->getRDBRepository('Team')->getRelation($team, 'users')->find() as $user) {
                $ids[] = (string) $user->getId();
            }
        }

        return array_values(array_unique($ids));
    }

    private function isManagedAgentUser(string $userId): bool
    {
        return (bool) $this->entityManager
            ->getRDBRepository('ChatwootMachineIdentity')
            ->where(['crmUserId' => $userId])
            ->findOne();
    }

    /**
     * @return list<string>
     */
    private function chatwootAccountIds(string $tenantId, bool $withDeleted = false): array
    {
        $builder = $this->entityManager->getQueryBuilder()->select(['id'])->from('ChatwootAccount')->where(['tenantId' => $tenantId]);

        if ($withDeleted) {
            $builder->withDeleted();
        }

        $ids = [];

        foreach ($this->entityManager->getRDBRepository('ChatwootAccount')->clone($builder->build())->find() as $account) {
            $ids[] = (string) $account->getId();
        }

        return $ids;
    }

    private function hasOpenSubscription(string $tenantId): bool
    {
        return (bool) $this->entityManager
            ->getRDBRepository('CreditBillingObligation')
            ->where(['tenantId' => $tenantId, 'state' => self::OPEN_SUBSCRIPTION_STATES])
            ->findOne();
    }

    private function findTenant(string $tenantId): ?Entity
    {
        $query = $this->entityManager->getQueryBuilder()->select()->from('Tenant')->where(['id' => $tenantId])->withDeleted()->build();

        return $this->entityManager->getRDBRepository('Tenant')->clone($query)->findOne();
    }

    private function tenant(Entity $record): Entity
    {
        return $this->findTenant((string) $record->get('tenantId'))
            ?? throw new OffboardingFailure('Tenant ' . $record->get('tenantId') . ' no longer exists.');
    }

    private function retryIfFailed(Entity $record): Entity
    {
        if ($record->get('status') !== self::STATUS_FAILED) {
            return $record;
        }

        $this->entityManager->getQueryExecutor()->execute(
            $this->entityManager->getQueryBuilder()->update()->in(self::ENTITY_TYPE)
                ->set(['attempts' => 0, 'nextAttemptAt' => $this->at(0), 'modifiedAt' => $this->at(0)])
                ->where(['id' => $record->getId(), 'status' => self::STATUS_FAILED])
                ->build()
        );

        return $this->entityManager->getEntityById(self::ENTITY_TYPE, (string) $record->getId()) ?? $record;
    }

    /**
     * @return array<string, mixed>
     */
    private function state(Entity $record): array
    {
        $state = $record->get('state');

        if (is_string($state)) {
            return json_decode($state, true) ?: [];
        }

        return json_decode((string) json_encode($state ?? []), true) ?: [];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function persistState(Entity $record, string $lease, array $state): void
    {
        $this->persist($record, $lease, ['state' => $state, 'leaseUntil' => $this->at(self::LEASE_SECONDS)]);
    }

    /**
     * Take the lease unless a live one is held. Completed offboardings are
     * never reclaimed.
     */
    private function claim(string $id): ?string
    {
        $now = $this->at(0);
        $token = bin2hex(random_bytes(16));

        $statement = $this->entityManager->getQueryExecutor()->execute(
            $this->entityManager
                ->getQueryBuilder()
                ->update()
                ->in(self::ENTITY_TYPE)
                ->set([
                    'leaseToken' => $token,
                    'leaseUntil' => $this->at(self::LEASE_SECONDS),
                    'status' => self::STATUS_RUNNING,
                    'modifiedAt' => $now,
                ])
                ->where([
                    'id' => $id,
                    'status!=' => self::STATUS_COMPLETED,
                    'OR' => [
                        ['leaseUntil' => null],
                        ['leaseUntil<' => $now],
                    ],
                ])
                ->build()
        );

        return $statement->rowCount() === 1 ? $token : null;
    }

    /**
     * Write while holding the lease, conditioned on the revision.
     *
     * @param array<string, mixed> $values
     * @throws LeaseLost
     */
    private function persist(Entity $record, string $lease, array $values): void
    {
        $revision = (int) $record->get('revision');
        $values['revision'] = $revision + 1;
        $values['modifiedAt'] = $this->at(0);

        $columns = $values;

        if (array_key_exists('state', $columns)) {
            $columns['state'] = json_encode((object) $columns['state']);
        }

        $statement = $this->entityManager->getQueryExecutor()->execute(
            $this->entityManager
                ->getQueryBuilder()
                ->update()
                ->in(self::ENTITY_TYPE)
                ->set($columns)
                ->where([
                    'id' => $record->getId(),
                    'leaseToken' => $lease,
                    'revision' => $revision,
                ])
                ->build()
        );

        if ($statement->rowCount() !== 1) {
            throw new LeaseLost();
        }

        if (array_key_exists('state', $values)) {
            $values['state'] = json_decode((string) json_encode((object) $values['state']));
        }

        $record->set($values);
    }

    private function fail(Entity $record, string $lease, Throwable $error, bool $retryable): void
    {
        $attempts = (int) $record->get('attempts') + 1;
        $retry = $retryable && $attempts < self::MAX_ATTEMPTS;
        $delay = min(60 * 2 ** ($attempts - 1), self::MAX_RETRY_DELAY_SECONDS);

        $this->log->error(sprintf(
            'SelfService: offboarding %s failed at step %s (attempt %d%s): %s',
            $record->getId(),
            $record->get('step'),
            $attempts,
            $retry ? ", retry in {$delay}s" : ', no retry',
            $error->getMessage(),
        ));

        try {
            $this->persist($record, $lease, [
                'status' => self::STATUS_FAILED,
                'attempts' => $attempts,
                'lastError' => mb_substr($error::class . ': ' . $error->getMessage(), 0, 2000),
                'nextAttemptAt' => $retry ? $this->at($delay) : null,
                'leaseToken' => null,
                'leaseUntil' => null,
            ]);
        } catch (LeaseLost) {
            // The worker that took over owns the record now.
        }
    }

    private function at(int $secondsFromNow): string
    {
        return gmdate('Y-m-d H:i:s', time() + $secondsFromNow);
    }
}
