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

/** Links agents created in Chatwoot to an existing CRM identity. */
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
        $remoteId = (int) ($agent['id'] ?? 0);
        $platformId = $account->get('platformId');
        if (!$remoteId || !$platformId || !$account instanceof CoreEntity) {
            return null;
        }

        $repository = $this->entityManager->getRDBRepository('ChatwootUser');
        $existing = $repository->where([
            'chatwootUserId' => $remoteId,
            'platformId' => $platformId,
        ])->findOne();
        if ($existing) {
            return $existing;
        }

        $user = $this->findCrmUser((string) ($agent['email'] ?? ''));
        if (!$user || !$user->isActive() || !$this->sharesAccountTeam($account, $user)) {
            return null;
        }

        // An existing link must be corrected explicitly, never reassigned by email.
        if ($repository->where(['assignedUserId' => $user->getId(), 'platformId' => $platformId])->findOne()) {
            $this->log->warning("ChatwootAgentIdentity: Conflicting identity for CRM user {$user->getId()}.");
            return null;
        }

        // The agent already exists remotely. Import without provisioning a user
        // or inventing a password that could overwrite their chosen password.
        return $this->entityManager->createEntity('ChatwootUser', [
            'name' => $user->get('name') ?: $user->getUserName(),
            'emailAddress' => $user->get('emailAddress'),
            'assignedUserId' => $user->getId(),
            'platformId' => $platformId,
            'chatwootUserId' => $remoteId,
            'teamsIds' => $account->getLinkMultipleIdList('teams'),
        ], ['silent' => true]);
    }

    /** Resolve the first password reset even if the scheduled import has not run yet. */
    public function importForPasswordSync(int $remoteId, string $email, ?string $installationUrl): void
    {
        $user = $this->findCrmUser($email);
        if (!$user || !$user->isActive() || !$installationUrl) {
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
                if (!$this->sharesAccountTeam($account, $user) || !$account->get('apiKey') ||
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
                    if ($identity && $identity->get('assignedUserId') === $user->getId()) {
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
