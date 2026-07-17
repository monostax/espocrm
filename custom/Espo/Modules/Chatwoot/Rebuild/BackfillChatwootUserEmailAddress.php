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

namespace Espo\Modules\Chatwoot\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Backfills ChatwootUser.emailAddress after migrating from the non-standard
 * `email` field name, which Espo's email field processor did not persist.
 */
class BackfillChatwootUserEmailAddress implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log,
    ) {}

    public function process(): void
    {
        $users = $this->entityManager
            ->getRDBRepository('ChatwootUser')
            ->find();

        $scanned = 0;
        $updated = 0;
        $unresolved = 0;
        $sourceCounts = [];

        foreach ($users as $chatwootUser) {
            $scanned++;

            if ($this->normalizeEmail($chatwootUser->get('emailAddress'))) {
                continue;
            }

            $resolved = $this->resolveEmail($chatwootUser);

            if (!$resolved) {
                $unresolved++;
                continue;
            }

            [$email, $source] = $resolved;
            $chatwootUser->set('emailAddress', $email);

            // Keep Espo field processing enabled so it creates the
            // email_address/entity_email_address relation.
            $this->entityManager->saveEntity($chatwootUser, ['silent' => true]);

            $updated++;
            $sourceCounts[$source] = ($sourceCounts[$source] ?? 0) + 1;
        }

        $this->log->info(
            'BackfillChatwootUserEmailAddress: scanned ' . $scanned .
            ', updated ' . $updated .
            ', unresolved ' . $unresolved .
            ', sources ' . json_encode($sourceCounts)
        );
    }

    /**
     * @return array{string, string}|null
     */
    private function resolveEmail(Entity $chatwootUser): ?array
    {
        $assignedUserId = $chatwootUser->get('assignedUserId');

        if ($assignedUserId) {
            $assignedUser = $this->entityManager->getEntityById('User', $assignedUserId);
            $email = $assignedUser ? $this->extractEntityEmail($assignedUser) : null;

            if ($email) {
                return [$email, 'assignedUser'];
            }
        }

        $membershipEmails = [];
        $memberships = $this->entityManager
            ->getRDBRepository('ChatwootAccountUserMembership')
            ->where(['chatwootUserId' => $chatwootUser->getId()])
            ->find();

        foreach ($memberships as $membership) {
            $email = $this->normalizeEmail($membership->get('email'));

            if ($email) {
                $membershipEmails[strtolower($email)] = $email;
            }
        }

        if (count($membershipEmails) === 1) {
            return [reset($membershipEmails), 'membership'];
        }

        if (count($membershipEmails) > 1) {
            $this->log->warning(
                'BackfillChatwootUserEmailAddress: conflicting membership emails for ChatwootUser ' .
                $chatwootUser->getId()
            );
        }

        $email = $this->resolveRemoteEmail($chatwootUser);

        return $email ? [$email, 'chatwoot'] : null;
    }

    private function resolveRemoteEmail(Entity $chatwootUser): ?string
    {
        $platformId = $chatwootUser->get('platformId');
        $chatwootUserId = (int) $chatwootUser->get('chatwootUserId');

        if (!$platformId || !$chatwootUserId) {
            return null;
        }

        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
        $platformUrl = $platform?->get('backendUrl');
        $accessToken = $platform?->get('accessToken');

        if (!$platformUrl || !$accessToken) {
            return null;
        }

        try {
            $userData = $this->apiClient->getUser($platformUrl, $accessToken, $chatwootUserId);

            return $this->normalizeEmail($userData['email'] ?? null);
        } catch (\Throwable $e) {
            $this->log->warning(
                'BackfillChatwootUserEmailAddress: failed to fetch ChatwootUser ' .
                $chatwootUser->getId() . ': ' . $e->getMessage()
            );

            return null;
        }
    }

    private function extractEntityEmail(Entity $entity): ?string
    {
        $email = $this->normalizeEmail($entity->get('emailAddress'));

        if ($email) {
            return $email;
        }

        $data = $entity->get('emailAddressData');

        if (!is_array($data)) {
            return null;
        }

        foreach ($data as $item) {
            $value = is_object($item)
                ? ($item->emailAddress ?? null)
                : ($item['emailAddress'] ?? null);
            $email = $this->normalizeEmail($value);

            if ($email) {
                return $email;
            }
        }

        return null;
    }

    private function normalizeEmail(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $email = trim($value);

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
