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

namespace Espo\Modules\FeatureSelfService\Tools\Provisioning;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Id\RecordIdGenerator;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ChatwootAccountMembershipOrchestrator;
use Espo\Modules\FeatureSelfService\Tools\Policy\SelfServicePolicy;
use Espo\Modules\Global\Tools\Tenant\TenantAdminTeamProvisioner;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use RuntimeException;
use Throwable;

/**
 * Creates a self-service workspace: Tenant (with its base and admin teams),
 * the trial AI cap, the owner's tenant membership and admin team, the
 * ChatwootAccount, and the owner's Chatwoot administrator membership.
 *
 * Progress lives in one SelfServiceProvisioning record:
 *
 * - The ids of the Tenant and ChatwootAccount are chosen when the record is
 *   made. A retry after a crash or lost reply finds what it created instead of
 *   creating it twice; the ChatwootAccount hook resumes its remote resources
 *   for the same id.
 * - Each step checks its own result, so rerunning a finished step is a no-op.
 * - One worker at a time holds a lease. Every write is conditioned on the
 *   lease token and a revision, so a worker whose lease expired stops instead
 *   of overwriting the one that took over.
 * - The self-service policy is checked before every step. When it is off the
 *   record pauses and resumes later where it stopped.
 */
class Provisioner
{
    public const ENTITY_TYPE = 'SelfServiceProvisioning';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    private const STEP_NEXT = [
        'tenant' => 'aiAllowance',
        'aiAllowance' => 'owner',
        'owner' => 'chatwootAccount',
        'chatwootAccount' => 'ownerMembership',
        'ownerMembership' => 'done',
    ];

    /**
     * The trial's AI: 500 credits, about 100 AI conversations. The legacy
     * budget (Chatwoot AiBudget) counts AI runs, and a conversation takes
     * 2-3 of them. Override with config `selfServiceTrialAiRuns`.
     */
    private const TRIAL_AI_RUNS = 250;

    private const LEASE_SECONDS = 600;
    private const MAX_ATTEMPTS = 8;
    private const PAUSE_RECHECK_SECONDS = 60;
    private const MAX_RETRY_DELAY_SECONDS = 1800;

    public function __construct(
        private EntityManager $entityManager,
        private SelfServicePolicy $policy,
        private RecordIdGenerator $idGenerator,
        private WorkspaceSlug $slugs,
        private TenantAdminTeamProvisioner $adminTeams,
        private ChatwootAccountMembershipOrchestrator $memberships,
        private Config $config,
        private Log $log,
    ) {}

    /**
     * Record a signup's provisioning, or return the one already recorded for
     * its dedupe key. Nothing outside the record is created until run().
     *
     * @throws Conflict The dedupe key was used with different settings.
     */
    public function start(ProvisioningRequest $request): Entity
    {
        $this->policy->assertEnabled();

        $existing = $this->findByKey($request->dedupeKey);

        if ($existing) {
            return $this->matching($existing, $request);
        }

        try {
            return $this->entityManager->createEntity(self::ENTITY_TYPE, [
                'dedupeKey' => $request->dedupeKey,
                'inputHash' => $request->hash(),
                'input' => (object) $request->toArray(),
                'status' => self::STATUS_PENDING,
                'step' => 'tenant',
                'tenantId' => $this->idGenerator->generate(),
                'ownerId' => $request->ownerUserId,
                'chatwootAccountId' => $this->idGenerator->generate(),
                'nextAttemptAt' => $this->at(0),
            ], ['silent' => true]);
        } catch (Throwable $e) {
            // A concurrent start() inserted the same key first.
            $existing = $this->findByKey($request->dedupeKey);

            if (!$existing) {
                throw $e;
            }

            return $this->matching($existing, $request);
        }
    }

