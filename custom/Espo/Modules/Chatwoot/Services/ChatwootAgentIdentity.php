<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2026 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Provisions CRM identities for agents returned by the authenticated Account API. */
class ChatwootAgentIdentity
{
    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private ChatwootAccountUserMembershipService $membershipService,
        private Log $log
    ) {}

    public function findCrmUser(string $email): ?User
    {
        $email = strtolower(trim($email));
        if (ManagedIdentityPolicy::isReservedEmail($email)) return null;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $users = $this->entityManager->getRDBRepositoryByClass(User::class)
            ->where(['emailAddress' => $email, 'type' => User::TYPE_REGULAR])
            ->find();

        $matches = [];
        foreach ($users as $user) {
            // Email queries can also match secondary addresses. Authentication
            // and password sync must agree on the user's primary address.
            if (!$user->isSuperAdmin() && strtolower((string) $user->get('emailAddress')) === $email) {
                $matches[] = $user;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @param array<string, mixed> $agent Profile from the authenticated Account API. */
    public function importForAccount(Entity $account, array $agent): ?Entity
    {
        // Reserved identities are only materialized by trusted provisioning, never email import.
        if (ManagedIdentityPolicy::isReservedEmail($agent['email'] ?? null)) return null;
        $remoteId = (int) ($agent['id'] ?? 0);
        $platformId = $account->get('platformId');
        if (!$remoteId || !$platformId || !$account instanceof CoreEntity) {
            return null;
        }

        // The scheduled import and an invitation/password reset can arrive at
        // the same time. Serialize resolution before creating either identity.
        return $this->entityManager->getTransactionManager()->run(function () use ($account, $agent, $platformId) {
            $platform = $this->entityManager->getRDBRepository('ChatwootPlatform')
                ->where(['id' => $platformId])->select(['id'])->forUpdate()->findOne();

            return $platform ? $this->importIdentity($account, $agent) : null;
        });
    }

    /** @param array<string, mixed> $agent */
    private function importIdentity(CoreEntity $account, array $agent): ?Entity
    {
        $remoteId = (int) $agent['id'];
        $platformId = $account->get('platformId');
        $repository = $this->entityManager->getRDBRepository('ChatwootUser');
        $existing = $repository->where([
            'chatwootUserId' => $remoteId,
            'platformId' => $platformId,
        ])->findOne();
        if ($existing) {
            if ($this->entityManager->getRDBRepository('ChatwootMachineIdentity')
                ->where(['chatwootUserId' => $existing->getId()])->findOne()) return null;
            return $existing;
        }

        $user = $this->findCrmUser((string) ($agent['email'] ?? '')) ?? $this->provisionCrmUser($account, $agent);
        if (!$user || !$user->isActive() || !$this->sharesAccountTeam($account, $user)) {
            return null;
        }

        // An existing link must be corrected explicitly, never reassigned by email.
        if ($repository->where(['assignedUserId' => $user->getId(), 'platformId' => $platformId])->findOne()) {
            $this->log->warning("ChatwootAgentIdentity: Conflicting identity for CRM user {$user->getId()}.");
            return null;
        }

        // The agent already exists remotely. Never push a generated password
        // back to Chatwoot or replace an existing CRM user's chosen password.
        return $this->entityManager->createEntity('ChatwootUser', [
            'name' => $user->get('name') ?: $user->getUserName(),
            'emailAddress' => $user->get('emailAddress'),
            'assignedUserId' => $user->getId(),
            'platformId' => $platformId,
            'chatwootUserId' => $remoteId,
            'teamsIds' => $account->getLinkMultipleIdList('teams'),
        ], ['silent' => true]);
    }

    /** @param array<string, mixed> $agent */
    private function provisionCrmUser(CoreEntity $account, array $agent): ?User
    {
        $email = strtolower(trim((string) ($agent['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $account->get('status') !== 'active') {
            return null;
        }

        // An email collision (inactive/admin/ambiguous identity included) needs
        // explicit reconciliation, not a second user or an automatic reactivation.
        if ($this->entityManager->getRDBRepository('User')->where([
            'OR' => [['userName' => $email], ['emailAddress' => $email]],
        ])->findOne()) {
            return null;
        }

        $tenantId = $account->get('tenantId');
        $tenant = $tenantId ? $this->entityManager->getEntityById('Tenant', $tenantId) : null;
        $baseTeamId = $tenant?->get('baseUserTeamId');
        $teamIds = $account->getLinkMultipleIdList('teams');
        if (!$baseTeamId || !in_array($baseTeamId, $teamIds, true)) {
            $this->log->warning("ChatwootAgentIdentity: Cannot provision agent for account {$account->getId()} without its tenant base team.");
            return null;
        }

        $name = trim((string) ($agent['name'] ?? '')) ?: $email;
        $parts = preg_split('/\s+/u', $name, 2);
        /** @var User $user */
        $user = $this->entityManager->createEntity(User::ENTITY_TYPE, [
            'userName' => $email,
            'emailAddress' => $email,
            'firstName' => $parts[0],
            'lastName' => $parts[1] ?? '',
            'type' => User::TYPE_REGULAR,
            'isActive' => true,
            'defaultTeamId' => $baseTeamId,
            'teamsIds' => [$baseTeamId],
            // The invitation/reset callback supplies the chosen password. The
            // scheduled import must not invent a usable/shared login password.
            'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT),
        ], ['silent' => true, 'skipChatwootProvisioning' => true]);

        $this->log->info("ChatwootAgentIdentity: Provisioned CRM user {$user->getId()} for Chatwoot user {$agent['id']} in account {$account->getId()}.");

        return $user;
    }

    /** Resolve the first password reset even if the scheduled import has not run yet. */
    public function importForPasswordSync(int $remoteId, string $email, ?string $installationUrl): void
    {
        $user = $this->findCrmUser($email);
        if (($user && !$user->isActive()) || !$installationUrl) {
            return;
        }

        $platforms = $this->entityManager->getRDBRepository('ChatwootPlatform')->find();
        foreach ($platforms as $platform) {
            if (rtrim((string) $platform->get('frontendUrl'), '/') !== rtrim($installationUrl, '/')) {
                continue;
            }

            $accounts = $this->entityManager->getRDBRepository('ChatwootAccount')
                ->where(['platformId' => $platform->getId(), 'status' => 'active'])
                ->find();

            foreach ($accounts as $account) {
                if (($user && !$this->sharesAccountTeam($account, $user)) || !$account->get('apiKey') ||
                    !$account->get('chatwootAccountId') || !$platform->get('backendUrl')) {
                    continue;
                }

                try {
                    $agents = $this->apiClient->listAgents(
                        $platform->get('backendUrl'),
                        $account->get('apiKey'),
                        (int) $account->get('chatwootAccountId')
                    );
                } catch (\Throwable $e) {
                    $this->log->warning("ChatwootAgentIdentity: Could not read agents for account {$account->getId()}.");
                    continue;
                }

                foreach ($agents as $agent) {
                    if (!is_array($agent) || (int) ($agent['id'] ?? 0) !== $remoteId ||
                        strtolower((string) ($agent['email'] ?? '')) !== strtolower($email)) {
                        continue;
                    }

                    $identity = $this->importForAccount($account, $agent);
                    if ($identity && (!$user || $identity->get('assignedUserId') === $user->getId())) {
                        $this->membershipService->upsertMembership(
                            $account->getId(),
                            $identity->getId(),
                            $agent['role'] ?? 'agent'
                        );
                    }

                    return;
                }
            }
        }
    }

    private function sharesAccountTeam(Entity $account, User $user): bool
    {
        return $account instanceof CoreEntity && (bool) array_intersect(
            $account->getLinkMultipleIdList('teams'),
            $user->getLinkMultipleIdList('teams')
        );
    }
}
