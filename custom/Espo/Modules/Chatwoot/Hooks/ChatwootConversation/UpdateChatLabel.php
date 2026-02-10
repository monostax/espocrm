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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootConversation;

use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Modules\Chatwoot\Services\WahaApiClient;

/**
 * Hook to update WhatsApp chat labels when a conversation is assigned to an agent.
 * 
 * When assigneeId changes:
 *   1. Get the conversation's inbox and find the ChatwootInboxIntegration
 *   2. Build the WhatsApp chatId from contactPhoneNumber
 *   3. Find the ChatwootAgent by assigneeId (chatwootAgentId)
 *   4. Find the WahaSessionLabel for that agent + inboxIntegration
 *   5. Verify label exists in WAHA (recreate if necessary)
 *   6. Call WAHA API to update the chat's labels
 */
class UpdateChatLabel
{
    /**
     * WAHA color map (0-19).
     */
    private const COLOR_MAP = [
        0 => '#ff9485', 1 => '#64c4ff', 2 => '#ffd429', 3 => '#dfaef0',
        4 => '#99b6c1', 5 => '#55ccb3', 6 => '#ff9dff', 7 => '#d3a91d',
        8 => '#6d7cce', 9 => '#d7e752', 10 => '#00d0e2', 11 => '#ffc5c7',
        12 => '#93ceac', 13 => '#f74848', 14 => '#00a0f2', 15 => '#83e422',
        16 => '#ffaf04', 17 => '#b5ebff', 18 => '#9ba6ff', 19 => '#9368cf',
    ];

    public function __construct(
        private EntityManager $entityManager,
        private WahaApiClient $wahaApiClient,
        private Log $log
    ) {}

    /**
     * Called after a conversation is saved.
     *
     * @param Entity $entity The ChatwootConversation entity
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
    {
        // Skip if assigneeId didn't change
        if (!$entity->isAttributeChanged('assigneeId')) {
            return;
        }

        // Skip if silent (internal sync operation)
        if (!empty($options['silent'])) {
            return;
        }

        $this->updateChatLabelForConversation($entity);
    }

    /**
     * Update the WhatsApp chat label based on the assigned agent.
     */
    private function updateChatLabelForConversation(Entity $conversation): void
    {
        try {
            // Get conversation's inbox
            $inboxId = $conversation->get('inboxId');
            if (!$inboxId) {
                $this->log->debug("UpdateChatLabel: Conversation {$conversation->getId()} has no inbox");
                return;
            }

            $inbox = $this->entityManager->getEntityById('ChatwootInbox', $inboxId);
            if (!$inbox) {
                $this->log->debug("UpdateChatLabel: Inbox {$inboxId} not found");
                return;
            }

            // Find ChatwootInboxIntegration via direct relationship or fallback methods
            $inboxIntegration = $this->findIntegrationForInbox($inbox);

            if (!$inboxIntegration) {
                $this->log->debug("UpdateChatLabel: No ChatwootInboxIntegration found for inbox {$inbox->getId()}");
                return;
            }

            // Get WAHA platform and session info
            $wahaPlatform = $this->entityManager->getEntityById(
                'WahaPlatform',
                $inboxIntegration->get('wahaPlatformId')
            );

            if (!$wahaPlatform) {
                $this->log->warning("UpdateChatLabel: WahaPlatform not found for integration {$inboxIntegration->getId()}");
                return;
            }

            $platformUrl = $wahaPlatform->get('backendUrl');
            $apiKey = $wahaPlatform->get('apiKey');
            $sessionName = $inboxIntegration->get('wahaSessionName');

            if (!$platformUrl || !$apiKey || !$sessionName) {
                $this->log->warning("UpdateChatLabel: Missing WAHA credentials or session name");
                return;
            }

            // Build WhatsApp chatId from contactPhoneNumber
            $phoneNumber = $conversation->get('contactPhoneNumber');
            if (!$phoneNumber) {
                $this->log->debug("UpdateChatLabel: Conversation has no contactPhoneNumber");
                return;
            }

            // Normalize phone number and build chatId
            $chatId = $this->buildChatId($phoneNumber);
            if (!$chatId) {
                $this->log->warning("UpdateChatLabel: Could not build chatId from phone number {$phoneNumber}");
                return;
            }

            // Get the new assigneeId
            $assigneeId = $conversation->get('assigneeId');

            // Determine which labels to set
            $labels = [];

            if ($assigneeId) {
                // Find ChatwootAgent by chatwootAgentId
                $agent = $this->entityManager
                    ->getRDBRepository('ChatwootAgent')
                    ->where([
                        'chatwootAgentId' => $assigneeId,
                        'chatwootAccountId' => $conversation->get('chatwootAccountId'),
                    ])
                    ->findOne();

                if ($agent) {
                    // Find WahaSessionLabel for this agent + inboxIntegration
                    $wahaSessionLabel = $this->entityManager
                        ->getRDBRepository('WahaSessionLabel')
                        ->where([
                            'agentId' => $agent->getId(),
                            'inboxIntegrationId' => $inboxIntegration->getId(),
                        ])
                        ->findOne();

                    if ($wahaSessionLabel && $wahaSessionLabel->get('wahaLabelId')) {
                        // Verify label exists in WAHA, recreate if necessary
                        $validLabelId = $this->ensureLabelExists(
                            $platformUrl,
                            $apiKey,
                            $sessionName,
                            $wahaSessionLabel,
                            $agent
                        );

                        if ($validLabelId) {
                            $labels[] = ['id' => $validLabelId];
                            $this->log->info(
                                "UpdateChatLabel: Setting label {$validLabelId} " .
                                "for chat {$chatId} (agent {$agent->get('name')})"
                            );
                        }
                    } else {
                        // No WahaSessionLabel exists - create one on-the-fly
                        $this->log->info(
                            "UpdateChatLabel: No WahaSessionLabel found for agent {$agent->getId()} " .
                            "+ integration {$inboxIntegration->getId()}, creating..."
                        );

                        $newLabelId = $this->createLabelForAgent(
                            $platformUrl,
                            $apiKey,
                            $sessionName,
                            $agent,
                            $inboxIntegration
                        );

                        if ($newLabelId) {
                            $labels[] = ['id' => $newLabelId];
                            $this->log->info(
                                "UpdateChatLabel: Created and setting label {$newLabelId} " .
                                "for chat {$chatId} (agent {$agent->get('name')})"
                            );
                        }
                    }
                } else {
                    $this->log->debug("UpdateChatLabel: ChatwootAgent with chatwootAgentId {$assigneeId} not found");
                }
            } else {
                // Unassigned - remove all agent labels
                $this->log->info("UpdateChatLabel: Removing labels for chat {$chatId} (unassigned)");
            }

            // Call WAHA API to update chat labels
            $this->wahaApiClient->updateChatLabels(
                $platformUrl,
                $apiKey,
                $sessionName,
                $chatId,
                $labels
            );

            $this->log->info(
                "UpdateChatLabel: Updated labels for chat {$chatId} - " .
                (count($labels) > 0 ? "set " . count($labels) . " label(s)" : "removed all labels")
            );

        } catch (\Exception $e) {
            $this->log->error(
                "UpdateChatLabel: Failed to update labels for conversation {$conversation->getId()}: " .
                $e->getMessage()
            );
        }
    }

