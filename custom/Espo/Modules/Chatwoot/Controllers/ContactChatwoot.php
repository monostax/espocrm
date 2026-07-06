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
use Espo\Modules\Chatwoot\Tools\ContactReconciler;
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

        // === Channel-aware contact resolution ===
        // Map the inbox's raw channel_type to our enum (whatsapp, instagram, …).
        $reconciler = $this->injectableFactory->create(ContactReconciler::class);
        $rawChannelType = $chatwootInbox->get('channelType');
        $mappedChannelType = $reconciler->mapChannelType($rawChannelType);

        $contactName = $contact->get('name') ?? '';

        // === Resolve teams (from inbox, fallback to account) ===
        $teamsIds = $chatwootInbox->getLinkMultipleIdList('teams');

        if (empty($teamsIds)) {
            $teamsIds = $chatwootAccount->getLinkMultipleIdList('teams');
        }

        $apiClient = $this->injectableFactory->create(ChatwootApiClient::class);

        $resolution = $this->resolveExternalContactForChannel(
            $apiClient,
            $contact,
            $contactEntityId,
            $contactName,
            $mappedChannelType,
            $chatwootAccount,
            $inboxAccountId,
            $chatwootInbox,
            $externalInboxId,
            $platformUrl,
            $accountApiKey,
            $externalAccountId
        );

        $externalContactId = $resolution['externalContactId'];
        $chatwootContactData = $resolution['chatwootContactData'];
        $wasCreated = $resolution['wasCreated'];
        $sourceId = $resolution['sourceId'];

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

        // Fall back to the mapped channel type we already know when the
        // Chatwoot response doesn't carry the inbox metadata (e.g.
        // contact-was-found path).
        if (!$inboxChannelType && $mappedChannelType) {
            $inboxChannelType = $mappedChannelType;
        }

        $localContactInbox = $this->findOrCreateLocalContactInbox(
            $localChatwootContact,
            $externalInboxId,
            $inboxEntityId,
            $inboxAccountId,
            $chatwootInbox,
            $contactEntityId,
            $inboxChannelType,
            $teamsIds,
            $sourceId
        );

        // --- 3. Materialize ContactChannelIdentity for the source_id
        //     we used. This keeps the AI agent / send-message reconciler
        //     in sync without waiting for the next bg sync pass.
        $tenantId = $this->extractTenantId($chatwootAccount);
        if ($sourceId && $mappedChannelType && $tenantId) {
            try {
                $reconciler->upsertIdentity(
                    $contactEntityId,
                    $tenantId,
                    $mappedChannelType,
                    $sourceId,
                    $chatwootInbox->get('name'),
                    $inboxAccountId,
                    $inboxEntityId
                );
            } catch (\Throwable $e) {
                // Non-fatal — the next sync will materialize it.
            }
        }

        // --- 4. ChatwootConversation (Decision #13: set inboxId) ---
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
     * @param string|null $sourceId Channel-scoped source identifier (when known)
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
        array $teamsIds,
        ?string $sourceId = null
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

            // Backfill sourceId when previously unknown.
            if ($sourceId && !$existing->get('sourceId')) {
                $existing->set('sourceId', $sourceId);
            }

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
            'sourceId' => $sourceId,
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
     * Resolve the Chatwoot-side contact (and its `source_id` for the
     * target inbox) for a given EspoCRM Contact + channel.
     *
     * Branches by channel category:
     *
     *  - whatsapp / sms (phone-based):
     *      requires `Contact.phoneNumber`. Searches Chatwoot by phone
     *      and falls back to creating a new contact with the phone as
     *      source_id.
     *
     *  - email:
     *      requires `Contact.emailAddress`. Creates a new contact with
     *      the email as source_id (Chatwoot search-by-email is not
     *      exposed by the API client; we lean on the local bridge first
     *      and only create on the platform when missing).
     *
     *  - instagram / telegram / facebook / line / viber (identity-based):
     *      requires a pre-existing `ContactChannelIdentity` of the
     *      target channelType within the inbox's ChatwootAccount. We
     *      look up an existing local `ChatwootContact` for this
     *      (Espo contact, account) pair; if absent, we create one in
     *      Chatwoot using the stored source_id as the inbox identifier.
     *      Then we ensure a `contact_inbox` exists by calling
     *      `createContactInbox` with the source_id.
     *
     * @return array{
     *   externalContactId: int,
     *   chatwootContactData: array<string, mixed>,
     *   wasCreated: bool,
     *   sourceId: ?string,
     * }
     *
     * @throws BadRequest|Error|Forbidden|NotFound
     */
    private function resolveExternalContactForChannel(
        ChatwootApiClient $apiClient,
        \Espo\ORM\Entity $contact,
        string $contactEntityId,
        string $contactName,
        ?string $mappedChannelType,
        \Espo\ORM\Entity $chatwootAccount,
        string $inboxAccountId,
        \Espo\ORM\Entity $chatwootInbox,
        int $externalInboxId,
        string $platformUrl,
        string $accountApiKey,
        int $externalAccountId
    ): array {
        $entityManager = $this->getEntityManager();

        $phoneBased = $mappedChannelType === 'whatsapp' || $mappedChannelType === 'sms';
        $emailBased = $mappedChannelType === 'email';
        $identityBased = in_array(
            $mappedChannelType,
            ['instagram', 'telegram', 'facebook', 'line', 'viber'],
            true
        );

        // --- Phone-based channels (existing flow) ---
        if ($phoneBased || $mappedChannelType === null) {
            // null channelType -> assume phone (current legacy behavior for
            // inboxes without a registered integration).
            $rawPhone = $contact->get('phoneNumber');
            $normalizedPhone = PhoneNormalizer::normalize($rawPhone);

            if ($normalizedPhone) {
                $searchResult = $apiClient->searchContactByPhone(
                    $platformUrl,
                    $accountApiKey,
                    $externalAccountId,
                    $normalizedPhone
                );

                if ($searchResult
                    && isset($searchResult['phone_number'])
                    && $searchResult['phone_number'] === $normalizedPhone
                ) {
                    $externalContactId = (int) ($searchResult['id'] ?? 0);
                    return [
                        'externalContactId' => $externalContactId,
                        'chatwootContactData' => $searchResult,
                        'wasCreated' => false,
                        'sourceId' => $normalizedPhone,
                    ];
                }
            }

            // LID-era fallback: the person may already exist in Chatwoot
            // keyed by a WhatsApp LID (identifier "…@lid") with the phone
            // number not yet enriched (or never resolvable for privacy-
            // enabled users), so the phone search misses them. Reuse the
            // local bridge link instead of creating a phone-keyed duplicate
            // — that would fork the WhatsApp thread (outbound on the phone
            // contact, replies on the LID contact).
            $existingBridge = $entityManager
                ->getRDBRepository('ChatwootContact')
                ->where([
                    'contactId' => $contactEntityId,
                    'chatwootAccountId' => $inboxAccountId,
                ])
                ->findOne();

            if ($existingBridge && $existingBridge->get('chatwootContactId')) {
                return [
                    'externalContactId' => (int) $existingBridge->get('chatwootContactId'),
                    'chatwootContactData' => [
                        'id' => (int) $existingBridge->get('chatwootContactId'),
                        'name' => $existingBridge->get('name'),
                        'phone_number' => $existingBridge->get('phoneNumber'),
                        'email' => $existingBridge->get('email'),
                        'identifier' => $existingBridge->get('identifier'),
                    ],
                    'wasCreated' => false,
                    'sourceId' => $normalizedPhone ?: ($existingBridge->get('identifier') ?: null),
                ];
            }

            if (!$normalizedPhone) {
                throw new BadRequest(
                    "Contact has no valid phone number and no linked Chatwoot "
                    . "contact. A phone number is required for this channel."
                );
            }

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

            $chatwootContactData = $createResponse['payload']['contact']
                ?? $createResponse['contact']
                ?? $createResponse;
            $externalContactId = (int) ($chatwootContactData['id'] ?? 0);

            return [
                'externalContactId' => $externalContactId,
                'chatwootContactData' => $chatwootContactData,
                'wasCreated' => true,
                'sourceId' => $normalizedPhone,
            ];
        }

        // --- Email channel ---
        if ($emailBased) {
            $email = $contact->get('emailAddress');
            $normalizedEmail = $email ? strtolower(trim((string) $email)) : null;

            if (!$normalizedEmail) {
                throw new BadRequest(
                    "Contact has no email address. An email address is required "
                    . "for email conversations."
                );
            }

            // Reuse a local ChatwootContact bridge if available; otherwise
            // create the Chatwoot contact with email as the identifier.
            $existingBridge = $entityManager
                ->getRDBRepository('ChatwootContact')
                ->where([
                    'contactId' => $contactEntityId,
                    'chatwootAccountId' => $inboxAccountId,
                ])
                ->findOne();

            if ($existingBridge && $existingBridge->get('chatwootContactId')) {
                $externalContactId = (int) $existingBridge->get('chatwootContactId');

                // Ensure contact_inbox exists for this inbox with the email
                // as source_id.
                $apiClient->createContactInbox(
                    $platformUrl,
                    $accountApiKey,
                    $externalAccountId,
                    $externalContactId,
                    $externalInboxId,
                    $normalizedEmail
                );

                $contactData = [
                    'id' => $externalContactId,
                    'name' => $existingBridge->get('name') ?? $contactName,
                    'email' => $normalizedEmail,
                ];

                return [
                    'externalContactId' => $externalContactId,
                    'chatwootContactData' => $contactData,
                    'wasCreated' => false,
                    'sourceId' => $normalizedEmail,
                ];
            }

            $createResponse = $apiClient->createContact(
                $platformUrl,
                $accountApiKey,
                $externalAccountId,
                [
                    'inbox_id' => $externalInboxId,
                    'email' => $normalizedEmail,
                    'identifier' => $normalizedEmail,
                    'name' => $contactName,
                ]
            );

            $chatwootContactData = $createResponse['payload']['contact']
                ?? $createResponse['contact']
                ?? $createResponse;
            $externalContactId = (int) ($chatwootContactData['id'] ?? 0);

            return [
                'externalContactId' => $externalContactId,
                'chatwootContactData' => $chatwootContactData,
                'wasCreated' => true,
                'sourceId' => $normalizedEmail,
            ];
        }

        // --- Identity-based channels (Instagram / Telegram / Facebook / …) ---
        if ($identityBased) {
            $tenantId = $this->extractTenantId($chatwootAccount);

            if (!$tenantId) {
                throw new BadRequest(
                    "ChatwootAccount is not linked to a tenant. Cannot initiate "
                    . "a conversation on this channel."
                );
            }

            $identity = $entityManager
                ->getRDBRepository('ContactChannelIdentity')
                ->where([
                    'contactId' => $contactEntityId,
                    'channelType' => $mappedChannelType,
                    'chatwootAccountId' => $inboxAccountId,
                ])
                ->findOne();

            // Fallback: tenant-scoped lookup (in case the identity was
            // observed in another account that shares the same tenant).
            if (!$identity) {
                $identity = $entityManager
                    ->getRDBRepository('ContactChannelIdentity')
                    ->where([
                        'contactId' => $contactEntityId,
                        'channelType' => $mappedChannelType,
                        'tenantId' => $tenantId,
                    ])
                    ->findOne();
            }

            if (!$identity) {
                $channelLabel = ucfirst($mappedChannelType);
                throw new BadRequest(
                    "Contact has no {$channelLabel} identifier. The customer "
                    . "must send a message first before a new conversation "
                    . "can be opened on this channel."
                );
            }

            $sourceId = (string) $identity->get('sourceId');

            // Reuse existing Chatwoot contact within this account when possible.
            $existingBridge = $entityManager
                ->getRDBRepository('ChatwootContact')
                ->where([
                    'contactId' => $contactEntityId,
                    'chatwootAccountId' => $inboxAccountId,
                ])
                ->findOne();

            if ($existingBridge && $existingBridge->get('chatwootContactId')) {
                $externalContactId = (int) $existingBridge->get('chatwootContactId');

                // Ensure contact_inbox exists for the target inbox using the
                // known source_id (idempotent on Chatwoot's side: duplicate
                // POSTs return the existing row).
                $apiClient->createContactInbox(
                    $platformUrl,
                    $accountApiKey,
                    $externalAccountId,
                    $externalContactId,
                    $externalInboxId,
                    $sourceId
                );

                $contactData = [
                    'id' => $externalContactId,
                    'name' => $existingBridge->get('name') ?? $contactName,
                    'identifier' => $sourceId,
                ];

                return [
                    'externalContactId' => $externalContactId,
                    'chatwootContactData' => $contactData,
                    'wasCreated' => false,
                    'sourceId' => $sourceId,
                ];
            }

            // No Chatwoot contact yet in this account — create one with
            // inbox_id+identifier so Chatwoot also creates the contact_inbox
            // row in a single call.
            $createResponse = $apiClient->createContact(
                $platformUrl,
                $accountApiKey,
                $externalAccountId,
                [
                    'inbox_id' => $externalInboxId,
                    'identifier' => $sourceId,
                    'name' => $contactName,
                ]
            );

            $chatwootContactData = $createResponse['payload']['contact']
                ?? $createResponse['contact']
                ?? $createResponse;
            $externalContactId = (int) ($chatwootContactData['id'] ?? 0);

            return [
                'externalContactId' => $externalContactId,
                'chatwootContactData' => $chatwootContactData,
                'wasCreated' => true,
                'sourceId' => $sourceId,
            ];
        }

        // --- Unsupported channel ---
        throw new BadRequest(
            "Channel '{$mappedChannelType}' is not supported for "
            . "outbound conversation initiation."
        );
    }

    /**
     * Extract the tenantId from a ChatwootAccount entity (or null when
     * the account hasn't been backfilled yet).
     */
    private function extractTenantId(\Espo\ORM\Entity $chatwootAccount): ?string
    {
        $tenantId = $chatwootAccount->get('tenantId');
        if (!is_string($tenantId)) {
            return null;
        }
        $trimmed = trim($tenantId);
        return $trimmed !== '' ? $trimmed : null;
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
