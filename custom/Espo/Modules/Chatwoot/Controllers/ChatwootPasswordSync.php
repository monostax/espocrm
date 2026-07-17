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

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\PasswordHash;
use Espo\Entities\User;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * Receives password changes from Chatwoot and applies them to the linked
 * CRM user, keeping passwords in sync (Chatwoot -> CRM direction).
 *
 * Sent by Chatwoot's Crm::PasswordSyncService whenever a user changes
 * their password (profile settings, forgot-password reset, super admin).
 *
 * Security:
 * - HMAC-signed with the shared CRM_PASSWORD_SYNC_SECRET env var, using
 *   Chatwoot's webhook scheme: sha256=HMAC_SHA256(secret, "{ts}.{body}")
 *   with X-Chatwoot-Signature / X-Chatwoot-Timestamp headers.
 * - Timestamp freshness window prevents replay.
 * - The target user is resolved via the ChatwootUser mapping AND must
 *   match the payload email; super-admins are never updated.
 *
 * POST /api/v1/ChatwootPasswordSync
 */
class ChatwootPasswordSync
{
    private const TIMESTAMP_TOLERANCE_SECONDS = 300;

    public function __construct(
        private EntityManager $entityManager,
        private PasswordHash $passwordHash,
        private Log $log
    ) {}

    public function postActionReceive(Request $request, Response $response): stdClass
    {
        $secret = getenv('CRM_PASSWORD_SYNC_SECRET');

        if (!$secret) {
            $this->log->warning('ChatwootPasswordSync: CRM_PASSWORD_SYNC_SECRET is not configured, rejecting.');

            throw new Forbidden('Password sync is not configured.');
        }

        $rawBody = $request->getBodyContents() ?? '';

        $signature = $_SERVER['HTTP_X_CHATWOOT_SIGNATURE'] ?? null;
        $timestamp = $_SERVER['HTTP_X_CHATWOOT_TIMESTAMP'] ?? null;

        if (!$this->validateSignature($rawBody, $signature, $timestamp, $secret)) {
            $this->log->warning('ChatwootPasswordSync: Invalid HMAC signature.');

            throw new Forbidden('Invalid signature.');
        }

        $data = json_decode($rawBody);

        if (!$data instanceof stdClass) {
            throw new BadRequest('Invalid JSON payload.');
        }

        $chatwootUserId = $data->user_id ?? null;
        $email = $data->email ?? null;
        $password = $data->password ?? null;
        $installationUrl = $data->installation_url ?? null;

        if (!$chatwootUserId || !is_string($email) || !is_string($password) || $password === '') {
            throw new BadRequest('Missing user_id, email or password.');
        }

        $userIds = $this->resolveCrmUserIds((int) $chatwootUserId, $email, $installationUrl);

        if ($userIds === []) {
            $this->log->warning(
                "ChatwootPasswordSync: No linked CRM user found for Chatwoot user {$chatwootUserId} ({$email})."
            );

            throw new NotFound('No linked CRM user.');
        }

        $hash = $this->passwordHash->hash($password);
        $updatedCount = 0;

        foreach ($userIds as $userId) {
            /** @var ?User $user */
            $user = $this->entityManager->getEntityById(User::ENTITY_TYPE, $userId);

            if (!$user || !$user->isActive() || $user->isSuperAdmin() || $user->isSystem() || $user->isApi()) {
                continue;
            }

            $user->set('password', $hash);

            $this->entityManager->saveEntity($user, ['silent' => true]);

            $updatedCount++;

            $this->log->info(
                "ChatwootPasswordSync: Password updated for CRM user {$userId} " .
                "(from Chatwoot user {$chatwootUserId})."
            );
        }

        $this->updateStoredChatwootUserPasswords((int) $chatwootUserId, $email, $password, $installationUrl);

        return (object) ['success' => true, 'updated' => $updatedCount];
    }

