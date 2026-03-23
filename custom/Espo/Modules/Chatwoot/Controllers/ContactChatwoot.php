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
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Chatwoot\Tools\Acl\InboxAccessResolver;
use Espo\Modules\Chatwoot\Tools\PhoneNormalizer;
use stdClass;

/**
 * Controller for initiating Chatwoot conversations from an EspoCRM Contact.
 *
 * Handles the full flow: validate access → find/create Chatwoot contact →
 * create Chatwoot conversation → create local bridge entities.
 *
 * Route: POST Contact/:id/initiateConversation
 */
class ContactChatwoot extends \Espo\Core\Templates\Controllers\Base
{
    /**
     * POST Contact/:id/initiateConversation
     *
     * Initiates a new conversation on a ChatwootInbox for a Contact that may
     * or may not have an existing linked ChatwootContact.
     *
     * Request body: { "inboxId": "<EspoCRM ChatwootInbox entity ID>" }
     *
     * @throws BadRequest
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function postActionInitiateConversation(Request $request, Response $response): stdClass
    {
        $contactEntityId = $request->getRouteParam('id');

        if (!$contactEntityId) {
            throw new BadRequest("Contact ID is required.");
        }

        $body = $request->getParsedBody();
        $inboxEntityId = $body->inboxId ?? null;

        if (!$inboxEntityId) {
            throw new BadRequest("inboxId is required in request body.");
        }

        $entityManager = $this->getEntityManager();

        // === Layer 1: Contact ACL (Decision #9) ===
        $contact = $entityManager->getEntityById('Contact', $contactEntityId);

        if (!$contact) {
            throw new NotFound("Contact not found.");
        }

        if (!$this->acl->check($contact, 'read')) {
            throw new Forbidden("You do not have access to this contact.");
        }

        // === Load and validate ChatwootInbox ===
        $chatwootInbox = $entityManager->getEntityById('ChatwootInbox', $inboxEntityId);

        if (!$chatwootInbox) {
            throw new NotFound("ChatwootInbox not found.");
        }

        $inboxAccountId = $chatwootInbox->get('chatwootAccountId');

        if (!$inboxAccountId) {
            throw new BadRequest("ChatwootInbox has no linked ChatwootAccount.");
        }

        $externalInboxId = (int) $chatwootInbox->get('chatwootInboxId');

        if (!$externalInboxId) {
            throw new BadRequest("ChatwootInbox has no external chatwootInboxId.");
        }

        // === Layer 2: Chatwoot membership validation ===
        $currentUserId = $this->getUser()->getId();

        $chatwootUser = $entityManager
            ->getRDBRepository('ChatwootUser')
            ->where(['assignedUserId' => $currentUserId])
            ->order('createdAt', 'ASC')
            ->findOne();

        if (!$chatwootUser) {
            throw new Forbidden("You do not have access to this account.");
        }

        $membership = $entityManager
            ->getRDBRepository('ChatwootAccountUserMembership')
            ->where([
                'chatwootUserId' => $chatwootUser->getId(),
                'chatwootAccountId' => $inboxAccountId,
            ])
            ->findOne();

        if (!$membership) {
            throw new Forbidden("You do not have access to this account.");
        }

        // === Layer 3: Inbox access validation (Decision #3) ===
        $inboxAccessResolver = $this->injectableFactory->create(InboxAccessResolver::class);

        if (!$inboxAccessResolver->canAccessInboxId($this->getUser(), $inboxEntityId)) {
            throw new Forbidden("You do not have access to this inbox.");
        }

        // === Platform resolution ===
        $chatwootAccount = $entityManager->getEntityById('ChatwootAccount', $inboxAccountId);

        if (!$chatwootAccount) {
            throw new BadRequest("Linked ChatwootAccount not found.");
        }

        $accountApiKey = $chatwootAccount->get('apiKey');

        if (!$accountApiKey) {
            throw new BadRequest("ChatwootAccount has no API key.");
        }

        $externalAccountId = (int) $chatwootAccount->get('chatwootAccountId');

        if (!$externalAccountId) {
            throw new BadRequest("ChatwootAccount has no external chatwootAccountId.");
        }

        $platformId = $chatwootAccount->get('platformId');

        if (!$platformId) {
            throw new BadRequest("ChatwootAccount has no linked platform.");
        }

        $platform = $entityManager->getEntityById('ChatwootPlatform', $platformId);

        if (!$platform) {
            throw new BadRequest("Linked ChatwootPlatform not found.");
        }

        $platformUrl = $platform->get('backendUrl');

        if (!$platformUrl) {
            throw new BadRequest("ChatwootPlatform has no backendUrl.");
        }

        // === Phone validation (Decision #8, #16) ===
        $rawPhone = $contact->get('phoneNumber');
        $normalizedPhone = PhoneNormalizer::normalize($rawPhone);

        if (!$normalizedPhone) {
            throw new BadRequest("Contact has no valid phone number. A phone number is required for WhatsApp conversations.");
        }

        $contactName = $contact->get('name') ?? '';

        // === Resolve teams (from inbox, fallback to account) ===
        $teamsIds = $chatwootInbox->getLinkMultipleIdList('teams');

        if (empty($teamsIds)) {
            $teamsIds = $chatwootAccount->getLinkMultipleIdList('teams');
        }

        // === Strict contact matching in Chatwoot (Decision #10) ===
        $apiClient = $this->injectableFactory->create(ChatwootApiClient::class);

        $chatwootContactData = null;
        $wasCreated = false;

        // Step 1: Search for existing contact by phone
        $searchResult = $apiClient->searchContactByPhone(
            $platformUrl,
            $accountApiKey,
            $externalAccountId,
            $normalizedPhone
        );

        // Step 2: Accept ONLY exact phone match (discard unsafe fallback)
        if ($searchResult && isset($searchResult['phone_number']) && $searchResult['phone_number'] === $normalizedPhone) {
            $chatwootContactData = $searchResult;
            $wasCreated = false;
        } else {
            // Step 3: No exact match — create new contact in Chatwoot
            $createResponse = $apiClient->createContact(
                $platformUrl,
                $accountApiKey,
                $externalAccountId,
                [
                    'inbox_id' => $externalInboxId,
                    'phone_number' => $normalizedPhone,
                    'name' => $contactName,
                ]
            );

            // Normalize nested response structure
            $chatwootContactData = $createResponse['payload']['contact']
                ?? $createResponse['contact']
                ?? $createResponse;

            $wasCreated = true;
        }

        $externalContactId = (int) ($chatwootContactData['id'] ?? 0);

        if (!$externalContactId) {
            throw new Error("Failed to resolve Chatwoot contact ID.");
        }

        // === Create conversation in Chatwoot ===
        $chatwootConversationResponse = $apiClient->createConversation(
            $platformUrl,
            $accountApiKey,
            $externalAccountId,
            $externalContactId,
            $externalInboxId
        );

        $chatwootConversationId = $chatwootConversationResponse['display_id']
            ?? $chatwootConversationResponse['id']
            ?? null;

        if (!$chatwootConversationId) {
            throw new Error("Chatwoot API response missing conversation ID.");
        }

        // === Create local entities only after ALL remote calls succeed (Decision #14) ===

        // --- 1. ChatwootContact ---
        $localChatwootContact = $this->findOrCreateLocalChatwootContact(
            $externalContactId,
            $inboxAccountId,
            $contactEntityId,
            $chatwootContactData,
            $teamsIds
        );

        // --- 2. ChatwootContactInbox ---
        $inboxChannelType = $this->resolveInboxChannelType(
            $wasCreated,
            $chatwootContactData,
            $externalInboxId,
            $inboxAccountId
        );

        $localContactInbox = $this->findOrCreateLocalContactInbox(
            $localChatwootContact,
            $externalInboxId,
            $inboxEntityId,
            $inboxAccountId,
            $chatwootInbox,
            $contactEntityId,
            $inboxChannelType,
            $teamsIds
        );

        // --- 3. ChatwootConversation (Decision #13: set inboxId) ---
        $conversationName = date('Y-m-d') . ($contactName ? ' - ' . $contactName : '');

        $conversationData = [
            'name' => $conversationName,
            'chatwootConversationId' => (int) $chatwootConversationId,
            'chatwootAccountId' => $inboxAccountId,
            'chatwootContactId' => $localChatwootContact->getId(),
            'contactId' => $contactEntityId,
            'contactInboxId' => $localContactInbox->getId(),
            'chatwootInboxId' => $externalInboxId,
            'inboxId' => $inboxEntityId, // Decision #13: fixes ACL gap
            'inboxName' => $chatwootInbox->get('name'),
            'inboxChannelType' => $inboxChannelType,
            'status' => 'open',
        ];

        if (!empty($teamsIds)) {
            $conversationData['teamsIds'] = $teamsIds;
        }

        $conversationEntity = $this->createEntityWithDuplicateHandling(
            'ChatwootConversation',
            $conversationData
        );

        // === Return response (same shape as existing createConversation endpoint) ===
        $result = new stdClass();
        $result->chatwootConversationId = (int) $chatwootConversationId;
        $result->chatwootAccountIdExternal = $externalAccountId;
        $result->id = $conversationEntity->getId();

        return $result;
    }

    /**
     * Find or create a local ChatwootContact entity.
     *
     * Handles soft-deleted records (restores them) and unique constraint
     * violations (Decision #17).
     *
     * @param int $externalContactId Chatwoot external contact ID
     * @param string $accountEntityId EspoCRM ChatwootAccount entity ID
     * @param string $contactEntityId EspoCRM Contact entity ID
     * @param array<string, mixed> $chatwootContactData Chatwoot API response data
     * @param array<string> $teamsIds Team IDs
     * @return \Espo\ORM\Entity
     */
    private function findOrCreateLocalChatwootContact(
        int $externalContactId,
        string $accountEntityId,
        string $contactEntityId,
        array $chatwootContactData,
        array $teamsIds
    ): \Espo\ORM\Entity {
        $entityManager = $this->getEntityManager();

        // Check for existing (including soft-deleted) — same pattern as SyncContactsFromChatwoot
        $query = $entityManager
            ->getQueryBuilder()
            ->select()
            ->from('ChatwootContact')
            ->where([
                'chatwootContactId' => $externalContactId,
                'chatwootAccountId' => $accountEntityId,
            ])
            ->withDeleted()
            ->build();

        $existing = $entityManager
            ->getRDBRepository('ChatwootContact')
            ->clone($query)
            ->findOne();

        if ($existing) {
            // Restore if soft-deleted
            if ($existing->get('deleted')) {
                $existing->set('deleted', false);
            }

            // Update link to EspoCRM Contact
            $existing->set('contactId', $contactEntityId);
            $existing->set('lastSyncedAt', date('Y-m-d H:i:s'));

            if (!empty($teamsIds)) {
                $existing->set('teamsIds', $teamsIds);
            }

            $entityManager->saveEntity($existing, ['silent' => true]);

            return $existing;
        }

        // Create new ChatwootContact — field mapping follows SyncContactsFromChatwoot::createNewContact()
        $data = [
            'chatwootContactId' => $externalContactId,
            'chatwootAccountId' => $accountEntityId,
            'contactId' => $contactEntityId,
            'name' => $chatwootContactData['name'] ?? null,
            'phoneNumber' => $chatwootContactData['phone_number'] ?? null,
            'email' => $chatwootContactData['email'] ?? null,
            'identifier' => $chatwootContactData['identifier'] ?? null,
            'syncStatus' => 'synced',
            'lastSyncedAt' => date('Y-m-d H:i:s'),
        ];

        if (!empty($teamsIds)) {
            $data['teamsIds'] = $teamsIds;
        }

        return $this->createEntityWithDuplicateHandling('ChatwootContact', $data);
    }

