<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Rebuild;

use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootAccountUserMembershipService;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Chatwoot\Services\ChatwootWahaAppTokenSync;
use Espo\Modules\Chatwoot\Services\ConciergeAvatarService;
use Espo\Modules\Chatwoot\Services\ConciergeEmailDomainResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * One-shot upgrade migration from the legacy "automation user" to the new
 * "concierge user" concept.
 *
 * This action is fully idempotent and safe to run on every rebuild.
 *
 * Behaviour per ChatwootAccount that still carries a legacy linkage
 * (i.e. has a populated `automation_user_id` column, or has `concierge_user_id`
 * NULL but a non-empty `api_key` inherited from the old automation user):
 *
 *   1. Create a fresh concierge user on the Chatwoot platform
 *      (`concierge.<id>@guest.<tenant-slug>.<gitops-domain>`, name "✦ Concierge (Monostax)").
 *   2. Attach it to the Chatwoot account as administrator.
 *   3. Create a matching `ChatwootUser` entity in EspoCRM.
 *   4. Rotate `apiKey` to the fresh user's access token.
 *   5. Set `conciergeUserId` to the new `ChatwootUser`.
 *   6. Upsert the `ChatwootAccountUserMembership` row.
 *   7. (Best-effort) detach + delete the old Chatwoot user to free the
 *      legacy `automation.<id>@chatwoot.local` email and keep the
 *      Chatwoot user list clean.
 *
 * After every account has been rotated, the action performs a final
 * schema cleanup: drops the legacy `automation_user_id` column and its
 * index from `chatwoot_account`. That DDL is a no-op if the column has
 * already been removed (the action checks `INFORMATION_SCHEMA` first).
 *
 * Ordering: this action MUST run AFTER `SeedChatwootAccount` (which
 * guarantees the `concierge_user_id` column exists via EspoCRM's
 * `entityDefs` rebuild).
 */
class RotateAutomationToConcierge implements RebuildAction
{
    /**
     * Email suffix used by the OLD automation user.
     * Any ChatwootUser whose email matches this suffix is considered a
     * legacy record eligible for rotation + deletion.
     */
    private const LEGACY_EMAIL_SUFFIX = '@chatwoot.local';

