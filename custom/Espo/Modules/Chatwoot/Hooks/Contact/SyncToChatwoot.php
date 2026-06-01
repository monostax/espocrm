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

use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Chatwoot\Tools\PhoneNormalizer;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Auto-push Contact changes to every team-matching ChatwootAccount.
 *
 * Fires on every Contact afterSave (per product decision) and:
 *   1. Updates the linked ChatwootContact row(s) on Chatwoot when a local
 *      ChatwootContact already exists for the (account, contact) tuple.
 *   2. Otherwise creates a new Chatwoot contact via the account's first
 *      WhatsApp inbox, after a STRICT phone equality match against
 *      Chatwoot's contact search (we never reuse a non-exact hit).
 *
 * Loop suppression: every write inside this module passes
 * `['silent' => true]` on Contact saves (see SyncContactsFromChatwoot,
 * SyncConversationsFromChatwoot). We short-circuit on that flag, so
 * Chatwoot → Espo pulls never re-trigger this hook.
 *
 * Account targeting: an account is eligible when ALL hold:
 *   - status            = 'active'
 *   - contactSyncEnabled = true     (shared toggle with pull side)
 *   - contactPushEnabled = true     (NEW, default true)
 *   - account.teams ∩ contact.teams ≠ ∅
 *
 * Failures never propagate — push errors are logged and the parent
 * saveEntity() always succeeds.
 */
class SyncToChatwoot
{
    /** Run after standard hooks, before SyncChatwootContactMerge (order 100). */
    public static int $order = 90;

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
    {
        // Sole recursion guard. Pull jobs always pass silent=true, so they
        // will never re-trigger us. See recursion audit in commit msg.
        if (!empty($options['silent'])) {
            return;
        }

        try {
            $this->process($entity);
        } catch (\Throwable $e) {
            // Never let push failures break the parent Contact save.
            $this->log->error(
                "SyncToChatwoot: Contact {$entity->getId()}: {$e->getMessage()}"
            );
        }
    }

    private function process(Entity $contact): void
    {
        $phone = PhoneNormalizer::normalize($contact->get('phoneNumber'));

        if (!$phone) {
            // Without a normalizable phone there is no inbox-bound identity
            // for WhatsApp channels — silent skip.
            return;
        }

        $contactTeamIds = $contact->getLinkMultipleIdList('teams');

        if (empty($contactTeamIds)) {
            $this->log->debug(
                "SyncToChatwoot: Contact {$contact->getId()} has no teams, skip"
            );
            return;
        }

        $accounts = $this->entityManager
            ->getRDBRepository('ChatwootAccount')
            ->where([
                'contactSyncEnabled' => true,
                'contactPushEnabled' => true,
                'status' => 'active',
            ])
            ->find();

        foreach ($accounts as $account) {
            $accountTeamIds = $account->getLinkMultipleIdList('teams');

            if (empty(array_intersect($accountTeamIds, $contactTeamIds))) {
                continue;
            }

            try {
                $this->pushToAccount($contact, $account, $phone);
            } catch (\Throwable $e) {
                // Per-account failure must not abort other accounts.
                $this->log->error(
                    "SyncToChatwoot: Contact {$contact->getId()} → " .
                    "account {$account->getId()}: {$e->getMessage()}"
                );
            }
        }
    }