    /**
     * Find or create a local ChatwootContactInbox entity.
     *
     * @param \Espo\ORM\Entity $localChatwootContact Local ChatwootContact entity
     * @param int $externalInboxId Chatwoot external inbox ID
     * @param string $inboxEntityId EspoCRM ChatwootInbox entity ID
     * @param string $accountEntityId EspoCRM ChatwootAccount entity ID
     * @param \Espo\ORM\Entity $chatwootInbox ChatwootInbox entity
     * @param string $contactEntityId EspoCRM Contact entity ID
     * @param string|null $inboxChannelType Mapped channel type
     * @param array<string> $teamsIds Team IDs
     * @return \Espo\ORM\Entity
     */
    private function findOrCreateLocalContactInbox(
        \Espo\ORM\Entity $localChatwootContact,
        int $externalInboxId,
        string $inboxEntityId,
        string $accountEntityId,
        \Espo\ORM\Entity $chatwootInbox,
        string $contactEntityId,
        ?string $inboxChannelType,
        array $teamsIds
    ): \Espo\ORM\Entity {
        $entityManager = $this->getEntityManager();

        // Check for existing
        $existing = $entityManager
            ->getRDBRepository('ChatwootContactInbox')
            ->where([
                'chatwootContactId' => $localChatwootContact->getId(),
                'chatwootInboxId' => $externalInboxId,
                'chatwootAccountId' => $accountEntityId,
            ])
            ->findOne();

        if ($existing) {
            // Update denormalized fields
            $existing->set('contactId', $contactEntityId);
            $existing->set('inboxId', $inboxEntityId);
            $existing->set('lastSyncedAt', date('Y-m-d H:i:s'));

            if (!empty($teamsIds)) {
                $existing->set('teamsIds', $teamsIds);
            }

            $entityManager->saveEntity($existing, ['silent' => true]);

            return $existing;
        }

        // Generate display name: "{contact} <> {inbox} ({channel type})"
        $contactName = $localChatwootContact->get('name') ?? 'Unknown';
        $inboxName = $chatwootInbox->get('name') ?? 'Inbox #' . $externalInboxId;
        $inboxDisplayName = $inboxName;

        if ($inboxChannelType) {
            $inboxDisplayName .= ' (' . $inboxChannelType . ')';
        }

        $name = $contactName . ' <> ' . $inboxDisplayName;

        // Create new — field mapping follows SyncContactsFromChatwoot::syncContactInboxes()
        $data = [
            'name' => $name,
            'chatwootContactId' => $localChatwootContact->getId(),
            'contactId' => $contactEntityId, // Denormalized
            'chatwootAccountId' => $accountEntityId,
            'chatwootInboxId' => $externalInboxId,
            'inboxId' => $inboxEntityId,
            'inboxName' => $inboxName,
            'inboxChannelType' => $inboxChannelType,
            'sourceId' => null, // Decision #11: sync job backfills
            'lastSyncedAt' => date('Y-m-d H:i:s'),
        ];

        if (!empty($teamsIds)) {
            $data['teamsIds'] = $teamsIds;
        }

        return $this->createEntityWithDuplicateHandling('ChatwootContactInbox', $data);
    }