    /**
     * Run the remaining steps. Safe to call again and from several workers:
     * the one holding the lease runs, the others get the record as it is.
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
            $this->log->warning("SelfService: lost the lease on provisioning {$id}; another worker continues it.");
        } catch (ProvisioningFailure $e) {
            $this->fail($record, $lease, $e, false);
        } catch (Throwable $e) {
            $this->fail($record, $lease, $e, true);
        }

        return $this->entityManager->getEntityById(self::ENTITY_TYPE, $id);
    }

    /**
     * Provisionings a worker should run now: due ones, and running ones whose
     * worker stopped without releasing its lease.
     *
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
                    [
                        'status' => [self::STATUS_PENDING, self::STATUS_PAUSED, self::STATUS_FAILED],
                        'nextAttemptAt<=' => $now,
                    ],
                    [
                        'status' => self::STATUS_RUNNING,
                        'leaseUntil<' => $now,
                    ],
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
        while (($step = (string) $record->get('step')) !== 'done') {
            if (!$this->policy->isEnabled()) {
                $this->persist($record, $lease, [
                    'status' => self::STATUS_PAUSED,
                    'nextAttemptAt' => $this->at(self::PAUSE_RECHECK_SECONDS),
                    'leaseToken' => null,
                    'leaseUntil' => null,
                ]);

                return;
            }

            match ($step) {
                'tenant' => $this->ensureTenant($record, $lease),
                'aiAllowance' => $this->ensureAiAllowance($record),
                'owner' => $this->ensureOwner($record),
                'chatwootAccount' => $this->ensureChatwootAccount($record),
                'ownerMembership' => $this->ensureOwnerMembership($record),
                default => throw new ProvisioningFailure("Unknown step '{$step}'."),
            };

            $this->persist($record, $lease, [
                'step' => self::STEP_NEXT[$step],
                'leaseUntil' => $this->at(self::LEASE_SECONDS),
            ]);
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

    private function ensureTenant(Entity $record, string $lease): void
    {
        $input = $this->input($record);
        $tenantId = (string) $record->get('tenantId');
        $tenant = $this->entityManager->getEntityById('Tenant', $tenantId);

        if (!$tenant) {
            $slug = $record->get('slug') ?: $this->slugs->available($input['workspaceName']);

            if ($slug !== $record->get('slug')) {
                $this->persist($record, $lease, ['slug' => $slug]);
            }

            try {
                $tenant = $this->entityManager->createEntity('Tenant', [
                    'id' => $tenantId,
                    'name' => $input['workspaceName'],
                    'slug' => $slug,
                    'language' => $input['language'],
                    'timeZone' => $input['timeZone'],
                ]);
            } catch (Throwable $e) {
                // Inserted before a hook failed: continue with it. Not inserted:
                // most likely a concurrent signup took the slug, so the retry
                // picks another.
                $tenant = $this->entityManager->getEntityById('Tenant', $tenantId);

                if (!$tenant) {
                    $this->persist($record, $lease, ['slug' => null]);

                    throw $e;
                }
            }
        }

        if (!$tenant->get('baseUserTeamId')) {
            // CreateBaseTeam runs on every save while the link is empty.
            $this->entityManager->saveEntity($tenant);
            $tenant = $this->entityManager->getEntityById('Tenant', $tenantId);
        }

        if (!$tenant?->get('baseUserTeamId')) {
            throw new RuntimeException("Tenant {$tenantId} has no base team. Has the `tenant` Role been seeded?");
        }

        if ($this->adminTeams->ensureForTenant($tenant) === null) {
            throw new RuntimeException("Tenant {$tenantId} has no admin team. Has the `tenant-admin` Role been seeded?");
        }
    }

    /**
     * Without a billing rate AiBudget lets a tenant's AI run unmetered
     * (overage `allow`). A new workspace starts capped at the trial allowance
     * and blocked beyond it.
     */
    private function ensureAiAllowance(Entity $record): void
    {
        $tenantId = (string) $record->get('tenantId');

        if ($this->entityManager->getRDBRepository('TenantAiBillingRate')->where(['tenantId' => $tenantId])->findOne()) {
            return;
        }

        $this->entityManager->createEntity('TenantAiBillingRate', [
            'name' => 'Self-service trial',
            'tenantId' => $tenantId,
            // Yesterday in UTC, so the rate already covers "today" in any time zone.
            'effectiveFrom' => gmdate('Y-m-d', time() - 86400),
            'billingModel' => 'credit',
            'overagePolicy' => 'block',
            'planIncludedCredits' => (int) ($this->config->get('selfServiceTrialAiRuns') ?? self::TRIAL_AI_RUNS),
        ]);
    }

    private function ensureOwner(Entity $record): void
    {
        $tenant = $this->tenant($record);
        $owner = $this->owner($record);
        $baseTeamId = (string) $tenant->get('baseUserTeamId');
        $adminTeamId = $this->adminTeams->findAdminTeamId($tenant)
            ?? throw new RuntimeException('Tenant ' . $tenant->getId() . ' has no admin team.');

        // Linking the tenant adds its base team (Global SyncUserTeams).
        $tenantUsers = $this->entityManager->getRDBRepository('Tenant')->getRelation($tenant, 'users');

        if (!$tenantUsers->isRelatedById((string) $owner->getId())) {
            $tenantUsers->relateById((string) $owner->getId());
        }

        $ownerTeams = $this->entityManager->getRDBRepository('User')->getRelation($owner, 'teams');

        foreach ([$baseTeamId, $adminTeamId] as $teamId) {
            if (!$ownerTeams->isRelatedById($teamId)) {
                $ownerTeams->relateById($teamId);
            }
        }

        $owner = $this->owner($record);

        if (!$owner->get('defaultTeamId')) {
            $owner->set('defaultTeamId', $baseTeamId);
            $this->entityManager->saveEntity($owner);
        }
    }