    private function pushToAccount(Entity $contact, Entity $account, string $phone): void
    {
        // Credential resolution mirrors SyncChatwootContactMerge::mergeChatwootContactsForAccount.
        $platform = $this->entityManager
            ->getEntityById('ChatwootPlatform', $account->get('platformId'));

        if (!$platform) {
            $this->log->warning(
                "SyncToChatwoot: ChatwootPlatform missing for account {$account->getId()}"
            );
            return;
        }

        $platformUrl = $platform->get('backendUrl');
        $apiKey = $account->get('apiKey');
        $extAccountId = (int) $account->get('chatwootAccountId');

        if (!$platformUrl || !$apiKey || !$extAccountId) {
            $this->log->warning(
                "SyncToChatwoot: Missing API credentials for account {$account->getId()}"
            );
            return;
        }

        $payload = $this->buildPayload($contact, $phone);

        // Path A — existing local link: PATCH only, no inbox required.
        $existingLink = $this->entityManager
            ->getRDBRepository('ChatwootContact')
            ->where([
                'chatwootAccountId' => $account->getId(),
                'contactId' => $contact->getId(),
                'syncStatus!=' => 'merged',
            ])
            ->findOne();

        if ($existingLink) {
            $extContactId = (int) $existingLink->get('chatwootContactId');

            if (!$extContactId) {
                $this->log->warning(
                    "SyncToChatwoot: ChatwootContact {$existingLink->getId()} has no chatwootContactId, skip"
                );
                return;
            }

            $this->apiClient->updateContact(
                $platformUrl,
                $apiKey,
                $extAccountId,
                $extContactId,
                $payload
            );

            $existingLink->set('name', $payload['name']);
            $existingLink->set('phoneNumber', $payload['phone_number']);
            $existingLink->set('email', $payload['email']);
            $existingLink->set('syncStatus', 'synced');
            $existingLink->set('lastSyncedAt', date('Y-m-d H:i:s'));
            $this->entityManager->saveEntity($existingLink, ['silent' => true]);

            $this->log->info(
                "SyncToChatwoot: Updated Chatwoot contact {$extContactId} on account {$account->getId()}"
            );
            return;
        }

        // Path B — new link: pick a WhatsApp inbox and strict-match before creating.
        $inbox = $this->findWhatsAppInbox($account);

        if (!$inbox) {
            $this->log->debug(
                "SyncToChatwoot: Account {$account->getId()} has no WhatsApp inbox, skip create"
            );
            return;
        }

        $externalContactId = $this->strictMatchExternalContactId(
            $platformUrl,
            $apiKey,
            $extAccountId,
            $phone
        );

        $cwtRespBody = null;

        if (!$externalContactId) {
            $createPayload = $payload;
            $createPayload['inbox_id'] = (int) $inbox->get('chatwootInboxId');

            $response = $this->apiClient->createContact(
                $platformUrl,
                $apiKey,
                $extAccountId,
                $createPayload
            );

            $cwtRespBody = $response['payload']['contact']
                ?? $response['contact']
                ?? $response;

            $externalContactId = (int) ($cwtRespBody['id'] ?? 0);

            if (!$externalContactId) {
                throw new \RuntimeException(
                    'Chatwoot createContact returned no contact id: ' . json_encode($response)
                );
            }

            $this->log->info(
                "SyncToChatwoot: Created Chatwoot contact {$externalContactId} on account {$account->getId()}"
            );
        } else {
            $this->log->info(
                "SyncToChatwoot: Linking existing Chatwoot contact {$externalContactId} on account {$account->getId()}"
            );
        }

        // Teams: inbox teams first, account teams fallback (controller convention).
        $teamIds = $inbox->getLinkMultipleIdList('teams');
        if (empty($teamIds)) {
            $teamIds = $account->getLinkMultipleIdList('teams');
        }

        $localCwt = $this->upsertLocalChatwootContact(
            $externalContactId,
            $account->getId(),
            $contact->getId(),
            $cwtRespBody ?? $payload + ['phone_number' => $phone],
            $teamIds
        );

        $this->upsertLocalChatwootContactInbox(
            $localCwt,
            (int) $inbox->get('chatwootInboxId'),
            $inbox->getId(),
            $account->getId(),
            $inbox,
            $contact->getId(),
            $inbox->get('channelType'),
            $teamIds
        );
    }