    /**
     * Resolve inboxChannelType per Decision #12.
     *
     * - When contact was created: extract from API response contact_inboxes[].inbox.channel_type
     * - When contact was found: check existing local ChatwootContactInbox records
     * - Fallback: null (sync job backfills)
     *
     * @param bool $wasCreated Whether the contact was just created in Chatwoot
     * @param array<string, mixed> $chatwootContactData Chatwoot API contact data
     * @param int $externalInboxId Chatwoot external inbox ID
     * @param string $accountEntityId EspoCRM ChatwootAccount entity ID
     * @return string|null Mapped channel type
     */
    private function resolveInboxChannelType(
        bool $wasCreated,
        array $chatwootContactData,
        int $externalInboxId,
        string $accountEntityId
    ): ?string {
        if ($wasCreated) {
            // Extract from createContact response's contact_inboxes array
            $contactInboxes = $chatwootContactData['contact_inboxes'] ?? [];

            foreach ($contactInboxes as $ci) {
                $ciInboxId = $ci['inbox']['id'] ?? null;

                if ($ciInboxId === $externalInboxId) {
                    $rawChannelType = $ci['inbox']['channel_type'] ?? null;

                    return $this->mapChannelType($rawChannelType);
                }
            }
        }

        // When contact was found (not created), check existing local records
        $existingContactInbox = $this->getEntityManager()
            ->getRDBRepository('ChatwootContactInbox')
            ->where([
                'chatwootInboxId' => $externalInboxId,
                'chatwootAccountId' => $accountEntityId,
            ])
            ->where(['inboxChannelType!=' => null])
            ->findOne();

        if ($existingContactInbox) {
            return $existingContactInbox->get('inboxChannelType');
        }

        return null;
    }