    private function ensureChatwootAccount(Entity $record): void
    {
        $tenant = $this->tenant($record);
        $accountId = (string) $record->get('chatwootAccountId');
        $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);

        if (!$account) {
            $input = $this->input($record);

            // When this fails, the ChatwootAccount hook keeps what it already
            // created in Chatwoot, and the retry reuses it for the same id.
            $account = $this->entityManager->createEntity('ChatwootAccount', [
                'id' => $accountId,
                'name' => $input['workspaceName'],
                'platformId' => $this->platformId(),
                'tenantId' => $tenant->getId(),
                'teamsIds' => [(string) $tenant->get('baseUserTeamId')],
                'locale' => $input['language'] === 'pt_BR' ? 'pt_BR' : 'en',
                'status' => 'active',
            ]);
        }

        if (!$account->get('chatwootAccountId') || !$account->get('apiKey')) {
            throw new ProvisioningFailure(
                "ChatwootAccount {$accountId} exists without its Chatwoot account id or API key."
            );
        }
    }

    private function ensureOwnerMembership(Entity $record): void
    {
        $accountId = (string) $record->get('chatwootAccountId');
        $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId)
            ?? throw new RuntimeException("ChatwootAccount {$accountId} not found.");

        $this->memberships->ensureUserMembership($account, $this->owner($record), 'administrator');
    }

    private function tenant(Entity $record): Entity
    {
        $tenantId = (string) $record->get('tenantId');

        return $this->entityManager->getEntityById('Tenant', $tenantId)
            ?? throw new RuntimeException("Tenant {$tenantId} not found.");
    }

    /**
     * Workspace owners are regular users with workspace-scoped admin rights,
     * never instance administrators.
     */
    private function owner(Entity $record): User
    {
        $owner = $this->entityManager->getEntityById('User', (string) $record->get('ownerId'));

        if (!$owner instanceof User || !$owner->isActive() || $owner->getType() !== User::TYPE_REGULAR) {
            throw new ProvisioningFailure('The owner must be an active regular user.');
        }

        return $owner;
    }

    private function platformId(): string
    {
        $configured = $this->config->get('selfServiceChatwootPlatformId');

        if (is_string($configured) && $configured !== '') {
            if (!$this->entityManager->getEntityById('ChatwootPlatform', $configured)) {
                throw new ProvisioningFailure("ChatwootPlatform {$configured} (selfServiceChatwootPlatformId) not found.");
            }

            return $configured;
        }

        $ids = [];

        foreach ($this->entityManager->getRDBRepository('ChatwootPlatform')->select(['id'])->limit(0, 2)->find() as $platform) {
            $ids[] = (string) $platform->getId();
        }

        if (count($ids) !== 1) {
            throw new ProvisioningFailure('Set selfServiceChatwootPlatformId: there is not exactly one Chatwoot platform.');
        }

        return $ids[0];
    }

    /**
     * @return array{ownerUserId: string, workspaceName: string, language: string, timeZone: string}
     */
    private function input(Entity $record): array
    {
        /** @var array{ownerUserId: string, workspaceName: string, language: string, timeZone: string} */
        return (array) $record->get('input');
    }

    private function findByKey(string $dedupeKey): ?Entity
    {
        return $this->entityManager
            ->getRDBRepository(self::ENTITY_TYPE)
            ->where(['dedupeKey' => $dedupeKey])
            ->findOne();
    }

    /**
     * @throws Conflict
     */
    private function matching(Entity $existing, ProvisioningRequest $request): Entity
    {
        if ($existing->get('inputHash') !== $request->hash()) {
            throw new Conflict('This signup was already submitted with different workspace settings.');
        }

        return $existing;
    }

    /**
     * Take the lease unless a live one is held. Completed provisionings are
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
     * Write while holding the lease. The revision always changes, so a
     * matching row always counts as affected.
     *
     * @param array<string, mixed> $values
     * @throws LeaseLost
     */
    private function persist(Entity $record, string $lease, array $values): void
    {
        $revision = (int) $record->get('revision');
        $values['revision'] = $revision + 1;
        $values['modifiedAt'] = $this->at(0);

        $statement = $this->entityManager->getQueryExecutor()->execute(
            $this->entityManager
                ->getQueryBuilder()
                ->update()
                ->in(self::ENTITY_TYPE)
                ->set($values)
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

        $record->set($values);
    }

    private function fail(Entity $record, string $lease, Throwable $error, bool $retryable): void
    {
        $attempts = (int) $record->get('attempts') + 1;
        $retry = $retryable && $attempts < self::MAX_ATTEMPTS;
        $delay = min(60 * 2 ** ($attempts - 1), self::MAX_RETRY_DELAY_SECONDS);

        $this->log->error(sprintf(
            'SelfService: provisioning %s failed at step %s (attempt %d%s): %s',
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
