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

namespace Espo\Modules\Chatwoot\Hooks\Contact;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Hook to synchronize Contact merges with Chatwoot.
 * 
 * When Contacts are merged in EspoCRM, this hook detects multiple ChatwootContacts
 * linked to the same Contact (from the same Chatwoot account) and merges them
 * in Chatwoot as well.
 * 
 * The merge logic:
 * 1. After Contact save, check for duplicate ChatwootContacts per Chatwoot account
 * 2. Keep the ChatwootContact with the lowest chatwootContactId (oldest) as base
 * 3. Merge all others into it via Chatwoot API
 * 4. Mark merged ChatwootContacts as 'merged' and update references
 */
class SyncChatwootContactMerge
{
    public static int $order = 100; // Run after standard hooks

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    /**
     * After Contact is saved, check for duplicate ChatwootContacts and merge them.
     * 
     * @param Entity $entity
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
    {
        // Skip if silent (internal operations)
        if (!empty($options['silent'])) {
            return;
        }

        // Skip if this is a new Contact (no merge possible)
        if ($entity->isNew()) {
            return;
        }

        try {
            $this->processChatwootContactMerge($entity);
        } catch (\Exception $e) {
            // Log but don't fail the Contact save
            $this->log->error(
                'SyncChatwootContactMerge: Failed to sync merge for Contact ' . 
                $entity->getId() . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * Process ChatwootContact merge for a Contact.
     */
    private function processChatwootContactMerge(Entity $contact): void
    {
        // Get all ChatwootContacts linked to this Contact
        $chatwootContacts = $this->entityManager
            ->getRDBRepository('ChatwootContact')
            ->where([
                'contactId' => $contact->getId(),
                'syncStatus!=' => 'merged', // Exclude already merged
            ])
            ->find();

        $contactList = iterator_to_array($chatwootContacts);

        if (count($contactList) <= 1) {
            // No duplicates, nothing to merge
            return;
        }

        // Group by chatwootAccountId
        $groupedByAccount = [];
        foreach ($contactList as $cwtContact) {
            $accountId = $cwtContact->get('chatwootAccountId');
            if (!isset($groupedByAccount[$accountId])) {
                $groupedByAccount[$accountId] = [];
            }
            $groupedByAccount[$accountId][] = $cwtContact;
        }

        // Process each account group
        foreach ($groupedByAccount as $accountId => $contacts) {
            if (count($contacts) <= 1) {
                continue;
            }

            $this->mergeChatwootContactsForAccount($accountId, $contacts);
        }
    }

