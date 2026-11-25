<?php

declare(strict_types=1);

/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureMcp\Tools\Chat;

use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Who the person is in Chat, per workspace, with the rules the AI Session uses (backend `chat-human.ts` and
 * `chat-delegation.ts`): their ChatwootUser on the workspace's platform with a human (not AI) membership in it, exactly
 * one, with a token (the CRM keeps it; the platform token fetches it when missing, as OpportunityInboxFilter does);
 * and before acting, Chat's /profile must answer with that same user and the workspace active in their accounts. Chat
 * then applies its own rules (inboxes, teams, custom roles) to everything the token does.
 */
class ChatIdentity
{
    /** @var ?list<array{accountId: int, workspace: string, chatwootUserId: string}> */
    private ?array $workspaces = null;

    /** @var array<int, ChatAccess> */
    private array $verified = [];

    public function __construct(
        private EntityManager $entityManager,
        private User $user,
        private ChatwootApiClient $chatwoot,
    ) {}

    /**
     * Workspaces the person has a Chat identity in (Chat account ids), unverified: enough to list them.
     *
     * @return list<array{accountId: int, workspace: string}>
     */
    public function workspaces(): array
    {
        return array_map(fn ($it) => ['accountId' => $it['accountId'], 'workspace' => $it['workspace']], $this->candidates());
    }

    /**
     * @throws Forbidden when the person has no usable, verified identity in that workspace
     */
    public function access(?int $accountId): ChatAccess
    {
        $candidates = $this->candidates();

        if ($accountId === null) {
            if (count($candidates) !== 1) {
                throw new Forbidden('Pick the workspace.');
            }

            $accountId = $candidates[0]['accountId'];
        }

        if (isset($this->verified[$accountId])) {
            return $this->verified[$accountId];
        }

        $candidate = null;

        foreach ($candidates as $item) {
            if ($item['accountId'] === $accountId) {
                $candidate = $item;
            }
        }

        if (!$candidate) {
            throw new Forbidden('You have no Chat access in that workspace.');
        }

        $chatUser = $this->entityManager->getEntityById('ChatwootUser', $candidate['chatwootUserId']);
        $platform = $chatUser
            ? $this->entityManager->getEntityById('ChatwootPlatform', (string) $chatUser->get('platformId'))
            : null;
        $baseUrl = rtrim((string) $platform?->get('backendUrl'), '/');
        $userId = (int) $chatUser?->get('chatwootUserId');

        if (!$chatUser || $baseUrl === '' || $userId <= 0) {
            throw new Forbidden('You have no Chat access in that workspace.');
        }

        $token = (string) $chatUser->get('userAccessToken');

        if ($token === '' && $platform->get('accessToken')) {
            try {
                $token = (string) $this->chatwoot->fetchUserAccessToken($baseUrl, (string) $platform->get('accessToken'), $userId);
            } catch (Throwable) {
                $token = '';
            }
        }

        if ($token === '' || !$this->verify($baseUrl, $token, $userId, $accountId)) {
            throw new Forbidden('Your Chat identity in that workspace could not be verified.');
        }

        return $this->verified[$accountId] = new ChatAccess($accountId, $candidate['workspace'], $userId, $baseUrl, $token);
    }

    private function verify(string $baseUrl, string $token, int $userId, int $accountId): bool
    {
        try {
            $response = $this->chatwoot->accountRequest($baseUrl, $token, 'GET', '/api/v1/profile');
        } catch (Throwable) {
            return false;
        }

        $profile = $response['body'] ?? [];

        if ($response['code'] !== 200 || (int) ($profile['id'] ?? 0) !== $userId) {
            return false;
        }

        foreach ($profile['accounts'] ?? [] as $account) {
            if ((int) ($account['id'] ?? 0) === $accountId && ($account['status'] ?? null) === 'active') {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{accountId: int, workspace: string, chatwootUserId: string}> */
    private function candidates(): array
    {
        if ($this->workspaces !== null) {
            return $this->workspaces;
        }

        $found = [];
        $chatUsers = $this->entityManager->getRDBRepository('ChatwootUser')
            ->where(['assignedUserId' => $this->user->getId()])
            ->find();

        foreach ($chatUsers as $chatUser) {
            $memberships = $this->entityManager->getRDBRepository('ChatwootAccountUserMembership')
                ->where(['chatwootUserId' => $chatUser->getId(), 'isAI' => false])
                ->find();

            foreach ($memberships as $membership) {
                $account = $this->entityManager->getEntityById('ChatwootAccount', (string) $membership->get('chatwootAccountId'));
                $accountId = (int) $account?->get('chatwootAccountId');

                if (!$account || $accountId <= 0 || $account->get('platformId') !== $chatUser->get('platformId')) {
                    continue;
                }

                $found[$accountId][$chatUser->getId()] = [
                    'accountId' => $accountId,
                    'workspace' => (string) $account->get('name'),
                    'chatwootUserId' => $chatUser->getId(),
                ];
            }
        }

        $this->workspaces = [];

        // Two identities for one workspace is ambiguous: neither acts.
        foreach ($found as $byUser) {
            if (count($byUser) === 1) {
                $this->workspaces[] = array_values($byUser)[0];
            }
        }

        usort($this->workspaces, fn ($a, $b) => strcmp($a['workspace'], $b['workspace']));

        return $this->workspaces;
    }
}