    /**
     * Build the Chatwoot contact payload from an Espo Contact entity.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(Entity $contact, string $phone): array
    {
        $firstName = (string) $contact->get('firstName');
        $lastName = (string) $contact->get('lastName');
        $name = trim($firstName . ' ' . $lastName);

        if ($name === '') {
            $name = (string) ($contact->get('name') ?: $phone);
        }

        return [
            'name' => $name,
            'phone_number' => $phone,
            'email' => $contact->get('emailAddress') ?: null,
        ];
    }

    /**
     * Find a WhatsApp inbox for the account by joining through
     * ChatwootInboxIntegration (the local enum lives there).
     */
    private function findWhatsAppInbox(Entity $account): ?Entity
    {
        $integration = $this->entityManager
            ->getRDBRepository('ChatwootInboxIntegration')
            ->where([
                'chatwootAccountId' => $account->getId(),
                'channelType' => ['whatsappCloudApi', 'whatsappCoexistence', 'whatsappQrcode'],
            ])
            ->findOne();

        if (!$integration) {
            return null;
        }

        return $this->entityManager
            ->getRDBRepository('ChatwootInbox')
            ->where(['chatwootInboxIntegrationId' => $integration->getId()])
            ->findOne();
    }

    /**
     * Strict phone equality lookup — `searchContactByPhone` falls back to
     * the first hit on a non-exact match (see Services/ChatwootApiClient.php
     * L809-817); we MUST reject that fallback or risk merging into the
     * wrong Chatwoot contact. Same guard as
     * Controllers/ContactChatwoot.php:195-201.
     */
    private function strictMatchExternalContactId(
        string $platformUrl,
        string $apiKey,
        int $extAccountId,
        string $phone
    ): ?int {
        $hit = $this->apiClient->searchContactByPhone(
            $platformUrl,
            $apiKey,
            $extAccountId,
            $phone
        );

        if (!$hit) {
            return null;
        }

        if (($hit['phone_number'] ?? null) !== $phone) {
            // Loose match — refuse it; createContact will be called instead.
            return null;
        }

        $id = (int) ($hit['id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    /**
     * Soft-delete-aware upsert of a local ChatwootContact row.
     * Mirrors Controllers/ContactChatwoot.php:findOrCreateLocalChatwootContact.
     *
     * @param array<string, mixed> $cwtData
     * @param string[] $teamIds
     */
    private function upsertLocalChatwootContact(
        int $externalContactId,
        string $accountId,
        string $contactId,
        array $cwtData,
        array $teamIds
    ): Entity {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from('ChatwootContact')
            ->where([
                'chatwootContactId' => $externalContactId,
                'chatwootAccountId' => $accountId,
            ])
            ->withDeleted()
            ->build();

        $existing = $this->entityManager
            ->getRDBRepository('ChatwootContact')
            ->clone($query)
            ->findOne();

        if ($existing) {
            if ($existing->get('deleted')) {
                $existing->set('deleted', false);
            }

            $existing->set('contactId', $contactId);
            $existing->set('name', $cwtData['name'] ?? $existing->get('name'));
            $existing->set('phoneNumber', $cwtData['phone_number'] ?? $existing->get('phoneNumber'));
            $existing->set('email', $cwtData['email'] ?? $existing->get('email'));
            $existing->set('syncStatus', 'synced');
            $existing->set('lastSyncedAt', date('Y-m-d H:i:s'));

            if (!empty($teamIds)) {
                $existing->set('teamsIds', $teamIds);
            }

            $this->entityManager->saveEntity($existing, ['silent' => true]);

            return $existing;
        }

        $data = [
            'chatwootContactId' => $externalContactId,
            'chatwootAccountId' => $accountId,
            'contactId' => $contactId,
            'name' => $cwtData['name'] ?? null,
            'phoneNumber' => $cwtData['phone_number'] ?? null,
            'email' => $cwtData['email'] ?? null,
            'identifier' => $cwtData['identifier'] ?? null,
            'syncStatus' => 'synced',
            'lastSyncedAt' => date('Y-m-d H:i:s'),
        ];

        if (!empty($teamIds)) {
            $data['teamsIds'] = $teamIds;
        }

        return $this->createWithDuplicateHandling('ChatwootContact', $data, [
            'chatwootContactId' => $externalContactId,
            'chatwootAccountId' => $accountId,
        ]);
    }

    /**
     * Idempotent upsert of a local ChatwootContactInbox row.
     * Mirrors Controllers/ContactChatwoot.php:findOrCreateLocalContactInbox.
     *
     * @param string[] $teamIds
     */
    private function upsertLocalChatwootContactInbox(
        Entity $localChatwootContact,
        int $externalInboxId,
        string $inboxEntityId,
        string $accountId,
        Entity $inbox,
        string $contactId,
        ?string $channelType,
        array $teamIds
    ): Entity {
        $existing = $this->entityManager
            ->getRDBRepository('ChatwootContactInbox')
            ->where([
                'chatwootContactId' => $localChatwootContact->getId(),
                'chatwootInboxId' => $externalInboxId,
                'chatwootAccountId' => $accountId,
            ])
            ->findOne();

        if ($existing) {
            $existing->set('contactId', $contactId);
            $existing->set('inboxId', $inboxEntityId);
            $existing->set('lastSyncedAt', date('Y-m-d H:i:s'));

            if (!empty($teamIds)) {
                $existing->set('teamsIds', $teamIds);
            }

            $this->entityManager->saveEntity($existing, ['silent' => true]);

            return $existing;
        }

        $contactName = (string) ($localChatwootContact->get('name') ?? 'Unknown');
        $inboxName = (string) ($inbox->get('name') ?? "Inbox #{$externalInboxId}");

        $displayInboxName = $channelType
            ? "{$inboxName} ({$channelType})"
            : $inboxName;

        $data = [
            'name' => $contactName . ' <> ' . $displayInboxName,
            'chatwootContactId' => $localChatwootContact->getId(),
            'contactId' => $contactId,
            'chatwootAccountId' => $accountId,
            'chatwootInboxId' => $externalInboxId,
            'inboxId' => $inboxEntityId,
            'inboxName' => $inboxName,
            'inboxChannelType' => $channelType,
            'sourceId' => null,
            'lastSyncedAt' => date('Y-m-d H:i:s'),
        ];

        if (!empty($teamIds)) {
            $data['teamsIds'] = $teamIds;
        }

        return $this->createWithDuplicateHandling('ChatwootContactInbox', $data, [
            'chatwootContactId' => $localChatwootContact->getId(),
            'chatwootInboxId' => $externalInboxId,
            'chatwootAccountId' => $accountId,
        ]);
    }

    /**
     * Race-safe createEntity that recovers the existing row if a concurrent
     * insert won the unique-index race. Pattern lifted from
     * Controllers/ContactChatwoot.php:createEntityWithDuplicateHandling.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $uniqueWhere
     */
    private function createWithDuplicateHandling(string $entityType, array $data, array $uniqueWhere): Entity
    {
        try {
            return $this->entityManager->createEntity($entityType, $data, ['silent' => true]);
        } catch (\Espo\Core\Exceptions\Error $e) {
            if (!$this->isDuplicateError($e->getMessage())) {
                throw $e;
            }
        } catch (\PDOException $e) {
            if ($e->getCode() !== '23000' && !$this->isDuplicateError($e->getMessage())) {
                throw $e;
            }
        }

        $existing = $this->entityManager
            ->getRDBRepository($entityType)
            ->where($uniqueWhere)
            ->findOne();

        if (!$existing) {
            throw new \RuntimeException(
                "Duplicate {$entityType} reported but no existing row found for: " . json_encode($uniqueWhere)
            );
        }

        return $existing;
    }

    private function isDuplicateError(string $message): bool
    {
        return stripos($message, 'duplicate') !== false;
    }
}