    /**
     * Map Chatwoot channel_type to local enum values.
     *
     * Same mapping as SyncContactsFromChatwoot::mapChannelType().
     */
    private function mapChannelType(?string $channelType): ?string
    {
        if (!$channelType) {
            return null;
        }

        $map = [
            'Channel::Whatsapp' => 'whatsapp',
            'Channel::Email' => 'email',
            'Channel::WebWidget' => 'web_widget',
            'Channel::Api' => 'api',
            'Channel::Telegram' => 'telegram',
            'Channel::Sms' => 'sms',
            'Channel::FacebookPage' => 'facebook',
            'Channel::Instagram' => 'instagram',
        ];

        return $map[$channelType] ?? strtolower(str_replace('Channel::', '', $channelType));
    }

    /**
     * Create an entity with duplicate handling (Decision #17).
     *
     * Catches unique constraint violations and returns the existing entity instead.
     *
     * @param string $entityType Entity type name
     * @param array<string, mixed> $data Entity data
     * @return \Espo\ORM\Entity Created or existing entity
     * @throws Error If creation fails for non-duplicate reasons
     */
    private function createEntityWithDuplicateHandling(string $entityType, array $data): \Espo\ORM\Entity
    {
        $entityManager = $this->getEntityManager();

        try {
            return $entityManager->createEntity($entityType, $data, ['silent' => true]);
        } catch (\Espo\Core\Exceptions\Error $e) {
            // Check if this is a unique constraint violation
            if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'duplicate')) {
                // Try to find the existing entity
                return $this->findExistingByUniqueFields($entityType, $data);
            }