    /**
     * Custom attribute tag used on the OLD automation user.
     * Kept for reference — we match primarily on the email suffix since
     * we cannot query custom_attributes remotely.
     */
    private const LEGACY_CUSTOM_ATTRIBUTE_TYPE = 'automation';

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private ChatwootAccountUserMembershipService $membershipService,
        private ConciergeAvatarService $conciergeAvatarService,
        private ConciergeEmailDomainResolver $conciergeEmailDomainResolver,
        private ChatwootWahaAppTokenSync $wahaAppTokenSync,
        private Log $log,
    ) {}

    public function process(): void
    {
        // Early-exit guard: if the legacy column is already gone AND every
        // account has a conciergeUserId set, there is nothing to do.
        $legacyColumnExists = $this->legacyColumnExists();

        if (!$legacyColumnExists && !$this->hasAccountsMissingConcierge()) {
            // Fully migrated; nothing to do.
            return;
        }

        $this->log->info('RotateAutomationToConcierge: Starting rotation pass');

        $accounts = $this->entityManager
            ->getRDBRepository('ChatwootAccount')
            ->where(['deleted' => false])
            ->find();

        $rotated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($accounts as $account) {
            try {
                $result = $this->rotateAccount($account);
                if ($result === 'rotated') {
                    $rotated++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->log->error(
                    'RotateAutomationToConcierge: Failed to rotate account ' .
                    $account->getId() . ' - ' . $e->getMessage()
                );
            }
        }

        $this->log->info(
            "RotateAutomationToConcierge: Rotation complete — rotated: $rotated, skipped: $skipped, failed: $failed"
        );

        // Only drop the legacy column once every account has been rotated.
        // If any rotations failed, keep the old column around so we don't
        // lose the breadcrumb trail; the next rebuild will retry.
        if ($failed === 0 && $legacyColumnExists) {
            $this->dropLegacyColumn();
        } elseif ($failed > 0) {
            $this->log->warning(
                'RotateAutomationToConcierge: Skipping legacy column drop because ' .
                $failed . ' account(s) failed to rotate. Re-run rebuild after fixing.'
            );
        }
    }

    /**
     * Rotate a single ChatwootAccount.
     *
     * @return string 'rotated' if new concierge user was created, 'skipped' otherwise.
     */
    private function rotateAccount(Entity $account): string
    {
        $accountId = $account->getId();
        $conciergeUserId = $account->get('conciergeUserId');
        $chatwootAccountId = $account->get('chatwootAccountId');

        // If already linked to a non-legacy concierge user, nothing to do.
        if ($conciergeUserId) {
            $existingUser = $this->entityManager->getEntityById('ChatwootUser', $conciergeUserId);
            if ($existingUser && !$this->isLegacyUser($existingUser)) {
                return 'skipped';
            }
        }

        if (!$chatwootAccountId) {
            $this->log->info(
                "RotateAutomationToConcierge: Account $accountId has no chatwootAccountId — skipping"
            );
            return 'skipped';
        }

        // Resolve platform credentials
        $platform = $account->get('platformId')
            ? $this->entityManager->getEntityById('ChatwootPlatform', $account->get('platformId'))
            : null;

        if (!$platform) {
            $this->log->warning(
                "RotateAutomationToConcierge: Account $accountId has no platform — skipping"
            );
            return 'skipped';
        }

        $backendUrl = $platform->get('backendUrl');
        $accessToken = $platform->get('accessToken');

        if (!$backendUrl || !$accessToken) {
            $this->log->warning(
                "RotateAutomationToConcierge: Platform missing credentials for account $accountId — skipping"
            );
            return 'skipped';
        }

        $this->log->info(
            "RotateAutomationToConcierge: Rotating account $accountId (chatwootAccountId=$chatwootAccountId)"
        );

        // Snapshot the legacy user so we can remove it AFTER the new one is active.
        $legacyChatwootUser = $conciergeUserId
            ? $this->entityManager->getEntityById('ChatwootUser', $conciergeUserId)
            : null;
        $legacyPlatformUserId = $legacyChatwootUser
            ? (int) $legacyChatwootUser->get('chatwootUserId')
            : null;

        // 1-4. Create concierge user on Chatwoot + attach to account + generate credentials
        $conciergeData = $this->createConciergeUser(
            $backendUrl,
            $accessToken,
            (int) $chatwootAccountId,
            (string) $account->get('name'),
            $account
        );

        if (!$conciergeData) {
            $this->log->warning(
                "RotateAutomationToConcierge: Could not create concierge user on Chatwoot for account $accountId"
            );
            return 'skipped';
        }

        // 5. Reuse an existing ChatwootUser entity if one already exists for this
        //    platform user on this platform. Chatwoot's POST /platform/api/v1/users
        //    is idempotent-by-email: on retries it returns the SAME existing platform
        //    user rather than 422-ing. If we blindly called createEntity each time we'd
        //    mint a fresh CRM row for every retry — which is the exact bug that caused
        //    the 21 duplicate rows we had to clean up. Look up the existing CRM row
        //    first; only create a new one if none exists.
        $platformUserId = (int) $conciergeData['user_id'];
        $existingCrmUser = $this->entityManager
            ->getRDBRepository('ChatwootUser')
            ->where([
                'chatwootUserId' => $platformUserId,
                'platformId' => $platform->getId(),
                'deleted' => false,
            ])
            ->findOne();

        // 6. Persist the linkage atomically. If the account save fails for ANY reason
        //    (hook fatals, DB locks, transient errors, ...) the CRM ChatwootUser row
        //    we just created gets rolled back with it. Without this, a crash between
        //    createChatwootUserEntity and saveEntity(account) would leave an orphan
        //    ChatwootUser behind and the next rebuild — seeing conciergeUserId still
        //    NULL — would create ANOTHER one, ad infinitum. That is literally what
        //    happened on 2026-04-29 (21 rogue rows before the loop broke).
        $pdo = $this->entityManager->getPDO();
        $pdo->beginTransaction();
        try {
            $newChatwootUser = $existingCrmUser
                ?? $this->createChatwootUserEntity($account, $platform, $conciergeData);

            if (!$newChatwootUser) {
                throw new \RuntimeException(
                    "Failed to materialise CRM ChatwootUser entity for account $accountId " .
                    "(platform user ID: $platformUserId, email: {$conciergeData['email']})"
                );
            }

            if (isset($conciergeData['access_token'])) {
                $account->set('apiKey', $conciergeData['access_token']);
            }
            $account->set('conciergeUserId', $newChatwootUser->getId());

            $this->entityManager->saveEntity($account, [SaveOption::SKIP_ALL => true]);

            $this->membershipService->upsertMembership(
                $account->getId(),
                $newChatwootUser->getId(),
                'administrator',
                isset($conciergeData['account_user_id']) ? (int) $conciergeData['account_user_id'] : null,
                true // isAI — concierge memberships are AI-enabled by default
            );

            // Proactively stamp the avatar URL we just uploaded to Chatwoot
            // onto the membership row. Looked up fresh to keep the write
            // inside the same transaction as the rotation.
            $avatarUrl = $conciergeData['avatar_url'] ?? null;
            if ($avatarUrl) {
                $membership = $this->entityManager
                    ->getRDBRepository('ChatwootAccountUserMembership')
                    ->where([
                        'chatwootAccountId' => $account->getId(),
                        'chatwootUserId' => $newChatwootUser->getId(),
                    ])
                    ->findOne();

                if ($membership && !$membership->get('avatarUrl')) {
                    $membership->set('avatarUrl', $avatarUrl);
                    $this->entityManager->saveEntity($membership, [SaveOption::SKIP_ALL => true]);
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $this->log->error(
                "RotateAutomationToConcierge: Rotation transaction rolled back for account $accountId — " .
                $e->getMessage()
            );
            return 'skipped';
        }

        // Propagate the freshly-minted concierge access token to every WAHA
        // Chatwoot app bound to this account. Without this step, existing
        // WAHA sessions keep the deleted automation user's token cached in
        // `config.accountToken` and every outbound bot reply / status
        // command hits Chatwoot with 401 "Invalid Access Token" — webhooks
        // from Chatwoot to WAHA still work (they use the inbox identifier),
        // which makes the breakage silent from the Chatwoot UI side.
        //
        // Done outside the DB transaction on purpose: a WAHA hiccup must
        // not roll back the already-committed rotation; the service logs
        // per-app failures and the next rebuild will retry.
        //
        // The normal `Hooks\ChatwootAccount\PropagateApiKeyToWaha` afterSave
        // hook cannot cover this path because the rotation uses
        // `SaveOption::SKIP_ALL` above to avoid recursing into Chatwoot
        // sync hooks while we're still inside the rebuild.
        try {
            $this->wahaAppTokenSync->syncForAccount($account);
        } catch (\Throwable $e) {
            $this->log->error(
                "RotateAutomationToConcierge: WAHA app token propagation failed for account $accountId — " .
                $e->getMessage()
            );
        }

        // 7. Best-effort cleanup of the legacy user (outside the transaction — if this
        //    fails we've still successfully rotated; cleanup can be retried later).
        if ($legacyChatwootUser && $legacyChatwootUser->getId() !== $newChatwootUser->getId()) {
            $this->cleanupLegacyUser(
                $backendUrl,
                $accessToken,
                (int) $chatwootAccountId,
                $legacyChatwootUser,
                $legacyPlatformUserId
            );
        }

        $reusedSuffix = $existingCrmUser ? ' (reused existing CRM entity)' : '';
        $this->log->info(
            "RotateAutomationToConcierge: Rotated account $accountId to concierge user$reusedSuffix " .
            "(platform user ID: {$conciergeData['user_id']}, email: {$conciergeData['email']})"
        );

        return 'rotated';
    }

    /**
     * True when the ChatwootUser entity looks like the OLD automation user
     * (email ends with `@chatwoot.local`).
     */
    private function isLegacyUser(Entity $chatwootUser): bool
    {
        $email = (string) $chatwootUser->get('email');
        if ($email === '') {
            return false;
        }
        return str_ends_with($email, self::LEGACY_EMAIL_SUFFIX);
    }

    /**
     * Create a concierge user on the Chatwoot platform and attach it
     * to the given account as administrator.
     *
     * Intentionally duplicates the helper from SeedChatwootAccount rather
     * than depending on it, because this class has a narrow, one-shot
     * lifetime and we don't want the two to drift behaviour.
     *
     * @param string $backendUrl
     * @param string $platformAccessToken
     * @param int $chatwootAccountId
     * @param string $accountName
     * @param Entity $account The ChatwootAccount entity (used to resolve tenant slug for email domain)
     * @return array<string, mixed>|null
     */
    private function createConciergeUser(
        string $backendUrl,
        string $platformAccessToken,
        int $chatwootAccountId,
        string $accountName,
        Entity $account,
    ): ?array {
        $email = $this->conciergeEmailDomainResolver->resolveEmail($account, $chatwootAccountId);
        $name = '✦ Concierge (Monostax)';
        $password = $this->generateSecurePassword();

        try {
            $userResponse = $this->apiClient->createUser($backendUrl, $platformAccessToken, [
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'custom_attributes' => [
                    'type' => 'concierge',
                    'created_by' => 'espocrm',
                    'account_id' => $chatwootAccountId,
                ],
            ]);

            $chatwootUserId = $userResponse['id'] ?? null;

            if (!$chatwootUserId) {
                $this->log->error(
                    'RotateAutomationToConcierge: createUser returned no ID for account ' . $chatwootAccountId
                );
                return null;
            }

            $accountUserResponse = $this->apiClient->attachUserToAccount(
                $backendUrl,
                $platformAccessToken,
                $chatwootAccountId,
                $chatwootUserId,
                'administrator'
            );

            $userAccessToken = $userResponse['access_token'] ?? null;

            // Best-effort avatar branding. See ConciergeAvatarService docs.
            $avatarUrl = null;
            if ($userAccessToken) {
                $avatarUrl = $this->conciergeAvatarService->uploadForConcierge(
                    $backendUrl,
                    $userAccessToken,
                    (int) $chatwootUserId
                );
            }

            return [
                'user_id' => $chatwootUserId,
                'email' => $email,
                'password' => $password,
                'name' => $name,
                'access_token' => $userAccessToken,
                'account_user_id' => isset($accountUserResponse['id']) ? (int) $accountUserResponse['id'] : null,
                'avatar_url' => $avatarUrl,
            ];
        } catch (\Exception $e) {
            // If the new-domain email collides (unlikely but possible on re-runs),
            // surface it as info and let the caller skip this account.
            if (
                str_contains($e->getMessage(), 'already been taken') ||
                str_contains($e->getMessage(), 'already exists')
            ) {
                $this->log->info(
                    'RotateAutomationToConcierge: Concierge email already taken for account ' . $chatwootAccountId .
                    ' — a previous rotation may have succeeded. Skipping.'
                );
                return null;
            }
            throw $e;
        }
    }

    /**
     * Create a ChatwootUser entity in CRM for the newly-minted concierge user.
     *
     * Mirrors the private helper in SeedChatwootAccount.
     *
     * @param array<string, mixed> $conciergeUserData
     */
    private function createChatwootUserEntity(Entity $account, Entity $platform, array $conciergeUserData): ?Entity
    {
        try {
            $teamsIds = $account->getLinkMultipleIdList('teams');

            $attributes = [
                'name' => $conciergeUserData['name'],
                'email' => $conciergeUserData['email'],
                'password' => $conciergeUserData['password'],
                'displayName' => $conciergeUserData['name'],
                'platformId' => $platform->getId(),
                'chatwootUserId' => $conciergeUserData['user_id'],
                'teamsIds' => $teamsIds,
            ];

            // Persist the user's personal access_token so the bi-directional
            // avatar sync (AgentAvatarSyncService) can hit /api/v1/profile
            // without re-fetching via the Platform API. Optional — the sync
            // lazily refetches when missing.
            $userAccessToken = $conciergeUserData['access_token'] ?? null;
            if (is_string($userAccessToken) && $userAccessToken !== '') {
                $attributes['userAccessToken'] = $userAccessToken;
            }

            $chatwootUser = $this->entityManager->createEntity('ChatwootUser', $attributes, [
                'skipHooks' => true,
                'silent' => true,
            ]);

            return $chatwootUser;
        } catch (\Throwable $e) {
            $this->log->error(
                'RotateAutomationToConcierge: Failed to create ChatwootUser entity — ' . $e->getMessage()
            );
            return null;
        }
    }

    /**
     * Detach + delete the legacy automation user from Chatwoot and remove
     * the corresponding ChatwootUser entity from CRM.
     *
     * All steps are best-effort. We never let a cleanup failure undo a
     * successful rotation — the account is already pointing at the new
     * concierge user by the time this runs.
     */
    private function cleanupLegacyUser(
        string $backendUrl,
        string $accessToken,
        int $chatwootAccountId,
        Entity $legacyChatwootUser,
        ?int $legacyPlatformUserId,
    ): void {
        $legacyEspoId = $legacyChatwootUser->getId();

        // Remove the legacy membership row in CRM (if any).
        try {
            $legacyMembership = $this->entityManager
                ->getRDBRepository('ChatwootAccountUserMembership')
                ->where([
                    'chatwootUserId' => $legacyEspoId,
                ])
                ->findOne();

            if ($legacyMembership) {
                $this->entityManager->removeEntity($legacyMembership, [SaveOption::SKIP_ALL => true]);
            }
        } catch (\Throwable $e) {
            $this->log->warning(
                'RotateAutomationToConcierge: Could not remove legacy membership for user ' .
                $legacyEspoId . ' - ' . $e->getMessage()
            );
        }

        // Detach + delete on Chatwoot side (if we know the platform user ID).
        if ($legacyPlatformUserId) {
            try {
                $this->apiClient->detachUserFromAccount(
                    $backendUrl,
                    $accessToken,
                    $chatwootAccountId,
                    $legacyPlatformUserId
                );
            } catch (\Throwable $e) {
                $this->log->warning(
                    'RotateAutomationToConcierge: Could not detach legacy user ' .
                    $legacyPlatformUserId . ' from account ' . $chatwootAccountId . ' - ' . $e->getMessage()
                );
            }

            try {
                $this->apiClient->deleteUser($backendUrl, $accessToken, $legacyPlatformUserId);
            } catch (\Throwable $e) {
                $this->log->warning(
                    'RotateAutomationToConcierge: Could not delete legacy Chatwoot user ' .
                    $legacyPlatformUserId . ' - ' . $e->getMessage()
                );
            }
        }

        // Remove the legacy ChatwootUser entity in CRM.
        try {
            $this->entityManager->removeEntity($legacyChatwootUser, [SaveOption::SKIP_ALL => true]);
        } catch (\Throwable $e) {
            $this->log->warning(
                'RotateAutomationToConcierge: Could not remove legacy ChatwootUser entity ' .
                $legacyEspoId . ' - ' . $e->getMessage()
            );
        }
    }

    /**
     * True if there is at least one ChatwootAccount whose `conciergeUserId`
     * is unset. Used as a fast pre-check.
     */
    private function hasAccountsMissingConcierge(): bool
    {
        $count = $this->entityManager
            ->getRDBRepository('ChatwootAccount')
            ->where([
                'deleted' => false,
                'conciergeUserId' => null,
            ])
            ->count();

        return $count > 0;
    }

    /**
     * Check if the legacy `automation_user_id` column still exists on
     * `chatwoot_account`. Returns false silently on any error (we'd
     * rather skip a cleanup step than fail a rebuild).
     */
    private function legacyColumnExists(): bool
    {
        try {
            $pdo = $this->entityManager->getPDO();
            $stmt = $pdo->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS " .
                "WHERE TABLE_SCHEMA = DATABASE() " .
                "AND TABLE_NAME = 'chatwoot_account' " .
                "AND COLUMN_NAME = 'automation_user_id' LIMIT 1"
            );
            $stmt->execute();
            return (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            $this->log->warning(
                'RotateAutomationToConcierge: Could not probe for legacy column — ' . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Drop the legacy `automation_user_id` column and its index from
     * `chatwoot_account`. Tolerant of partial states (missing index or
     * column). Logs and continues on error.
     */
    private function dropLegacyColumn(): void
    {
        try {
            $pdo = $this->entityManager->getPDO();

            // Drop index first (MySQL requires it before the column).
            // Detect index existence defensively so we don't blow up on
            // a fresh deployment where EspoCRM never created the old one.
            $indexStmt = $pdo->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS " .
                "WHERE TABLE_SCHEMA = DATABASE() " .
                "AND TABLE_NAME = 'chatwoot_account' " .
                "AND INDEX_NAME = 'IDX_AUTOMATION_USER_ID' LIMIT 1"
            );
            $indexStmt->execute();
            if ($indexStmt->fetchColumn()) {
                $pdo->exec('ALTER TABLE `chatwoot_account` DROP INDEX `IDX_AUTOMATION_USER_ID`');
                $this->log->info('RotateAutomationToConcierge: Dropped IDX_AUTOMATION_USER_ID');
            }

            $pdo->exec('ALTER TABLE `chatwoot_account` DROP COLUMN `automation_user_id`');
            $this->log->info('RotateAutomationToConcierge: Dropped legacy column chatwoot_account.automation_user_id');
        } catch (\Throwable $e) {
            $this->log->warning(
                'RotateAutomationToConcierge: Could not drop legacy column/index — ' . $e->getMessage()
            );
        }
    }

    /**
     * Mirrors SeedChatwootAccount::generateSecurePassword.
     */
    private function generateSecurePassword(): string
    {
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $numbers = '0123456789';
        $special = '!@#$%^&*()_+-=[]{}';

        $password = '';
        $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];
        $password .= $special[random_int(0, strlen($special) - 1)];
        $password .= $special[random_int(0, strlen($special) - 1)];
        $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];

        $allChars = $lowercase . $uppercase . $numbers . $special;
        for ($i = 0; $i < 12; $i++) {
            $password .= $allChars[random_int(0, strlen($allChars) - 1)];
        }

        return str_shuffle($password);
    }
}
