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
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Field\DateTime;
use Espo\Core\Utils\Crypt;
use Espo\Core\Utils\Log;
use Espo\Entities\EmailAccount;
use Espo\Entities\InboundEmail;
use Espo\Entities\OAuthAccount;
use Espo\Entities\OAuthProvider;
use Espo\Modules\FeatureIntegrationGmail\Rebuild\SeedOAuthProviderGmail;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Imports a Chatwoot-native Google email grant into the CRM OAuthAccount
 * linked to its mirrored mailbox. The privileged endpoint keeps provider_config
 * out of the normal inbox payload and browser-facing API responses.
 */
class SyncEmailOAuthCredentials
{
    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Crypt $crypt,
        private Log $log,
    ) {}

    /**
     * @param array<string, mixed> $chatwootInbox
     */
    public function syncForInbox(
        string $platformUrl,
        string $apiKey,
        int $chatwootAccountId,
        array $chatwootInbox,
        Entity $localInbox,
        ?Entity $mailbox,
        array $teamsIds = []
    ): void {
        if (
            ($chatwootInbox['channel_type'] ?? null) !== EmailChannelBridge::REMOTE_CHANNEL_EMAIL ||
            ($chatwootInbox['provider'] ?? null) !== 'google'
        ) {
            $this->revokeForInbox($localInbox, $mailbox);

            return;
        }

        $inboxId = (int) ($chatwootInbox['id'] ?? 0);

        if (!$inboxId) {
            return;
        }

        if ($mailbox === null) {
            $this->revokeForInbox($localInbox);

            return;
        }

        try {
            $credentials = $this->apiClient->getInboxEmailOAuthCredentials(
                $platformUrl,
                $apiKey,
                $chatwootAccountId,
                $inboxId
            );
        } catch (\Throwable $e) {
            $this->log->warning(
                "SyncEmailOAuthCredentials: Failed to fetch Google OAuth credentials for inbox {$inboxId}: " .
                $e->getMessage()
            );

            return;
        }

        if ($credentials === null) {
            // A 404 means an older Chatwoot deployment may not have the
            // endpoint yet. Do not treat it as a source-side revocation.
            return;
        }

        if (($credentials['provider'] ?? null) !== 'google') {
            $this->log->warning(
                "SyncEmailOAuthCredentials: Unexpected credential provider for inbox {$inboxId}."
            );

            return;
        }

        if (($credentials['active'] ?? null) === false) {
            $this->revokeForInbox($localInbox, $mailbox);

            return;
        }

        $accessToken = $credentials['access_token'] ?? null;
        $refreshToken = $credentials['refresh_token'] ?? null;
        $expiresAt = $this->parseExpiresAt($credentials['expires_on'] ?? null);
        $sourceRevision = $credentials['source_revision'] ?? null;

        if (
            ($credentials['active'] ?? null) !== true ||
            !is_string($accessToken) || $accessToken === '' ||
            !is_string($refreshToken) || $refreshToken === '' ||
            $expiresAt === null ||
            !is_string($sourceRevision) || !preg_match('/^[a-f0-9]{64}$/', $sourceRevision)
        ) {
            $this->log->warning(
                "SyncEmailOAuthCredentials: Chatwoot returned an incomplete Google OAuth grant for inbox {$inboxId}."
            );

            return;
        }

        $provider = $this->entityManager->getEntityById(
            OAuthProvider::ENTITY_TYPE,
            SeedOAuthProviderGmail::PROVIDER_ID
        );

        if (!$provider instanceof OAuthProvider || !$provider->isActive()) {
            $this->log->warning(
                'SyncEmailOAuthCredentials: Google Gmail OAuthProvider is unavailable; run rebuild before syncing inbox ' .
                $inboxId . '.'
            );

            return;
        }

        if (!$this->hasCompatibleOAuthClient($provider, $credentials, $inboxId)) {
            return;
        }

        $oAuthAccount = $this->resolveOAuthAccount($localInbox, $mailbox, $provider, $inboxId);

        if ($oAuthAccount === null) {
            return;
        }

        if (
            $oAuthAccount->isNew() ||
            $this->sourceGrantHasChanged($oAuthAccount, $sourceRevision) ||
            !$oAuthAccount->get('accessToken') ||
            !$oAuthAccount->get('refreshToken')
        ) {
            if ($oAuthAccount->isNew()) {
                $oAuthAccount->set('name', $this->sourceAccountName($mailbox, $inboxId));
                $oAuthAccount->set('providerId', $provider->getId());
                $oAuthAccount->set('chatwootEmailInboxId', $localInbox->getId());
            }

            $oAuthAccount->set('accessToken', $this->crypt->encrypt($accessToken));
            $oAuthAccount->set('refreshToken', $this->crypt->encrypt($refreshToken));
            $oAuthAccount->set('expiresAt', $expiresAt->toString());
            $oAuthAccount->set('chatwootEmailOAuthRevision', $sourceRevision);

            if ($mailbox->getEntityType() === EmailAccount::ENTITY_TYPE) {
                $assignedUserId = $mailbox->get('assignedUserId');

                if ($assignedUserId && $oAuthAccount->hasAttribute('assignedUsersIds')) {
                    $oAuthAccount->set('assignedUsersIds', [$assignedUserId]);
                }
            } elseif ($teamsIds) {
                $oAuthAccount->set('teamsIds', $teamsIds);
            }

            $this->entityManager->saveEntity($oAuthAccount);
        }

        if ($mailbox->get('oAuthAccountId') !== $oAuthAccount->getId()) {
            $mailbox->set('oAuthAccountId', $oAuthAccount->getId());
            // The Gmail BeforeSave hook must run to install XOAUTH2 handlers.
            // The bridge flag prevents the resulting host/password changes looping back.
            $this->entityManager->saveEntity($mailbox, [
                EmailChannelBridge::SAVE_OPTION_SKIP => true,
            ]);
        }

        $this->log->debug(
            "SyncEmailOAuthCredentials: Synced Google OAuthAccount {$oAuthAccount->getId()} for inbox {$inboxId}."
        );
    }

    /**
     * Remove the source-owned credential when the remote Google grant, inbox,
     * or email channel is no longer present. Manually managed OAuth accounts
     * are never discovered by this source-specific lookup.
     */
    public function revokeForInbox(Entity $localInbox, ?Entity $mailbox = null): void
    {
        $oAuthAccount = $this->findSourceOAuthAccount($localInbox);

        if (!$oAuthAccount) {
            return;
        }

        foreach ($this->resolveMailboxes($localInbox, $mailbox) as $resolvedMailbox) {
            if ($resolvedMailbox->get('oAuthAccountId') !== $oAuthAccount->getId()) {
                continue;
            }

            $resolvedMailbox->set('oAuthAccountId', null);
            $this->entityManager->saveEntity($resolvedMailbox, [
                EmailChannelBridge::SAVE_OPTION_SKIP => true,
            ]);
        }

        // Clear the encrypted grant before soft-deleting the source account so
        // a future restore cannot reactivate credentials Chatwoot revoked.
        $oAuthAccount->set('accessToken', null);
        $oAuthAccount->set('refreshToken', null);
        $oAuthAccount->set('expiresAt', null);
        $this->entityManager->saveEntity($oAuthAccount, ['silent' => true]);
        $this->entityManager->removeEntity($oAuthAccount);
        $this->log->debug(
            "SyncEmailOAuthCredentials: Revoked source-owned Google OAuthAccount {$oAuthAccount->getId()} " .
            "for Chatwoot inbox {$localInbox->getId()}."
        );
    }

    private function resolveOAuthAccount(
        Entity $localInbox,
        Entity $mailbox,
        OAuthProvider $provider,
        int $inboxId
    ): ?Entity {
        $oAuthAccount = $this->findSourceOAuthAccount($localInbox);
        $linkedAccountId = $mailbox->get('oAuthAccountId');

        if ($oAuthAccount) {
            if ($oAuthAccount->get('providerId') !== $provider->getId()) {
                $this->log->warning(
                    "SyncEmailOAuthCredentials: Source OAuthAccount for inbox {$inboxId} has a different provider."
                );

                return null;
            }

            if ($linkedAccountId && $linkedAccountId !== $oAuthAccount->getId()) {
                $this->log->warning(
                    "SyncEmailOAuthCredentials: Restoring source-managed OAuthAccount for mailbox " .
                    "{$mailbox->getId()} on inbox {$inboxId}."
                );
            }

            return $oAuthAccount;
        }

        if ($linkedAccountId) {
            $linkedAccount = $this->entityManager->getEntityById(OAuthAccount::ENTITY_TYPE, $linkedAccountId);

            if (!$linkedAccount) {
                $mailbox->set('oAuthAccountId', null);
                $this->entityManager->saveEntity($mailbox, [
                    EmailChannelBridge::SAVE_OPTION_SKIP => true,
                ]);

                return $this->entityManager->getNewEntity(OAuthAccount::ENTITY_TYPE);
            }

            $this->log->warning(
                "SyncEmailOAuthCredentials: Mailbox {$mailbox->getId()} for inbox {$inboxId} already has a manually managed OAuthAccount; preserving it."
            );

            return null;
        }

        return $this->entityManager->getNewEntity(OAuthAccount::ENTITY_TYPE);
    }

    private function findSourceOAuthAccount(Entity $localInbox): ?Entity
    {
        return $this->entityManager
            ->getRDBRepository(OAuthAccount::ENTITY_TYPE)
            ->where(['chatwootEmailInboxId' => $localInbox->getId()])
            ->findOne();
    }

    /**
     * @return Entity[]
     */
    private function resolveMailboxes(Entity $localInbox, ?Entity $mailbox): array
    {
        $mailboxes = [];

        if ($mailbox) {
            $key = $mailbox->getEntityType() . ':' . $mailbox->getId();
            $mailboxes[$key] = $mailbox;
        }

        foreach ([
            [InboundEmail::ENTITY_TYPE, 'inboundEmailId'],
            [EmailAccount::ENTITY_TYPE, 'emailAccountId'],
        ] as [$entityType, $field]) {
            $id = $localInbox->get($field);

            if (!$id) {
                continue;
            }

            $resolved = $this->entityManager->getEntityById($entityType, $id);

            if (!$resolved) {
                continue;
            }

            $key = $resolved->getEntityType() . ':' . $resolved->getId();
            $mailboxes[$key] = $resolved;
        }

        return array_values($mailboxes);
    }

    private function sourceGrantHasChanged(Entity $oAuthAccount, string $sourceRevision): bool
    {
        $stored = $oAuthAccount->get('chatwootEmailOAuthRevision');

        if (!is_string($stored) || $stored === '') {
            return true;
        }

        return !hash_equals($stored, $sourceRevision);
    }

    /**
     * Refresh tokens are bound to the OAuth client that obtained them. Do not
     * import a grant that the CRM could only use until its access token expires.
     * The Chatwoot client ID is not secret and is returned by the privileged
     * endpoint solely for this compatibility check.
     *
     * @param array<string, mixed> $credentials
     */
    private function hasCompatibleOAuthClient(
        OAuthProvider $provider,
        array $credentials,
        int $inboxId
    ): bool {
        $sourceClientId = $credentials['client_id'] ?? null;

        try {
            $crmClientId = $provider->getClientId();
            $crmClientSecret = $provider->getClientSecret();
        } catch (\Throwable) {
            $crmClientId = null;
            $crmClientSecret = null;
        }

        if (
            !is_string($sourceClientId) || $sourceClientId === '' ||
            !is_string($crmClientId) || $crmClientId === '' ||
            !is_string($crmClientSecret) || $crmClientSecret === ''
        ) {
            $this->log->warning(
                "SyncEmailOAuthCredentials: Configure the CRM Google Gmail OAuth client before syncing inbox {$inboxId}."
            );

            return false;
        }

        if (!hash_equals($sourceClientId, $crmClientId)) {
            $this->log->warning(
                "SyncEmailOAuthCredentials: CRM and Chatwoot use different Google OAuth clients for inbox {$inboxId}."
            );

            return false;
        }

        return true;
    }

    private function sourceAccountName(Entity $mailbox, int $inboxId): string
    {
        $email = (string) ($mailbox->get('emailAddress') ?? '');
        $suffix = $email ?: "inbox #{$inboxId}";

        return substr('Chatwoot Gmail ' . $suffix, 0, 100);
    }

    private function parseExpiresAt(mixed $expiresOn): ?DateTime
    {
        return $this->parseDateTime($expiresOn);
    }

    private function parseDateTime(mixed $value): ?DateTime
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return DateTime::fromDateTime(new \DateTimeImmutable($value));
        } catch (\Throwable) {
            return null;
        }
    }
}
