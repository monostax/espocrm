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
use stdClass;

/**
 * Controller for ChatwootContactInbox entity.
 *
 * Provides custom actions for creating conversations from a contact inbox.
 */
class ChatwootContactInbox extends \Espo\Core\Templates\Controllers\Base
{
    /**
     * POST ChatwootContactInbox/:id/createConversation
     *
     * Creates a new conversation in Chatwoot for the given ChatwootContactInbox,
     * then creates a local ChatwootConversation entity and returns the data needed
     * to open the conversation in a tab or drawer.
     *
     * @throws BadRequest
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function postActionCreateConversation(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');

        if (!$id) {
            throw new BadRequest("ID is required.");
        }

        $entityManager = $this->getEntityManager();

        // Load the ChatwootContactInbox entity
        $contactInbox = $entityManager->getEntityById('ChatwootContactInbox', $id);

        if (!$contactInbox) {
            throw new NotFound("ChatwootContactInbox not found.");
        }

        // === Server-side account ownership validation (Decision #17) ===
        $currentUserId = $this->getUser()->getId();

        $chatwootUser = $entityManager
            ->getRDBRepository('ChatwootUser')
            ->where(['assignedUserId' => $currentUserId])
            ->order('createdAt', 'ASC')
            ->findOne();

        if (!$chatwootUser) {
            throw new Forbidden("You do not have access to this account.");
        }

        $inboxAccountId = $contactInbox->get('chatwootAccountId');

        if (!$inboxAccountId) {
            throw new BadRequest("ChatwootContactInbox has no linked ChatwootAccount.");
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

        // === Resolve required linked data ===

        // ChatwootContact → chatwootContactId (external int)
        $chatwootContactId = $contactInbox->get('chatwootContactId');
        if (!$chatwootContactId) {
            throw new BadRequest("ChatwootContactInbox has no linked ChatwootContact.");
        }

        $chatwootContact = $entityManager->getEntityById('ChatwootContact', $chatwootContactId);
        if (!$chatwootContact) {
            throw new BadRequest("Linked ChatwootContact not found.");
        }

        $externalContactId = (int) $chatwootContact->get('chatwootContactId');
        if (!$externalContactId) {
            throw new BadRequest("ChatwootContact has no external chatwootContactId.");
        }

        // ChatwootInboxId (external int, directly on the entity)
        $externalInboxId = (int) $contactInbox->get('chatwootInboxId');
        if (!$externalInboxId) {
            throw new BadRequest("ChatwootContactInbox has no chatwootInboxId.");
        }

        // ChatwootAccount → apiKey, chatwootAccountId (external int), platformId → ChatwootPlatform → backendUrl
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

        // === Call Chatwoot API to create conversation ===
        $apiClient = $this->injectableFactory->create(ChatwootApiClient::class);

        $chatwootResponse = $apiClient->createConversation(
            $platformUrl,
            $accountApiKey,
            $externalAccountId,
            $externalContactId,
            $externalInboxId
        );

        // Use display_id preferentially for URL-facing conversation number (Decision #16)
        $chatwootConversationId = $chatwootResponse['display_id'] ?? $chatwootResponse['id'] ?? null;

        if (!$chatwootConversationId) {
            throw new Error("Chatwoot API response missing conversation ID.");
        }

        // === Create local ChatwootConversation entity (Decision #15) ===

        // Resolve contact name for the conversation name
        $contactEntityId = $contactInbox->get('contactId');
        $contactName = '';
        if ($contactEntityId) {
            $contact = $entityManager->getEntityById('Contact', $contactEntityId);
            if ($contact) {
                $contactName = $contact->get('name') ?? '';
            }
        }

        $name = date('Y-m-d') . ($contactName ? ' - ' . $contactName : '');

        // Resolve teams: try from contactInbox first, fall back to account
        $teamsIds = $contactInbox->getLinkMultipleIdList('teams');
        if (empty($teamsIds)) {
            $teamsIds = $chatwootAccount->getLinkMultipleIdList('teams');
        }

        $data = [
            'name' => $name,
            'chatwootConversationId' => (int) $chatwootConversationId,
            'chatwootAccountId' => $inboxAccountId,
            'chatwootContactId' => $chatwootContactId,
            'contactId' => $contactEntityId,
            'contactInboxId' => $id,
            'chatwootInboxId' => $externalInboxId,
            'inboxName' => $contactInbox->get('inboxName'),
            'inboxChannelType' => $contactInbox->get('inboxChannelType'),
            'status' => 'open',
        ];

        if (!empty($teamsIds)) {
            $data['teamsIds'] = $teamsIds;
        }

        // Use silent option to suppress hooks/notifications (Decision #19).
        // Chatwoot returns the already-open conversation for channels limited to
        // a single open conversation (e.g. WhatsApp), and the background sync job
        // may ingest the conversation concurrently — so find-or-create instead of
        // a blind insert (avoids UNIQ_UNIQUE_CONVERSATION violations).
        $conversationEntity = $this->findOrCreateLocalConversation($data);

        // Return data needed by the client to open the conversation
        $result = new stdClass();
        $result->chatwootConversationId = (int) $chatwootConversationId;
        $result->chatwootAccountIdExternal = $externalAccountId;
        $result->id = $conversationEntity->getId();

        return $result;
    }

    /**
     * Find an existing local ChatwootConversation or create it, tolerating
     * unique constraint violations (race with SyncConversationsFromChatwoot
     * or a repeated request for the same open conversation).
     *
     * Mirrors ContactChatwoot::createEntityWithDuplicateHandling.
     *
     * @param array<string, mixed> $data Entity data (must contain
     *   chatwootConversationId and chatwootAccountId).
     * @throws Error
     */
    private function findOrCreateLocalConversation(array $data): \Espo\ORM\Entity
    {
        $entityManager = $this->getEntityManager();

        $where = [
            'chatwootConversationId' => $data['chatwootConversationId'],
            'chatwootAccountId' => $data['chatwootAccountId'],
        ];

        $existing = $entityManager
            ->getRDBRepository('ChatwootConversation')
            ->where($where)
            ->findOne();

        if ($existing) {
            return $existing;
        }

        try {
            return $entityManager->createEntity('ChatwootConversation', $data, ['silent' => true]);
        } catch (Error $e) {
            if (stripos($e->getMessage(), 'duplicate') === false) {
                throw $e;
            }
        } catch (\PDOException $e) {
            $isDuplicate = (string) $e->getCode() === '23000'
                || str_contains($e->getMessage(), 'Duplicate entry');

            if (!$isDuplicate) {
                throw new Error("Failed to create ChatwootConversation: " . $e->getMessage());
            }
        }

        // Lost the race — fetch the row that won.
        $existing = $entityManager
            ->getRDBRepository('ChatwootConversation')
            ->where($where)
            ->findOne();

        if (!$existing) {
            throw new Error("Duplicate detected for ChatwootConversation but existing record not found.");
        }

        return $existing;
    }
}