    /**
     * Resolve CRM user IDs linked to a Chatwoot user.
     *
     * Looks up ChatwootUser mapping rows by the remote Chatwoot user ID,
     * optionally scoped to the platform matching the sending installation,
     * and requires the linked CRM user's email to match the payload email.
     *
     * @return string[]
     */
    private function resolveCrmUserIds(int $chatwootUserId, string $email, ?string $installationUrl): array
    {
        $chatwootUsers = $this->entityManager
            ->getRDBRepository('ChatwootUser')
            ->where(['chatwootUserId' => $chatwootUserId])
            ->find();

        $userIds = [];

        foreach ($chatwootUsers as $chatwootUser) {
            if (!$this->matchesInstallation($chatwootUser->get('platformId'), $installationUrl)) {
                continue;
            }

            $assignedUserId = $chatwootUser->get('assignedUserId');

            if (!$assignedUserId) {
                continue;
            }

            /** @var ?User $user */
            $user = $this->entityManager->getEntityById(User::ENTITY_TYPE, $assignedUserId);

            if (!$user) {
                continue;
            }

            $userEmail = $user->get('emailAddress');

            if (!is_string($userEmail) || strtolower($userEmail) !== strtolower($email)) {
                $this->log->warning(
                    "ChatwootPasswordSync: Email mismatch for ChatwootUser {$chatwootUser->getId()} " .
                    "(CRM user {$assignedUserId}), skipping."
                );

                continue;
            }

            $userIds[] = $assignedUserId;
        }

        return array_values(array_unique($userIds));
    }

    /**
     * Check that the mapping row's platform belongs to the Chatwoot
     * installation that sent the request. When either side is unknown,
     * the check is skipped (the email match still gates the update).
     */
    private function matchesInstallation(?string $platformId, ?string $installationUrl): bool
    {
        if (!$platformId || !is_string($installationUrl) || $installationUrl === '') {
            return true;
        }

        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);

        if (!$platform) {
            return true;
        }

        $frontendUrl = $platform->get('frontendUrl');

        if (!is_string($frontendUrl) || $frontendUrl === '') {
            return true;
        }

        return rtrim($frontendUrl, '/') === rtrim($installationUrl, '/');
    }

    /**
     * Keep the creation-time plaintext password stored on ChatwootUser
     * mapping rows consistent with the new Chatwoot password.
     *
     * Scoped to the sending installation and matching email, so rows
     * belonging to other tenants' Chatwoot installations (which may share
     * the same sequential numeric user ID) are never touched.
     */
    private function updateStoredChatwootUserPasswords(
        int $chatwootUserId,
        string $email,
        string $password,
        ?string $installationUrl
    ): void {
        $chatwootUsers = $this->entityManager
            ->getRDBRepository('ChatwootUser')
            ->where(['chatwootUserId' => $chatwootUserId])
            ->find();

        foreach ($chatwootUsers as $chatwootUser) {
            if (!$this->matchesInstallation($chatwootUser->get('platformId'), $installationUrl)) {
                continue;
            }

            $rowEmail = $chatwootUser->get('emailAddress');

            if (!is_string($rowEmail) || strtolower($rowEmail) !== strtolower($email)) {
                continue;
            }

            if ($chatwootUser->get('password') === $password) {
                continue;
            }

            $chatwootUser->set('password', $password);

            $this->entityManager->saveEntity($chatwootUser, [
                'silent' => true,
                'skipHooks' => true,
            ]);
        }
    }

    /**
     * Validate the Chatwoot HMAC signature with a replay-protection window.
     *
     * Scheme (same as Chatwoot account webhooks):
     * sha256=HMAC_SHA256(secret, "{timestamp}.{raw_body}")
     */
    private function validateSignature(
        string $rawBody,
        ?string $signature,
        ?string $timestamp,
        string $secret
    ): bool {
        if (!$signature || !$timestamp) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::TIMESTAMP_TOLERANCE_SECONDS) {
            return false;
        }

        $message = $timestamp . '.' . $rawBody;
        $expected = 'sha256=' . hash_hmac('sha256', $message, $secret);

        return hash_equals($expected, $signature);
    }
}
