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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootInboxIntegration;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Chatwoot\Services\ChatwootInboxIdResolver;
use Espo\Modules\Chatwoot\Tools\WahaSessionTeardown;

/**
 * Hook to clean up external WAHA session and Chatwoot inbox resources
 * when ChatwootInboxIntegration is deleted.
 * 
 * Note: Database cascade deletion of related entities (ChatwootInbox, ChatwootConversation,
 * ChatwootMessage, WahaSessionLabel, etc.) is handled by the generic CascadeDelete hook
 * via metadata configuration in entityDefs.
 */
class CleanupOnRemove
{
    public static int $order = 10;

    public function __construct(
        private EntityManager $entityManager,
        private WahaSessionTeardown $wahaSessionTeardown,
        private ChatwootApiClient $chatwootApiClient,
        private ChatwootInboxIdResolver $chatwootInboxIdResolver,
        private Log $log
    ) {}

    /**
     * Before removing a ChatwootInboxIntegration, clean up external WAHA session
     * and Chatwoot inbox resources via their respective APIs.
     *
     * @param Entity $entity
     * @param array<string, mixed> $options
     */
    public function beforeRemove(Entity $entity, array $options): void
    {
        // Skip if this is a silent remove (internal operation / cascade from parent)
        if (!empty($options['silent'])) {
            return;
        }

        // Skip when this record is a *child* being removed as part of a real
        // parent cascade (see Global\Hooks\Common\CascadeDelete), where the
        // top-level entity is responsible for external teardown.
        //
        // WARNING: `cascadeParent` must never be passed by a top-level caller
        // that is itself initiating the delete — doing so silently skips WAHA
        // and Chatwoot teardown and leaks a live, authenticated WhatsApp
        // session. Callers who merely want to suppress remote inbox deletion
        // should pass `skipChatwootInboxCleanup` instead.
        if (!empty($options['cascadeParent'])) {
            return;
        }

        $skipChatwootInboxCleanup = !empty($options['skipChatwootInboxCleanup']);

        $channelId = $entity->getId();
        $channelType = $entity->get('channelType');
        $this->log->info("ChatwootInboxIntegration cleanup: Starting external API cleanup for channel {$channelId} (type: {$channelType})");

        // Clean up the WAHA session for every channel type that owns one.
        // Both QR code and Coexistence channels store a session name in
        // `wahaSessionName` (Coexistence provisions a send-only companion via
        // provisionWahaSendOnlySession()). Cloud API is the only sendable
        // channel with no WAHA session.
        $this->cleanupWahaSession($entity);

        // Clean up Chatwoot Inbox (works for both channel types)
        if (!$skipChatwootInboxCleanup) {
            $this->cleanupChatwootInbox($entity);
        }
    }

    /**
     * End the WAHA session. A failure is logged and does not block the delete.
     */
    private function cleanupWahaSession(Entity $entity): void
    {
        try {
            $this->wahaSessionTeardown->tearDown($entity);
        } catch (\Exception $e) {
            $this->log->error("ChatwootInboxIntegration cleanup: Failed to delete WAHA session {$entity->get('wahaSessionName')}: " . $e->getMessage());
        }
    }

    /**
     * Clean up Chatwoot inbox via external API.
     *
     * @param Entity $entity
     */
    private function cleanupChatwootInbox(Entity $entity): void
    {
        // Must be resolved through the resolver: the integration's own
        // `chatwootInboxId` attribute is the linked ChatwootInbox's Espo row id,
        // so casting it to int used to send a bogus id (always 6) to Chatwoot,
        // which 404s and silently left the remote inbox in place.
        $chatwootInboxId = $this->chatwootInboxIdResolver->resolve($entity, true);
        $chatwootAccountId = $entity->get('chatwootAccountId');

        if (!$chatwootInboxId || !$chatwootAccountId) {
            $this->log->info("ChatwootInboxIntegration cleanup: No Chatwoot inbox to clean up for channel {$entity->getId()}");
            return;
        }

        try {
            $chatwootAccount = $this->entityManager->getEntityById('ChatwootAccount', $chatwootAccountId);

            if (!$chatwootAccount) {
                $this->log->warning("ChatwootInboxIntegration cleanup: ChatwootAccount {$chatwootAccountId} not found");
                return;
            }

            $chatwootPlatformId = $chatwootAccount->get('platformId');
            $chatwootPlatform = $this->entityManager->getEntityById('ChatwootPlatform', $chatwootPlatformId);

            if (!$chatwootPlatform) {
                $this->log->warning("ChatwootInboxIntegration cleanup: ChatwootPlatform not found for account {$chatwootAccountId}");
                return;
            }

            $chatwootUrl = $chatwootPlatform->get('backendUrl');
            $chatwootApiKey = $chatwootAccount->get('apiKey') ?: $chatwootPlatform->get('accessToken');
            $chatwootAccountIdRemote = $chatwootAccount->get('chatwootAccountId');

            if (!$chatwootUrl || !$chatwootApiKey || !$chatwootAccountIdRemote) {
                $this->log->warning("ChatwootInboxIntegration cleanup: Missing Chatwoot credentials");
                return;
            }

            $this->log->info("ChatwootInboxIntegration cleanup: Deleting Chatwoot inbox {$chatwootInboxId} from account {$chatwootAccountIdRemote}");
            $this->chatwootApiClient->deleteInbox(
                $chatwootUrl,
                $chatwootApiKey,
                (int) $chatwootAccountIdRemote,
                (int) $chatwootInboxId
            );
            $this->log->info("ChatwootInboxIntegration cleanup: Chatwoot inbox {$chatwootInboxId} deleted successfully");

        } catch (\Exception $e) {
            // Log the error but don't prevent the entity from being deleted
            $this->log->error("ChatwootInboxIntegration cleanup: Failed to delete Chatwoot inbox {$chatwootInboxId}: " . $e->getMessage());
        }
    }
}