    /**
     * Build a WhatsApp chatId from a phone number.
     * Format: {phoneNumber}@c.us (for individual chats)
     *
     * @param string $phoneNumber
     * @return string|null
     */
    private function buildChatId(string $phoneNumber): ?string
    {
        // Remove any non-numeric characters except leading +
        $cleaned = preg_replace('/[^0-9+]/', '', $phoneNumber);
        
        if (!$cleaned) {
            return null;
        }

        // Remove leading + if present
        $cleaned = ltrim($cleaned, '+');

        if (empty($cleaned)) {
            return null;
        }

        // WhatsApp chatId format for individual chats
        return $cleaned . '@c.us';
    }

    /**
     * Create a new WAHA label for an agent and store the WahaSessionLabel record.
     *
     * @return string|null The new label ID, or null if creation failed
     */
    private function createLabelForAgent(
        string $platformUrl,
        string $apiKey,
        string $sessionName,
        Entity $agent,
        Entity $inboxIntegration
    ): ?string {
        try {
            // Generate label name based on agent type (AI or human)
            $labelPrefix = $agent->get('isAI') ? '[✨]' : '[👤]';
            $labelName = $labelPrefix . ' ' . $agent->get('name');
            $color = abs(crc32($agent->getId())) % 20;
            $colorHex = self::COLOR_MAP[$color] ?? '#64c4ff';

            // Create label in WAHA
            $wahaResponse = $this->wahaApiClient->createLabel(
                $platformUrl,
                $apiKey,
                $sessionName,
                [
                    'name' => $labelName,
                    'color' => $color,
                ]
            );

            $wahaLabelId = $wahaResponse['id'] ?? null;

            if (!$wahaLabelId) {
                $this->log->error("UpdateChatLabel: WAHA response missing label ID when creating label for agent {$agent->getId()}");
                return null;
            }

            // Create WahaSessionLabel record
            $this->entityManager->createEntity('WahaSessionLabel', [
                'name' => $labelName,
                'wahaLabelId' => (string)$wahaLabelId,
                'color' => $color,
                'colorHex' => $wahaResponse['colorHex'] ?? $colorHex,
                'agentId' => $agent->getId(),
                'inboxIntegrationId' => $inboxIntegration->getId(),
                'teamsIds' => $inboxIntegration->getLinkMultipleIdList('teams'),
                'syncStatus' => 'synced',
            ], ['silent' => true]);

            $this->log->info(
                "UpdateChatLabel: Created WahaSessionLabel for agent {$agent->getId()} with WAHA ID {$wahaLabelId}"
            );

            return (string)$wahaLabelId;

        } catch (\Exception $e) {
            $this->log->error(
                "UpdateChatLabel: Failed to create label for agent {$agent->getId()}: " . $e->getMessage()
            );
            return null;
        }
    }