    /**
     * Merge multiple ChatwootContacts from the same Chatwoot account.
     * 
     * @param string $espoAccountId The EspoCRM ChatwootAccount ID
     * @param Entity[] $chatwootContacts Array of ChatwootContact entities to merge
     */
    private function mergeChatwootContactsForAccount(string $espoAccountId, array $chatwootContacts): void
    {
        // Get the ChatwootAccount for API credentials
        $account = $this->entityManager->getEntityById('ChatwootAccount', $espoAccountId);
        
        if (!$account) {
            $this->log->warning(
                "SyncChatwootContactMerge: ChatwootAccount {$espoAccountId} not found, skipping merge"
            );
            return;
        }

        // Get platform for URL
        $platform = $this->entityManager->getEntityById(
            'ChatwootPlatform',
            $account->get('platformId')
        );

        if (!$platform) {
            $this->log->warning(
                "SyncChatwootContactMerge: ChatwootPlatform not found for account {$espoAccountId}"
            );
            return;
        }

        $platformUrl = $platform->get('url');
        $apiKey = $account->get('apiKey');
        $chatwootAccountId = $account->get('chatwootAccountId');

        if (!$platformUrl || !$apiKey || !$chatwootAccountId) {
            $this->log->warning(
                "SyncChatwootContactMerge: Missing API credentials for account {$espoAccountId}"
            );
            return;
        }

        // Sort by chatwootContactId (lowest = oldest = base)
        usort($chatwootContacts, function ($a, $b) {
            return $a->get('chatwootContactId') <=> $b->get('chatwootContactId');
        });

        // First one is the base (survives), rest are mergees (deleted)
        $baseContact = array_shift($chatwootContacts);
        $baseContactId = $baseContact->get('chatwootContactId');

        $this->log->info(
            "SyncChatwootContactMerge: Merging " . count($chatwootContacts) . 
            " contacts into base contact {$baseContactId} for account {$espoAccountId}"
        );

        // Merge each mergee into base
        foreach ($chatwootContacts as $mergeeContact) {
            $mergeeContactId = $mergeeContact->get('chatwootContactId');

            try {
                // Call Chatwoot merge API
                $this->apiClient->mergeContacts(
                    $platformUrl,
                    $apiKey,
                    (int) $chatwootAccountId,
                    (int) $baseContactId,
                    (int) $mergeeContactId
                );

                // Update the merged ChatwootContact in EspoCRM
                $mergeeContact->set('syncStatus', 'merged');
                $mergeeContact->set('mergedIntoChatwootContactId', $baseContactId);
                $this->entityManager->saveEntity($mergeeContact, ['silent' => true]);

                $this->log->info(
                    "SyncChatwootContactMerge: Merged Chatwoot contact {$mergeeContactId} " .
                    "into {$baseContactId}"
                );

                // Also update any ChatwootConversation records that were linked to the mergee
                $this->updateConversationLinks($mergeeContact, $baseContact);

                // Update ChatwootContactInbox records
                $this->updateContactInboxLinks($mergeeContact, $baseContact);

            } catch (\Exception $e) {
                $this->log->error(
                    "SyncChatwootContactMerge: Failed to merge Chatwoot contact {$mergeeContactId} " .
                    "into {$baseContactId}: " . $e->getMessage()
                );
                
                // If merge failed because contact doesn't exist (already merged/deleted in Chatwoot),
                // still mark it as merged in EspoCRM
                if (strpos($e->getMessage(), '404') !== false || 
                    strpos($e->getMessage(), 'not found') !== false) {
                    $mergeeContact->set('syncStatus', 'merged');
                    $mergeeContact->set('mergedIntoChatwootContactId', $baseContactId);
                    $this->entityManager->saveEntity($mergeeContact, ['silent' => true]);
                }
            }
        }
    }

    /**
     * Update ChatwootConversation links from mergee to base contact.
     */
    private function updateConversationLinks(Entity $mergeeContact, Entity $baseContact): void
    {
        $conversations = $this->entityManager
            ->getRDBRepository('ChatwootConversation')
            ->where(['chatwootContactId' => $mergeeContact->getId()])
            ->find();

        foreach ($conversations as $conversation) {
            $conversation->set('chatwootContactId', $baseContact->getId());
            $this->entityManager->saveEntity($conversation, ['silent' => true]);
        }
    }

    /**
     * Update ChatwootContactInbox links from mergee to base contact.
     */
    private function updateContactInboxLinks(Entity $mergeeContact, Entity $baseContact): void
    {
        $contactInboxes = $this->entityManager
            ->getRDBRepository('ChatwootContactInbox')
            ->where(['chatwootContactId' => $mergeeContact->getId()])
            ->find();

        foreach ($contactInboxes as $contactInbox) {
            // Check if base contact already has this inbox linked
            $existing = $this->entityManager
                ->getRDBRepository('ChatwootContactInbox')
                ->where([
                    'chatwootContactId' => $baseContact->getId(),
                    'chatwootInboxId' => $contactInbox->get('chatwootInboxId'),
                ])
                ->findOne();

            if ($existing) {
                // Already exists, delete the duplicate
                $this->entityManager->removeEntity($contactInbox);
            } else {
                // Move to base contact
                $contactInbox->set('chatwootContactId', $baseContact->getId());
                $this->entityManager->saveEntity($contactInbox, ['silent' => true]);
            }
        }
    }
}


