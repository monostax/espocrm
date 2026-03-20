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

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Orchestrates Chatwoot account membership creation from a CRM User + role.
 *
 * Flow:
 *   1. Resolve/create ChatwootUser by (assignedUserId, platformId)
 *   2. Create or update ChatwootAccountUserMembership for (account, chatwootUser)
 *   3. Synchronize remote account role on Chatwoot Platform API
 *
 * Idempotent behavior:
 *   - Existing membership with same role => success no-op.
 *   - Existing membership with different role => update role + sync remote.
 */
class ChatwootAccountMembershipOrchestrator
{
    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private ChatwootAccountUserMembershipService $membershipService,
        private Log $log
    ) {}

    /**
     * @throws BadRequest
     * @throws Error
     * @throws NotFound
     */
    public function addUserMembership(string $accountId, string $userId, string $role): Entity
    {
        $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);

        if (!$account) {
            throw new NotFound('ChatwootAccount not found.');
        }

        $user = $this->entityManager->getEntityById('User', $userId);

        if (!$user) {
            throw new NotFound('CRM User not found.');
        }

        $platformId = $account->get('platformId');
        if (!$platformId) {
            throw new BadRequest('ChatwootAccount does not have a platform configured.');
        }

        $externalAccountId = $account->get('chatwootAccountId');
        if (!$externalAccountId) {
            throw new BadRequest('ChatwootAccount has not been synchronized with Chatwoot yet.');
        }

        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);

        if (!$platform) {
            throw new NotFound('ChatwootPlatform not found.');
        }

        $platformUrl = $platform->get('backendUrl');
        $accessToken = $platform->get('accessToken');

        if (!$platformUrl || !$accessToken) {
            throw new BadRequest('ChatwootPlatform is missing backend URL or access token.');
        }

        $email = $this->extractUserEmail($user);
        $name = $user->get('name') ?: $user->get('userName') ?: $email;
        $teamsIds = $account->get('teamsIds') ?? [];

        $tm = $this->entityManager->getTransactionManager();
        $tm->start();

        $createdRemoteUserId = null;
        $attachedToAccount = false;
        $roleResynced = false;
        $roleResyncOldRole = null;
        $roleResyncExternalUserId = null;

        try {
            $chatwootUser = $this->findChatwootUser($platformId, $userId, $email);

            if (!$chatwootUser) {
                $generatedPassword = $this->generatePassword();

                $userResponse = $this->apiClient->createUser($platformUrl, $accessToken, [
                    'name' => $name,
                    'email' => $email,
                    'password' => $generatedPassword,
                    'custom_attributes' => [],
                ]);

                if (!isset($userResponse['id'])) {
                    throw new Error('Chatwoot API response missing user ID.');
                }

                $createdRemoteUserId = (int) $userResponse['id'];

                $chatwootUser = $this->entityManager->createEntity('ChatwootUser', [
                    'name' => $name,
                    'email' => $email,
                    'password' => $generatedPassword,
                    'platformId' => $platformId,
                    'assignedUserId' => $userId,
                    'chatwootUserId' => $createdRemoteUserId,
                    'teamsIds' => $teamsIds,
                ], ['silent' => true]);
            }

            $chatwootUserId = $chatwootUser->getId();
            $externalUserId = (int) $chatwootUser->get('chatwootUserId');

            if (!$externalUserId) {
                throw new Error('Resolved ChatwootUser is missing external chatwootUserId.');
            }

            $membership = $this->entityManager
                ->getRDBRepository('ChatwootAccountUserMembership')
                ->where([
                    'chatwootAccountId' => $accountId,
                    'chatwootUserId' => $chatwootUserId,
                ])
                ->findOne();

            if (!$membership) {
                $this->apiClient->attachUserToAccount(
                    $platformUrl,
                    $accessToken,
                    (int) $externalAccountId,
                    $externalUserId,
                    $role
                );
                $attachedToAccount = true;

                $membership = $this->membershipService->upsertMembership(
                    $accountId,
                    $chatwootUserId,
                    $role
                );
            } elseif ($membership->get('role') !== $role) {
                $roleResyncOldRole = (string) $membership->get('role');
                $roleResyncExternalUserId = $externalUserId;

                $this->resyncRole(
                    $platformUrl,
                    $accessToken,
                    (int) $externalAccountId,
                    $externalUserId,
                    $roleResyncOldRole,
                    $role
                );

                $roleResynced = true;

                $membership->set('role', $role);
                $membership->set('syncStatus', 'synced');
                $membership->set('lastSyncedAt', date('Y-m-d H:i:s'));
                $membership->set('lastSyncError', null);

                $this->entityManager->saveEntity($membership, ['silent' => true]);
            }

            $tm->commit();

            return $membership;
        } catch (\Throwable $e) {
            $tm->rollback();

            if ($attachedToAccount && isset($externalUserId)) {
                try {
                    $this->apiClient->detachUserFromAccount(
                        $platformUrl,
                        $accessToken,
                        (int) $externalAccountId,
                        $externalUserId
                    );
                } catch (\Throwable $detachException) {
                    $this->log->error(
                        'Failed to compensate account user attach: ' . $detachException->getMessage()
                    );
                }
            }

            if ($roleResynced && $roleResyncOldRole !== null && $roleResyncExternalUserId !== null) {
                try {
                    $this->resyncRole(
                        $platformUrl,
                        $accessToken,
                        (int) $externalAccountId,
                        (int) $roleResyncExternalUserId,
                        $role,
                        $roleResyncOldRole
                    );
                } catch (\Throwable $roleRollbackException) {
                    $this->log->error(
                        'Failed to compensate Chatwoot role update after local rollback: ' .
                        $roleRollbackException->getMessage()
                    );
                }
            }

            if ($createdRemoteUserId !== null) {
                try {
                    $this->apiClient->deleteUser($platformUrl, $accessToken, $createdRemoteUserId);
                } catch (\Throwable $deleteException) {
                    $this->log->error(
                        'Failed to compensate remote Chatwoot user creation: ' . $deleteException->getMessage()
                    );
                }
            }

            throw new Error('Failed to add account user membership: ' . $e->getMessage());
        }
    }

    /**
     * @throws BadRequest
     */
    private function extractUserEmail(Entity $user): string
    {
        $email = $user->get('emailAddress');

        if (!$email) {
            $emailAddressData = $user->get('emailAddressData') ?? [];

            if (is_array($emailAddressData) && !empty($emailAddressData)) {
                $first = $emailAddressData[0] ?? null;

                if (is_object($first)) {
                    $email = (string) ($first->emailAddress ?? '');
                } elseif (is_array($first)) {
                    $email = (string) ($first['emailAddress'] ?? '');
                }
            }
        }

        if (!$email) {
            throw new BadRequest('selectedUserMustHaveEmail');
        }

        return $email;
    }

    private function findChatwootUser(string $platformId, string $userId, string $email): ?Entity
    {
        $byAssigned = $this->entityManager
            ->getRDBRepository('ChatwootUser')
            ->where([
                'platformId' => $platformId,
                'assignedUserId' => $userId,
            ])
            ->order('createdAt', 'DESC')
            ->findOne();

        if ($byAssigned) {
            return $byAssigned;
        }

        return $this->entityManager
            ->getRDBRepository('ChatwootUser')
            ->where([
                'platformId' => $platformId,
                'email' => $email,
            ])
            ->order('createdAt', 'DESC')
            ->findOne();
    }

    /**
     * @throws Error
     */
    private function resyncRole(
        string $platformUrl,
        string $accessToken,
        int $externalAccountId,
        int $externalUserId,
        string $oldRole,
        string $newRole
    ): void {
        $this->apiClient->detachUserFromAccount(
            $platformUrl,
            $accessToken,
            $externalAccountId,
            $externalUserId
        );

        try {
            $this->apiClient->attachUserToAccount(
                $platformUrl,
                $accessToken,
                $externalAccountId,
                $externalUserId,
                $newRole
            );
        } catch (\Throwable $e) {
            try {
                $this->apiClient->attachUserToAccount(
                    $platformUrl,
                    $accessToken,
                    $externalAccountId,
                    $externalUserId,
                    $oldRole
                );
            } catch (\Throwable $restoreException) {
                $this->log->error(
                    'Failed to restore old Chatwoot role after failed role update: ' . $restoreException->getMessage()
                );
            }

            throw new Error('Failed to update Chatwoot account role: ' . $e->getMessage());
        }
    }

    private function generatePassword(): string
    {
        return bin2hex(random_bytes(8)) . '!A1';
    }
}