    /**
     * Ensure the label exists in WAHA. If not, recreate it and update the WahaSessionLabel record.
     * If session is not ready, falls back to using the stored label ID.
     *
     * @return string|null The valid label ID, or null if unable to ensure label exists
     */
    private function ensureLabelExists(
        string $platformUrl,
        string $apiKey,
        string $sessionName,
        Entity $wahaSessionLabel,
        Entity $agent
    ): ?string {
        $storedLabelId = $wahaSessionLabel->get('wahaLabelId');

        try {
            // Get all labels from WAHA to check if our label exists
            $wahaLabels = $this->wahaApiClient->listLabels($platformUrl, $apiKey, $sessionName);

            // Check if the label with our stored ID exists
            $labelExists = false;
            foreach ($wahaLabels as $label) {
                if ((string)($label['id'] ?? '') === (string)$storedLabelId) {
                    $labelExists = true;
                    break;
                }
            }

            if ($labelExists) {
                return $storedLabelId;
            }

            // Label doesn't exist in WAHA, need to recreate it
            $this->log->warning(
                "UpdateChatLabel: Label {$storedLabelId} not found in WAHA session {$sessionName}, recreating..."
            );

            // Generate label name based on agent type (AI or human)
            $labelPrefix = $agent->get('isAI') ? '[✨]' : '[👤]';
            $labelName = $labelPrefix . ' ' . $agent->get('name');
            $color = abs(crc32($agent->getId())) % 20;

            // Create new label in WAHA
            $wahaResponse = $this->wahaApiClient->createLabel(
                $platformUrl,
                $apiKey,
                $sessionName,
                [
                    'name' => $labelName,
                    'color' => $color,
                ]
            );

            $newLabelId = $wahaResponse['id'] ?? null;

            if (!$newLabelId) {
                $this->log->error("UpdateChatLabel: Failed to recreate label - WAHA response missing label ID");
                return null;
            }

            // Update the WahaSessionLabel record with the new ID
            $wahaSessionLabel->set('wahaLabelId', (string)$newLabelId);
            $wahaSessionLabel->set('name', $labelName);
            $wahaSessionLabel->set('color', $color);
            $wahaSessionLabel->set('colorHex', $wahaResponse['colorHex'] ?? self::COLOR_MAP[$color] ?? '#64c4ff');
            $this->entityManager->saveEntity($wahaSessionLabel, ['silent' => true]);

            $this->log->info(
                "UpdateChatLabel: Recreated label '{$labelName}' with new ID {$newLabelId} (was {$storedLabelId})"
            );

            return (string)$newLabelId;

        } catch (\Exception $e) {
            // Check if this is a session not ready error (422)
            $message = $e->getMessage();
            if (strpos($message, '422') !== false || strpos($message, 'STARTING') !== false || strpos($message, 'not as expected') !== false) {
                $this->log->warning(
                    "UpdateChatLabel: Session {$sessionName} not ready, using stored label ID {$storedLabelId} (best effort)"
                );
                // Return stored ID - the updateChatLabels call may still work or fail gracefully
                return $storedLabelId;
            }

            $this->log->error(
                "UpdateChatLabel: Failed to verify/recreate label {$storedLabelId}: " . $message
            );
            return null;
        }
    }

    /**
     * Find ChatwootInboxIntegration for an inbox using multiple methods.
     */
    private function findIntegrationForInbox(Entity $inbox): ?Entity
    {
        // Method 1: Direct relationship
        $integrationId = $inbox->get('chatwootInboxIntegrationId');
        if ($integrationId) {
            $integration = $this->entityManager->getEntityById('ChatwootInboxIntegration', $integrationId);
            if ($integration) {
                return $integration;
            }
        }

        // Method 2: By inboxIdentifier
        $inboxIdentifier = $inbox->get('inboxIdentifier');
        if ($inboxIdentifier) {
            $integration = $this->entityManager
                ->getRDBRepository('ChatwootInboxIntegration')
                ->where(['chatwootInboxIdentifier' => $inboxIdentifier])
                ->findOne();

            if ($integration) {
                return $integration;
            }
        }

        // Method 3: By chatwootInboxId
        $chatwootInboxId = $inbox->get('chatwootInboxId');
        if ($chatwootInboxId) {
            $integration = $this->entityManager
                ->getRDBRepository('ChatwootInboxIntegration')
                ->where(['chatwootInboxId' => $chatwootInboxId])
                ->findOne();

            if ($integration) {
                return $integration;
            }
        }

        return null;
    }
}
