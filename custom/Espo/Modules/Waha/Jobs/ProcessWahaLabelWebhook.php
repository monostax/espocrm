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

namespace Espo\Modules\Waha\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use Espo\ORM\Entity;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Waha\Services\WahaApiClient;

/**
 * Job to process WAHA label webhook events.
 * 
 * Handles label.chat.added and label.chat.deleted events to sync
 * WhatsApp label changes to ChatwootConversation assignee.
 * 
 * This enables bi-directional sync: when a label is changed on WhatsApp Business,
 * the corresponding ChatwootConversation is assigned/unassigned accordingly.
 */
class ProcessWahaLabelWebhook implements Job
{
    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $chatwootApiClient,
        private WahaApiClient $wahaApiClient,
        private Log $log
    ) {}

    public function run(Data $data): void
    {
        $channelId = $data->get('channelId');
        $event = $data->get('event');
        $payload = $data->get('payload');
        $session = $data->get('session');

        if (!$channelId || !$event || !$payload) {
            $this->log->error('ProcessWahaLabelWebhook: Missing required data (channelId, event, or payload)');
            return;
        }

        $this->log->warning("ProcessWahaLabelWebhook: Processing {$event} for channel {$channelId}");

        // Get the channel to access teamId for ACL
        $channel = $this->entityManager->getEntityById('ChatwootInboxIntegration', $channelId);
        if (!$channel) {
            $this->log->error("ProcessWahaLabelWebhook: Channel {$channelId} not found");
            return;
        }

        $teamId = $channel->get('teamId');
        if (!$teamId) {
            $this->log->warning("ProcessWahaLabelWebhook: Channel {$channelId} has no teamId, ACL may be bypassed");
        }

        try {
            if ($event === 'label.chat.added') {
                $this->handleLabelAdded($payload, $channel, $teamId);
            } elseif ($event === 'label.chat.deleted') {
                $this->handleLabelDeleted($payload, $channel, $teamId);
            } else {
                $this->log->warning("ProcessWahaLabelWebhook: Unknown event type: {$event}");
            }
        } catch (\Exception $e) {
            $this->log->error("ProcessWahaLabelWebhook: Error processing {$event}: " . $e->getMessage());
        }
    }

    /**
     * Handle label.chat.added event.
     * When a label is added to a chat on WhatsApp, assign the conversation to the corresponding agent.
     *
     * @param object $payload
     * @param Entity $channel
     * @param string|null $teamId
     */
    private function handleLabelAdded(object $payload, Entity $channel, ?string $teamId): void
    {
        // WAHA payload format: { labelId: "6", chatId: "123@c.us", label: { id: "6", ... } }
        // Note: label can be null right after scanning QR code
        $labelId = $payload->labelId ?? ($payload->label->id ?? null);
        $chatId = $payload->chatId ?? null;

        if (!$labelId || !$chatId) {
            $this->log->warning('ProcessWahaLabelWebhook: label.chat.added missing labelId or chatId', [
                'payload' => json_encode($payload),
            ]);
            return;
        }

        $this->log->warning("ProcessWahaLabelWebhook: Label {$labelId} added to chat {$chatId}");

        // Find WahaSessionLabel by wahaLabelId and teamId
        $wahaSessionLabelQuery = $this->entityManager
            ->getRDBRepository('WahaSessionLabel')
            ->where([
                'wahaLabelId' => (string) $labelId,
                'inboxIntegrationId' => $channel->getId(),
            ]);

        if ($teamId) {
            $wahaSessionLabelQuery->where(['teamId' => $teamId]);
        }

        $wahaSessionLabel = $wahaSessionLabelQuery->findOne();

        if (!$wahaSessionLabel) {
            $this->log->warning("ProcessWahaLabelWebhook: No WahaSessionLabel found for label {$labelId} - not an agent label, ignoring");
            return;
        }

        // Get the agent
        $agentId = $wahaSessionLabel->get('agentId');
        $agent = $this->entityManager->getEntityById('ChatwootAgent', $agentId);

        if (!$agent) {
            $this->log->warning("ProcessWahaLabelWebhook: ChatwootAgent {$agentId} not found");
            return;
        }

        $chatwootAgentId = $agent->get('chatwootAgentId') ? (int) $agent->get('chatwootAgentId') : null;
        $this->log->warning("ProcessWahaLabelWebhook: Found agent '{$agent->get('name')}' (chatwootAgentId: {$chatwootAgentId})");

        // Find the conversation by phone number and inbox (with LID resolution if needed)
        $phoneNumber = $this->extractPhoneFromChatId($chatId, $channel);
        if (!$phoneNumber) {
            $this->log->warning("ProcessWahaLabelWebhook: Could not extract phone number from chatId {$chatId}");
            return;
        }

        // Get chatwootInboxId from the related chatwootInbox entity
        $chatwootInboxId = $this->getChatwootInboxIdFromChannel($channel);
        $this->log->warning("ProcessWahaLabelWebhook: Looking for conversation with phone {$phoneNumber} in inbox {$chatwootInboxId}");
        
        $conversation = $this->findConversationByPhone($phoneNumber, $chatwootInboxId, $teamId);
        if (!$conversation) {
            $this->log->warning("ProcessWahaLabelWebhook: No conversation found for phone {$phoneNumber} in inbox {$chatwootInboxId}");
            return;
        }

        // Check if already assigned to this agent (cast to int for proper comparison)
        $currentAssigneeId = $conversation->get('assigneeId') ? (int) $conversation->get('assigneeId') : null;
        if ($currentAssigneeId === $chatwootAgentId) {
            $this->log->warning("ProcessWahaLabelWebhook: Conversation already assigned to agent {$chatwootAgentId}");
            return;
        }

        // Update conversation assignee in CRM (with silent to prevent loop)
        $conversation->set('assigneeId', $chatwootAgentId);
        $this->entityManager->saveEntity($conversation, ['silent' => true]);

        $this->log->warning("ProcessWahaLabelWebhook: Updated conversation {$conversation->getId()} assignee to {$chatwootAgentId}");

        // Sync to Chatwoot
        $this->syncAssignmentToChatwoot($conversation, $chatwootAgentId);
    }

    /**
     * Handle label.chat.deleted event.
     * When a label is removed from a chat on WhatsApp, unassign the conversation if it was assigned to that agent.
     *
     * @param object $payload
     * @param Entity $channel
     * @param string|null $teamId
     */
    private function handleLabelDeleted(object $payload, Entity $channel, ?string $teamId): void
    {
        // WAHA payload format: { labelId: "6", chatId: "123@c.us", label: null }
        // Note: label can be null, so we must use labelId field directly
        $labelId = $payload->labelId ?? ($payload->label->id ?? null);
        $chatId = $payload->chatId ?? null;

        if (!$labelId || !$chatId) {
            $this->log->warning('ProcessWahaLabelWebhook: label.chat.deleted missing labelId or chatId', [
                'payload' => json_encode($payload),
            ]);
            return;
        }

        $this->log->warning("ProcessWahaLabelWebhook: Label {$labelId} removed from chat {$chatId}");

        // Find WahaSessionLabel by wahaLabelId
        $wahaSessionLabelQuery = $this->entityManager
            ->getRDBRepository('WahaSessionLabel')
            ->where([
                'wahaLabelId' => (string) $labelId,
                'inboxIntegrationId' => $channel->getId(),
            ]);

        if ($teamId) {
            $wahaSessionLabelQuery->where(['teamId' => $teamId]);
        }

        $wahaSessionLabel = $wahaSessionLabelQuery->findOne();

        if (!$wahaSessionLabel) {
            $this->log->warning("ProcessWahaLabelWebhook: No WahaSessionLabel found for label {$labelId} - not an agent label, ignoring");
            return;
        }

        // Get the agent
        $agentId = $wahaSessionLabel->get('agentId');
        $agent = $this->entityManager->getEntityById('ChatwootAgent', $agentId);

        if (!$agent) {
            $this->log->warning("ProcessWahaLabelWebhook: ChatwootAgent {$agentId} not found");
            return;
        }

        $chatwootAgentId = $agent->get('chatwootAgentId') ? (int) $agent->get('chatwootAgentId') : null;
        $this->log->warning("ProcessWahaLabelWebhook: Found agent '{$agent->get('name')}' (chatwootAgentId: {$chatwootAgentId})");

        // Find the conversation by phone number and inbox (with LID resolution if needed)
        $phoneNumber = $this->extractPhoneFromChatId($chatId, $channel);
        if (!$phoneNumber) {
            $this->log->warning("ProcessWahaLabelWebhook: Could not extract phone number from chatId {$chatId}");
            return;
        }

        // Get chatwootInboxId from the related chatwootInbox entity
        $chatwootInboxId = $this->getChatwootInboxIdFromChannel($channel);
        $this->log->warning("ProcessWahaLabelWebhook: Looking for conversation with phone {$phoneNumber} in inbox {$chatwootInboxId}");
        
        $conversation = $this->findConversationByPhone($phoneNumber, $chatwootInboxId, $teamId);
        if (!$conversation) {
            $this->log->warning("ProcessWahaLabelWebhook: No conversation found for phone {$phoneNumber} in inbox {$chatwootInboxId}");
            return;
        }

        // Only unassign if currently assigned to this agent (cast to int for proper comparison)
        $currentAssigneeId = $conversation->get('assigneeId') ? (int) $conversation->get('assigneeId') : null;
        $this->log->warning("ProcessWahaLabelWebhook: Conversation {$conversation->getId()} currentAssigneeId={$currentAssigneeId}, chatwootAgentId={$chatwootAgentId}");
        
        if ($currentAssigneeId !== $chatwootAgentId) {
            $this->log->warning("ProcessWahaLabelWebhook: Conversation not assigned to agent {$chatwootAgentId} (current: {$currentAssigneeId}), not unassigning");
            return;
        }

        // Unassign conversation in CRM (with silent to prevent loop)
        $conversation->set('assigneeId', null);
        $this->entityManager->saveEntity($conversation, ['silent' => true]);

        $this->log->warning("ProcessWahaLabelWebhook: Unassigned conversation {$conversation->getId()}");

        // Sync to Chatwoot
        $this->syncAssignmentToChatwoot($conversation, null);
    }

    /**
     * Extract phone number from WhatsApp chatId.
     * Handles both @c.us format (direct phone) and @lid format (requires API lookup).
     * Format: "5511999999999@c.us" -> "5511999999999"
     *         "228144418136240@lid" -> resolved via WAHA API -> "5511999999999"
     *
     * @param string $chatId
     * @param Entity $channel The ChatwootInboxIntegration entity for WAHA API access
     * @return string|null
     */
    private function extractPhoneFromChatId(string $chatId, Entity $channel): ?string
    {
        // Check if this is a LID format
        if (str_ends_with($chatId, '@lid')) {
            $this->log->warning("ProcessWahaLabelWebhook: Resolving LID {$chatId} via WAHA API");
            
            // Get WAHA platform credentials from channel
            $platformId = $channel->get('wahaPlatformId');
            if (!$platformId) {
                $this->log->warning("ProcessWahaLabelWebhook: Channel has no wahaPlatformId, cannot resolve LID");
                return null;
            }

            $platform = $this->entityManager->getEntityById('WahaPlatform', $platformId);
            if (!$platform) {
                $this->log->warning("ProcessWahaLabelWebhook: WahaPlatform {$platformId} not found");
                return null;
            }

            $platformUrl = $platform->get('backendUrl');
            $apiKey = $platform->get('apiKey');
            $sessionName = $channel->get('wahaSessionName');

            if (!$platformUrl || !$apiKey || !$sessionName) {
                $this->log->warning("ProcessWahaLabelWebhook: Missing WAHA credentials for LID resolution");
                return null;
            }

            // Resolve LID to phone number via WAHA API
            $phoneChatId = $this->wahaApiClient->getPhoneByLid($platformUrl, $apiKey, $sessionName, $chatId);
            
            if (!$phoneChatId) {
                $this->log->warning("ProcessWahaLabelWebhook: Could not resolve LID {$chatId} to phone number");
                return null;
            }

            $this->log->warning("ProcessWahaLabelWebhook: Resolved LID {$chatId} to {$phoneChatId}");
            $chatId = $phoneChatId;
        }

        // Remove @c.us or @s.whatsapp.net suffix
        $phone = preg_replace('/@.*$/', '', $chatId);
        
        if (empty($phone) || !is_numeric($phone)) {
            return null;
        }

        return $phone;
    }

    /**
     * Get the Chatwoot inbox ID from the channel's related chatwootInbox entity.
     *
     * @param Entity $channel
     * @return int|null
     */
    private function getChatwootInboxIdFromChannel(Entity $channel): ?int
    {
        // Load the related chatwootInbox entity to get the actual Chatwoot inbox ID
        $inbox = $this->entityManager
            ->getRDBRepository('ChatwootInbox')
            ->where(['chatwootInboxIntegrationId' => $channel->getId()])
            ->findOne();

        if ($inbox) {
            $inboxId = $inbox->get('chatwootInboxId');
            $this->log->warning("ProcessWahaLabelWebhook: Found ChatwootInbox with chatwootInboxId={$inboxId}");
            if ($inboxId && is_numeric($inboxId)) {
                return (int) $inboxId;
            }
        }

        $this->log->warning("ProcessWahaLabelWebhook: Could not find chatwootInboxId for channel {$channel->getId()}");
        return null;
    }

    /**
     * Find a ChatwootConversation by contact phone number and inbox.
     *
     * @param string $phoneNumber
     * @param int|null $chatwootInboxId Filter by specific inbox (session)
     * @param string|null $teamId Filter by team for ACL
     * @return Entity|null
     */
    private function findConversationByPhone(string $phoneNumber, ?int $chatwootInboxId, ?string $teamId): ?Entity
    {
        // Try to find by contactPhoneNumber
        $whereConditions = [
            'OR' => [
                ['contactPhoneNumber' => $phoneNumber],
                ['contactPhoneNumber' => '+' . $phoneNumber],
                ['contactPhoneNumber*' => '%' . $phoneNumber],
            ],
        ];

        // Filter by inbox to ensure we get the conversation from the correct session
        if ($chatwootInboxId) {
            $whereConditions['chatwootInboxId'] = $chatwootInboxId;
        }

        // Filter by team for ACL
        if ($teamId) {
            $whereConditions['teamId'] = $teamId;
        }

        return $this->entityManager
            ->getRDBRepository('ChatwootConversation')
            ->where($whereConditions)
            ->findOne();
    }

    /**
     * Sync assignment change to Chatwoot.
     *
     * @param Entity $conversation
     * @param int|null $assigneeId
     */
    private function syncAssignmentToChatwoot(Entity $conversation, ?int $assigneeId): void
    {
        try {
            $chatwootAccountId = $conversation->get('chatwootAccountId');
            if (!$chatwootAccountId) {
                $this->log->warning("ProcessWahaLabelWebhook: Conversation has no chatwootAccountId");
                return;
            }

            $account = $this->entityManager->getEntityById('ChatwootAccount', $chatwootAccountId);
            if (!$account) {
                $this->log->warning("ProcessWahaLabelWebhook: ChatwootAccount {$chatwootAccountId} not found");
                return;
            }

            $platformId = $account->get('platformId');
            $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
            if (!$platform) {
                $this->log->warning("ProcessWahaLabelWebhook: ChatwootPlatform not found");
                return;
            }

            $platformUrl = $platform->get('backendUrl');
            $accountApiKey = $account->get('apiKey');
            $chatwootAccountIdRemote = $account->get('chatwootAccountId');
            $chatwootConversationId = $conversation->get('chatwootConversationId');

            if (!$platformUrl || !$accountApiKey || !$chatwootAccountIdRemote || !$chatwootConversationId) {
                $this->log->warning("ProcessWahaLabelWebhook: Missing Chatwoot credentials or conversation ID");
                return;
            }

            $this->chatwootApiClient->assignConversation(
                $platformUrl,
                $accountApiKey,
                (int) $chatwootAccountIdRemote,
                (int) $chatwootConversationId,
                $assigneeId
            );

            $this->log->warning("ProcessWahaLabelWebhook: Synced assignment to Chatwoot for conversation {$chatwootConversationId}");

        } catch (\Exception $e) {
            $this->log->error("ProcessWahaLabelWebhook: Failed to sync to Chatwoot: " . $e->getMessage());
        }
    }
}
