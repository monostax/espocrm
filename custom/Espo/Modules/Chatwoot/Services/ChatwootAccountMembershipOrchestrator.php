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
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Acl;
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
        private Log $log,
        private Acl $acl
    ) {}

    /**
     * @throws BadRequest
     * @throws Error
     * @throws NotFound
     */
    public function addUserMembership(string $accountId, string $userId, string $role): Entity
    {
        if (!$this->acl->check('ChatwootAccount', 'read')) {
            throw new Forbidden('noAccountAccess');
        }

        if (!$this->acl->check('User', 'read')) {
            throw new Forbidden('noUserAccess');
        }

        $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);

        if (!$account) {
            throw new NotFound('ChatwootAccount not found.');
        }

        if (!$this->acl->check($account, 'read')) {
            throw new Forbidden('noAccountAccess');
        }

        $user = $this->entityManager->getEntityById('User', $userId);

        if (!$user) {
            throw new NotFound('CRM User not found.');
        }

        if (!$this->acl->check($user, 'read')) {
            throw new Forbidden('noUserAccess');
        }

        $this->assertUserBelongsToAccountTeam($account, $user);

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

        $email = $this->extractCrmUserEmail($user);

        if (!$email) {
            throw new BadRequest('selectedUserMustHaveEmail');
        }
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

            if ($chatwootUser->get('email') !== $email) {
                $chatwootUser->set('email', $email);
                $this->entityManager->saveEntity($chatwootUser, ['silent' => true]);
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

            if ($e instanceof BadRequest || $e instanceof Forbidden || $e instanceof NotFound) {
                throw $e;
            }

            throw new Error('Failed to add account user membership.');
        }
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

        // Fallback: look up by email using EspoCRM's email address lookup.
        // ChatwootUser.email is of EspoCRM type "email" which stores data in the
        // email_address / entity_email_address junction tables — NOT as a column
        // on chatwoot_user. A simple ->where(['email' => ...]) silently returns
        // no results. We must query the junction tables explicitly.
        $byEmail = null;

        if ($email) {
            $pdo = $this->entityManager->getPDO();
            $stmt = $pdo->prepare("
                SELECT cu.id
                FROM chatwoot_user cu
                INNER JOIN entity_email_address eea ON eea.entity_id = cu.id AND eea.entity_type = 'ChatwootUser' AND eea.deleted = false
                INNER JOIN email_address ea ON ea.id = eea.email_address_id AND ea.deleted = false
                WHERE ea.lower = LOWER(?)
                  AND cu.platform_id = ?
                  AND cu.deleted = false
                ORDER BY cu.created_at DESC
                LIMIT 1
            ");
            $stmt->execute([$email, $platformId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row) {
                $byEmail = $this->entityManager->getEntityById('ChatwootUser', $row['id']);
            }
        }

        if (!$byEmail) {
            return null;
        }

        $assignedUserId = $byEmail->get('assignedUserId');

        if ($assignedUserId && $assignedUserId !== $userId) {
            throw new Forbidden('chatwootUserAlreadyAssignedToAnotherUser');
        }

        if (!$assignedUserId) {
            $byEmail->set('assignedUserId', $userId);
            $this->entityManager->saveEntity($byEmail, ['silent' => true]);
        }

        return $byEmail;
    }

    /**
     * @throws Forbidden
     */
    private function assertUserBelongsToAccountTeam(Entity $account, Entity $user): void
    {
        $accountTeamsIds = $this->extractEntityTeamIds($account);

        if (!$accountTeamsIds) {
            return;
        }

        $userTeamsIds = $this->extractEntityTeamIds($user);

        if (!$userTeamsIds) {
            throw new Forbidden('selectedUserMustBelongToAccountTeam');
        }

        if (!array_intersect($accountTeamsIds, $userTeamsIds)) {
            throw new Forbidden('selectedUserMustBelongToAccountTeam');
        }
    }

    /**
     * Extract team IDs from an entity in a way that works for both regular entities
     * and User entities (where teams are stored via team_user relation).
     *
     * @return string[]
     */
    private function extractEntityTeamIds(Entity $entity): array
    {
        if ($entity instanceof CoreEntity) {
            $teamIds = array_values(array_filter($entity->getLinkMultipleIdList('teams')));

            if ($teamIds) {
                return $teamIds;
            }
        }

        return array_values(array_filter((array) ($entity->get('teamsIds') ?? [])));
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

    private function extractCrmUserEmail(Entity $user): ?string
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

        return $email ?: null;
    }
}
