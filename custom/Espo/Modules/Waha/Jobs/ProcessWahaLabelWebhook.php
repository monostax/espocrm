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

        $this->log->info("ProcessWahaLabelWebhook: Processing {$event} for channel {$channelId}");

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
        $labelId = $payload->label->id ?? ($payload->id ?? null);
        $chatId = $payload->chatId ?? null;

        if (!$labelId || !$chatId) {
            $this->log->warning('ProcessWahaLabelWebhook: label.chat.added missing labelId or chatId');
            return;
        }

        $this->log->info("ProcessWahaLabelWebhook: Label {$labelId} added to chat {$chatId}");

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
            $this->log->info("ProcessWahaLabelWebhook: No WahaSessionLabel found for label {$labelId} - not an agent label, ignoring");
            return;
        }

        // Get the agent
        $agentId = $wahaSessionLabel->get('agentId');
        $agent = $this->entityManager->getEntityById('ChatwootAgent', $agentId);

        if (!$agent) {
            $this->log->warning("ProcessWahaLabelWebhook: ChatwootAgent {$agentId} not found");
            return;
        }

        $chatwootAgentId = $agent->get('chatwootAgentId');
        $this->log->info("ProcessWahaLabelWebhook: Found agent '{$agent->get('name')}' (chatwootAgentId: {$chatwootAgentId})");

        // Find the conversation by phone number
        $phoneNumber = $this->extractPhoneFromChatId($chatId);
        if (!$phoneNumber) {
            $this->log->warning("ProcessWahaLabelWebhook: Could not extract phone number from chatId {$chatId}");
            return;
        }

        $conversation = $this->findConversationByPhone($phoneNumber, $teamId);
        if (!$conversation) {
            $this->log->info("ProcessWahaLabelWebhook: No conversation found for phone {$phoneNumber}");
            return;
        }

        // Check if already assigned to this agent
        if ($conversation->get('assigneeId') === $chatwootAgentId) {
            $this->log->info("ProcessWahaLabelWebhook: Conversation already assigned to agent {$chatwootAgentId}");
            return;
        }

        // Update conversation assignee in CRM (with silent to prevent loop)
        $conversation->set('assigneeId', $chatwootAgentId);
        $this->entityManager->saveEntity($conversation, ['silent' => true]);

        $this->log->info("ProcessWahaLabelWebhook: Updated conversation {$conversation->getId()} assignee to {$chatwootAgentId}");

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
        $labelId = $payload->label->id ?? ($payload->id ?? null);
        $chatId = $payload->chatId ?? null;

        if (!$labelId || !$chatId) {
            $this->log->warning('ProcessWahaLabelWebhook: label.chat.deleted missing labelId or chatId');
            return;
        }

        $this->log->info("ProcessWahaLabelWebhook: Label {$labelId} removed from chat {$chatId}");

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
            $this->log->info("ProcessWahaLabelWebhook: No WahaSessionLabel found for label {$labelId} - not an agent label, ignoring");
            return;
        }

        // Get the agent
        $agentId = $wahaSessionLabel->get('agentId');
        $agent = $this->entityManager->getEntityById('ChatwootAgent', $agentId);

        if (!$agent) {
            $this->log->warning("ProcessWahaLabelWebhook: ChatwootAgent {$agentId} not found");
            return;
        }

        $chatwootAgentId = $agent->get('chatwootAgentId');

        // Find the conversation by phone number
        $phoneNumber = $this->extractPhoneFromChatId($chatId);
        if (!$phoneNumber) {
            $this->log->warning("ProcessWahaLabelWebhook: Could not extract phone number from chatId {$chatId}");
            return;
        }

        $conversation = $this->findConversationByPhone($phoneNumber, $teamId);
        if (!$conversation) {
            $this->log->info("ProcessWahaLabelWebhook: No conversation found for phone {$phoneNumber}");
            return;
        }

        // Only unassign if currently assigned to this agent
        if ($conversation->get('assigneeId') !== $chatwootAgentId) {
            $this->log->info("ProcessWahaLabelWebhook: Conversation not assigned to agent {$chatwootAgentId}, not unassigning");
            return;
        }

        // Unassign conversation in CRM (with silent to prevent loop)
        $conversation->set('assigneeId', null);
        $this->entityManager->saveEntity($conversation, ['silent' => true]);

        $this->log->info("ProcessWahaLabelWebhook: Unassigned conversation {$conversation->getId()}");

        // Sync to Chatwoot
        $this->syncAssignmentToChatwoot($conversation, null);
    }

    /**
     * Extract phone number from WhatsApp chatId.
     * Format: "5511999999999@c.us" -> "5511999999999"
     *
     * @param string $chatId
     * @return string|null
     */
    private function extractPhoneFromChatId(string $chatId): ?string
    {
        // Remove @c.us or @s.whatsapp.net suffix
        $phone = preg_replace('/@.*$/', '', $chatId);
        
        if (empty($phone) || !is_numeric($phone)) {
            return null;
        }

        return $phone;
    }

    /**
     * Find a ChatwootConversation by contact phone number.
     *
     * @param string $phoneNumber
     * @param string|null $teamId
     * @return Entity|null
     */
    private function findConversationByPhone(string $phoneNumber, ?string $teamId): ?Entity
    {
        // Try to find by contactPhoneNumber
        $query = $this->entityManager
            ->getRDBRepository('ChatwootConversation')
            ->where([
                'OR' => [
                    ['contactPhoneNumber' => $phoneNumber],
                    ['contactPhoneNumber' => '+' . $phoneNumber],
                    ['contactPhoneNumber*' => '%' . $phoneNumber],
                ],
            ])
            ->order('createdAt', 'DESC');

        if ($teamId) {
            $query->where(['teamId' => $teamId]);
        }

        return $query->findOne();
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

            $this->log->info("ProcessWahaLabelWebhook: Synced assignment to Chatwoot for conversation {$chatwootConversationId}");

        } catch (\Exception $e) {
            $this->log->error("ProcessWahaLabelWebhook: Failed to sync to Chatwoot: " . $e->getMessage());
        }
    }
}