            throw $e;
        } catch (\PDOException $e) {
            // MySQL error code 23000 = integrity constraint violation
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry')) {
                return $this->findExistingByUniqueFields($entityType, $data);
            }

            throw new Error("Failed to create {$entityType}: " . $e->getMessage());
        }
    }

    /**
     * Find an existing entity by its unique key fields.
     *
     * Uses the known unique indexes:
     * - ChatwootContact: (chatwootContactId, chatwootAccountId)
     * - ChatwootContactInbox: (chatwootContactId, chatwootInboxId, chatwootAccountId)
     * - ChatwootConversation: falls back to chatwootConversationId + chatwootAccountId
     *
     * @param string $entityType Entity type name
     * @param array<string, mixed> $data Entity data containing unique key fields
     * @return \Espo\ORM\Entity
     * @throws Error If no matching entity found
     */
    private function findExistingByUniqueFields(string $entityType, array $data): \Espo\ORM\Entity
    {
        $entityManager = $this->getEntityManager();
        $where = [];

        switch ($entityType) {
            case 'ChatwootContact':
                $where = [
                    'chatwootContactId' => $data['chatwootContactId'],
                    'chatwootAccountId' => $data['chatwootAccountId'],
                ];
                break;

            case 'ChatwootContactInbox':
                $where = [
                    'chatwootContactId' => $data['chatwootContactId'],
                    'chatwootInboxId' => $data['chatwootInboxId'],
                    'chatwootAccountId' => $data['chatwootAccountId'],
                ];
                break;

            case 'ChatwootConversation':
                $where = [
                    'chatwootConversationId' => $data['chatwootConversationId'],
                    'chatwootAccountId' => $data['chatwootAccountId'],
                ];
                break;

            default:
                throw new Error("No unique key mapping for entity type: {$entityType}");
        }

        $entity = $entityManager
            ->getRDBRepository($entityType)
            ->where($where)
            ->findOne();

        if (!$entity) {
            throw new Error("Duplicate detected for {$entityType} but existing record not found.");
        }

        return $entity;
    }
}
