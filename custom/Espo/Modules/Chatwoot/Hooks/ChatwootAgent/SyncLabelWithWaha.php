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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootAgent;

use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Modules\Chatwoot\Services\WahaApiClient;

/**
 * Hook to synchronize WahaSessionLabel when ChatwootAgent is linked/unlinked to/from ChatwootInbox.
 * 
 * When an agent is added to an inbox:
 *   1. Find the ChatwootInboxIntegration for that inbox
 *   2. Create a label in WAHA with name "[✨] {agent.name}" (AI) or "[👤] {agent.name}" (human)
 *   3. Create a WahaSessionLabel record linking agent + inboxIntegration
 * 
 * When an agent is removed from an inbox:
 *   1. Find the WahaSessionLabel for agent + inboxIntegration
 *   2. Delete the label from WAHA
 *   3. Delete the WahaSessionLabel record
 */
class SyncLabelWithWaha
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
     * Called after an agent is linked to an inbox.
     *
     * @param Entity $entity The ChatwootAgent entity
     * @param array<string, mixed> $options
     * @param array<string, mixed> $data Contains relationName, foreignId, foreignEntity
     */
    public function afterRelate(Entity $entity, array $options, array $data): void
    {
        // Only handle chatwootInboxes relation
        if (($data['relationName'] ?? '') !== 'chatwootAgentChatwootInbox') {
            return;
        }

        // Skip if silent (internal sync operation)
        if (!empty($options['silent'])) {
            return;
        }

        $foreignId = $data['foreignId'] ?? null;
        if (!$foreignId) {
            return;
        }

        $inbox = $this->entityManager->getEntityById('ChatwootInbox', $foreignId);
        if (!$inbox) {
            $this->log->warning("SyncLabelWithWaha: ChatwootInbox {$foreignId} not found");
            return;
        }

        $this->createLabelForAgentInbox($entity, $inbox);
    }

    /**
     * Called after an agent is unlinked from an inbox.
     *
     * @param Entity $entity The ChatwootAgent entity
     * @param array<string, mixed> $options
     * @param array<string, mixed> $data Contains relationName, foreignId
     */
    public function afterUnrelate(Entity $entity, array $options, array $data): void
    {
        // Only handle chatwootInboxes relation
        if (($data['relationName'] ?? '') !== 'chatwootAgentChatwootInbox') {
            return;
        }

        // Skip if silent (internal sync operation)
        if (!empty($options['silent'])) {
            return;
        }

        $foreignId = $data['foreignId'] ?? null;
        if (!$foreignId) {
            return;
        }

        $inbox = $this->entityManager->getEntityById('ChatwootInbox', $foreignId);
        if (!$inbox) {
            // Inbox might be deleted, try to find label by agent anyway
            $this->log->warning("SyncLabelWithWaha: ChatwootInbox {$foreignId} not found, searching label by agent");
        }

        $this->deleteLabelForAgentInbox($entity, $inbox);
    }

    /**
     * Create a WAHA label for an agent-inbox combination.
     */
    private function createLabelForAgentInbox(Entity $agent, Entity $inbox): void
    {
        try {
            // Find ChatwootInboxIntegration via direct relationship first
            $inboxIntegration = $this->findIntegrationForInbox($inbox);

            if (!$inboxIntegration) {
                $this->log->debug("SyncLabelWithWaha: No ChatwootInboxIntegration found for inbox {$inbox->getId()}");
                return;
            }

            // Check if label already exists
            $existingLabel = $this->entityManager
                ->getRDBRepository('WahaSessionLabel')
                ->where([
                    'agentId' => $agent->getId(),
                    'inboxIntegrationId' => $inboxIntegration->getId(),
                ])
                ->findOne();

            if ($existingLabel) {
                $this->log->debug("SyncLabelWithWaha: Label already exists for agent {$agent->getId()} + integration {$inboxIntegration->getId()}");
                return;
            }

            // Get WAHA platform and session info
            $wahaPlatform = $this->entityManager->getEntityById(
                'WahaPlatform',
                $inboxIntegration->get('wahaPlatformId')
            );

            if (!$wahaPlatform) {
                $this->log->warning("SyncLabelWithWaha: WahaPlatform not found for integration {$inboxIntegration->getId()}");
                return;
            }

            $platformUrl = $wahaPlatform->get('backendUrl');
            $apiKey = $wahaPlatform->get('apiKey');
            $sessionName = $inboxIntegration->get('wahaSessionName');

            if (!$platformUrl || !$apiKey || !$sessionName) {
                $this->log->warning("SyncLabelWithWaha: Missing WAHA credentials or session name");
                return;
            }

            // Generate label name and color based on agent type
            $labelPrefix = $agent->get('isAI') ? '[✨]' : '[👤]';
            $labelName = $labelPrefix . ' ' . $agent->get('name');
            $color = $this->getColorForAgent($agent);
            $colorHex = self::COLOR_MAP[$color] ?? '#64c4ff';

            // Create label in WAHA
            $this->log->info("SyncLabelWithWaha: Creating WAHA label '{$labelName}' for session {$sessionName}");

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
                $this->log->error("SyncLabelWithWaha: WAHA response missing label ID");
                return;
            }

            // Get the ChatwootAccount for this inbox
            $chatwootAccount = $this->entityManager->getEntityById(
                'ChatwootAccount',
                $inboxIntegration->get('chatwootAccountId')
            );

            $chatwootLabelId = null;

            // Create linked ChatwootLabel if we have a ChatwootAccount
            if ($chatwootAccount) {
                try {
                    $chatwootLabel = $this->entityManager->createEntity('ChatwootLabel', [
                        'name' => $labelName,
                        'description' => 'Auto-created label for agent ' . $agent->get('name'),
                        'color' => $colorHex,
                        'showOnSidebar' => true,
                        'chatwootAccountId' => $chatwootAccount->getId(),
                        'teamId' => $inboxIntegration->get('teamId'),
                    ]);
                    
                    $chatwootLabelId = $chatwootLabel->getId();
                    $this->log->info("SyncLabelWithWaha: Created linked ChatwootLabel {$chatwootLabelId} for agent {$agent->getId()}");
                } catch (\Exception $e) {
                    // Log but continue - WahaSessionLabel can exist without ChatwootLabel
                    $this->log->warning("SyncLabelWithWaha: Failed to create ChatwootLabel: " . $e->getMessage());
                }
            }

            // Create WahaSessionLabel record
            $this->entityManager->createEntity('WahaSessionLabel', [
                'name' => $labelName,
                'wahaLabelId' => (string) $wahaLabelId,
                'color' => $color,
                'colorHex' => $wahaResponse['colorHex'] ?? $colorHex,
                'agentId' => $agent->getId(),
                'inboxIntegrationId' => $inboxIntegration->getId(),
                'teamId' => $inboxIntegration->get('teamId'),
                'chatwootLabelId' => $chatwootLabelId,
                'syncStatus' => 'synced',
            ], ['silent' => true]);

            $this->log->info("SyncLabelWithWaha: Created WahaSessionLabel for agent {$agent->getId()} with WAHA ID {$wahaLabelId}");

        } catch (\Exception $e) {
            $this->log->error("SyncLabelWithWaha: Failed to create label for agent {$agent->getId()}: " . $e->getMessage());
        }
    }

    /**
     * Delete a WAHA label for an agent-inbox combination.
     */
    private function deleteLabelForAgentInbox(Entity $agent, ?Entity $inbox): void
    {
        try {
            // Find the WahaSessionLabel
            $query = $this->entityManager
                ->getRDBRepository('WahaSessionLabel')
                ->where(['agentId' => $agent->getId()]);

            // If we have the inbox, narrow down the search
            if ($inbox) {
                $inboxIntegration = $this->findIntegrationForInbox($inbox);

                if ($inboxIntegration) {
                    $query->where(['inboxIntegrationId' => $inboxIntegration->getId()]);
                }
            }

            $wahaSessionLabel = $query->findOne();

            if (!$wahaSessionLabel) {
                $this->log->debug("SyncLabelWithWaha: No WahaSessionLabel found for agent {$agent->getId()}");
                return;
            }

            $wahaLabelId = $wahaSessionLabel->get('wahaLabelId');
            $inboxIntegration = $this->entityManager->getEntityById(
                'ChatwootInboxIntegration',
                $wahaSessionLabel->get('inboxIntegrationId')
            );

            // Delete from WAHA if we have the necessary info
            if ($wahaLabelId && $inboxIntegration) {
                $wahaPlatform = $this->entityManager->getEntityById(
                    'WahaPlatform',
                    $inboxIntegration->get('wahaPlatformId')
                );

                if ($wahaPlatform) {
                    $platformUrl = $wahaPlatform->get('backendUrl');
                    $apiKey = $wahaPlatform->get('apiKey');
                    $sessionName = $inboxIntegration->get('wahaSessionName');

                    if ($platformUrl && $apiKey && $sessionName) {
                        $this->log->info("SyncLabelWithWaha: Deleting WAHA label {$wahaLabelId} from session {$sessionName}");

                        try {
                            $this->wahaApiClient->deleteLabel(
                                $platformUrl,
                                $apiKey,
                                $sessionName,
                                $wahaLabelId
                            );
                        } catch (\Exception $e) {
                            // Log but continue - the label might already be deleted in WAHA
                            $this->log->warning("SyncLabelWithWaha: Failed to delete label from WAHA: " . $e->getMessage());
                        }
                    }
                }
            }

            // Delete the WahaSessionLabel record
            $this->entityManager->removeEntity($wahaSessionLabel, ['silent' => true]);
            $this->log->info("SyncLabelWithWaha: Deleted WahaSessionLabel {$wahaSessionLabel->getId()}");

        } catch (\Exception $e) {
            $this->log->error("SyncLabelWithWaha: Failed to delete label for agent {$agent->getId()}: " . $e->getMessage());
        }
    }

    /**
     * Generate a deterministic color for an agent.
     */
    private function getColorForAgent(Entity $agent): int
    {
        // Use CRC32 of agent ID for deterministic distribution
        return abs(crc32($agent->getId())) % 20;
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
