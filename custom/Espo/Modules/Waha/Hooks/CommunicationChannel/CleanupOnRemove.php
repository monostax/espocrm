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

namespace Espo\Modules\Waha\Hooks\CommunicationChannel;

use Espo\Core\Exceptions\Error;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Modules\Waha\Services\WahaApiClient;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Hook to clean up WAHA session and Chatwoot inbox when CommunicationChannel is deleted.
 */
class CleanupOnRemove
{
    public static int $order = 10;

    public function __construct(
        private EntityManager $entityManager,
        private WahaApiClient $wahaApiClient,
        private ChatwootApiClient $chatwootApiClient,
        private Log $log
    ) {}

    /**
     * Before removing a CommunicationChannel, clean up the WAHA session and Chatwoot inbox.
     *
     * @param Entity $entity
     * @param array<string, mixed> $options
     */
    public function beforeRemove(Entity $entity, array $options): void
    {
        // Skip if this is a silent remove (internal operation)
        if (!empty($options['silent'])) {
            return;
        }

        $channelId = $entity->getId();
        $this->log->info("CommunicationChannel cleanup: Starting cleanup for channel {$channelId}");

        // Clean up WAHA Session
        $this->cleanupWahaSession($entity);

        // Clean up Chatwoot Inbox
        $this->cleanupChatwootInbox($entity);
    }

    /**
     * Clean up WAHA session.
     *
     * @param Entity $entity
     */
    private function cleanupWahaSession(Entity $entity): void
    {
        $sessionName = $entity->get('wahaSessionName');
        $wahaPlatformId = $entity->get('wahaPlatformId');

        if (!$sessionName || !$wahaPlatformId) {
            $this->log->info("CommunicationChannel cleanup: No WAHA session to clean up for channel {$entity->getId()}");
            return;
        }

        try {
            $wahaPlatform = $this->entityManager->getEntityById('WahaPlatform', $wahaPlatformId);

            if (!$wahaPlatform) {
                $this->log->warning("CommunicationChannel cleanup: WahaPlatform {$wahaPlatformId} not found");
                return;
            }

            $wahaUrl = $wahaPlatform->get('url');
            $wahaApiKey = $wahaPlatform->get('apiKey');

            if (!$wahaUrl || !$wahaApiKey) {
                $this->log->warning("CommunicationChannel cleanup: WahaPlatform missing URL or API key");
                return;
            }

            // First, delete the WAHA App if it exists
            $wahaAppId = $entity->get('wahaAppId');
            if ($wahaAppId) {
                try {
                    $this->log->info("CommunicationChannel cleanup: Deleting WAHA app {$wahaAppId}");
                    $this->wahaApiClient->deleteApp($wahaUrl, $wahaApiKey, $wahaAppId);
                    $this->log->info("CommunicationChannel cleanup: WAHA app {$wahaAppId} deleted successfully");
                } catch (\Exception $e) {
                    $this->log->warning("CommunicationChannel cleanup: Failed to delete WAHA app {$wahaAppId}: " . $e->getMessage());
                }
            }

            // Then, delete the WAHA Session
            $this->log->info("CommunicationChannel cleanup: Deleting WAHA session {$sessionName}");
            $this->wahaApiClient->deleteSession($wahaUrl, $wahaApiKey, $sessionName);
            $this->log->info("CommunicationChannel cleanup: WAHA session {$sessionName} deleted successfully");

        } catch (\Exception $e) {
            // Log the error but don't prevent the entity from being deleted
            $this->log->error("CommunicationChannel cleanup: Failed to delete WAHA session {$sessionName}: " . $e->getMessage());
        }
    }

    /**
     * Clean up Chatwoot inbox.
     *
     * @param Entity $entity
     */
    private function cleanupChatwootInbox(Entity $entity): void
    {
        $chatwootInboxId = $entity->get('chatwootInboxId');
        $chatwootAccountId = $entity->get('chatwootAccountId');

        if (!$chatwootInboxId || !$chatwootAccountId) {
            $this->log->info("CommunicationChannel cleanup: No Chatwoot inbox to clean up for channel {$entity->getId()}");
            return;
        }

        try {
            $chatwootAccount = $this->entityManager->getEntityById('ChatwootAccount', $chatwootAccountId);

            if (!$chatwootAccount) {
                $this->log->warning("CommunicationChannel cleanup: ChatwootAccount {$chatwootAccountId} not found");
                return;
            }

            $chatwootPlatformId = $chatwootAccount->get('platformId');
            $chatwootPlatform = $this->entityManager->getEntityById('ChatwootPlatform', $chatwootPlatformId);

            if (!$chatwootPlatform) {
                $this->log->warning("CommunicationChannel cleanup: ChatwootPlatform not found for account {$chatwootAccountId}");
                return;
            }

            $chatwootUrl = $chatwootPlatform->get('url');
            $chatwootApiKey = $chatwootAccount->get('apiKey') ?: $chatwootPlatform->get('accessToken');
            $chatwootAccountIdRemote = $chatwootAccount->get('chatwootAccountId');

            if (!$chatwootUrl || !$chatwootApiKey || !$chatwootAccountIdRemote) {
                $this->log->warning("CommunicationChannel cleanup: Missing Chatwoot credentials");
                return;
            }

            $this->log->info("CommunicationChannel cleanup: Deleting Chatwoot inbox {$chatwootInboxId} from account {$chatwootAccountIdRemote}");
            $this->chatwootApiClient->deleteInbox(
                $chatwootUrl,
                $chatwootApiKey,
                (int) $chatwootAccountIdRemote,
                (int) $chatwootInboxId
            );
            $this->log->info("CommunicationChannel cleanup: Chatwoot inbox {$chatwootInboxId} deleted successfully");

        } catch (\Exception $e) {
            // Log the error but don't prevent the entity from being deleted
            $this->log->error("CommunicationChannel cleanup: Failed to delete Chatwoot inbox {$chatwootInboxId}: " . $e->getMessage());
        }
    }
}

