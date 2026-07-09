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
 * ContactChannelIdentity awareness:
 *   - The push phone falls back to a whatsapp identity's source_id when
 *     Contact.phoneNumber is empty (same as the initiate-conversation
 *     controller), so identity-only contacts still sync.
 *   - Handle-like social identities (instagram/facebook/twitter) are
 *     pushed as `additional_attributes.social_profiles`.
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
        $identities = $this->loadChannelIdentities($contact->getId());

        // Phone resolution: the Contact.phoneNumber field first, then any
        // whatsapp ContactChannelIdentity whose source_id normalizes to a
        // valid E.164 (manual identity entry or webhook-materialized rows).
        // Same fallback as Controllers/ContactChatwoot.php.
        $phone = PhoneNormalizer::normalize($contact->get('phoneNumber'))
            ?: $this->phoneFromIdentities($identities);

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
                $this->pushToAccount($contact, $account, $phone, $identities);
            } catch (\Throwable $e) {
                // Per-account failure must not abort other accounts.
                $this->log->error(
                    "SyncToChatwoot: Contact {$contact->getId()} → " .
                    "account {$account->getId()}: {$e->getMessage()}"
                );
            }
        }
    }

    /**
     * @param Entity[] $identities
     */
    private function pushToAccount(Entity $contact, Entity $account, string $phone, array $identities): void
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

        $payload = $this->buildPayload($contact, $phone, $identities);

        // Path A — existing local link: PATCH only, no inbox required.
        // A contact may map to SEVERAL Chatwoot contacts on one account
        // (one per WhatsApp number — a Chatwoot contact holds a single
        // phone_number). Only a bridge already carrying the push phone,
        // or one with no phone yet (LID-era row awaiting enrichment),
        // may be updated. NEVER rewrite a bridge holding a DIFFERENT
        // number — that would fork its WhatsApp thread. With no
        // compatible bridge we fall through to Path B and create/link
        // a Chatwoot contact keyed by this phone.
        $existingLink = $this->findBridgeForPhone(
            $account->getId(),
            $contact->getId(),
            $phone
        );

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
     * Social handles from ContactChannelIdentity rows (e.g. a manually
     * entered instagram handle) are pushed as
     * `additional_attributes.social_profiles`. Chatwoot's contact update
     * endpoint merges `additional_attributes` with the stored hash, so
     * unrelated attributes survive; the `social_profiles` key itself is
     * replaced, which is why we send every handle we know at once.
     *
     * @param Entity[] $identities
     * @return array<string, mixed>
     */
    private function buildPayload(Entity $contact, string $phone, array $identities): array
    {
        $firstName = (string) $contact->get('firstName');
        $lastName = (string) $contact->get('lastName');
        $name = trim($firstName . ' ' . $lastName);

        if ($name === '') {
            $name = (string) ($contact->get('name') ?: $phone);
        }

        $payload = [
            'name' => $name,
            'phone_number' => $phone,
            'email' => $contact->get('emailAddress') ?: null,
        ];

        $socialProfiles = $this->buildSocialProfiles($identities);

        if ($socialProfiles) {
            $payload['additional_attributes'] = [
                'social_profiles' => $socialProfiles,
            ];
        }

        return $payload;
    }

    /**
     * The contact's ContactChannelIdentity rows, primary rows first.
     *
     * @return Entity[]
     */
    private function loadChannelIdentities(string $contactId): array
    {
        $collection = $this->entityManager
            ->getRDBRepository('ContactChannelIdentity')
            ->where(['contactId' => $contactId])
            ->order('isPrimary', 'DESC')
            ->find();

        return iterator_to_array($collection);
    }

    /**
     * The ChatwootContact bridge row that may safely receive an update
     * for $phone:
     *
     *   1. a bridge whose phoneNumber normalizes to $phone (exact
     *      same identity in Chatwoot), else
     *   2. a bridge with no phone number at all (LID-era contact whose
     *      phone was never enriched — updating it with the phone is the
     *      intended enrichment), else
     *   3. null — every bridge holds a DIFFERENT number. The caller
     *      creates a separate Chatwoot contact for $phone instead of
     *      rewriting one belonging to another number.
     */
    private function findBridgeForPhone(string $accountId, string $contactId, string $phone): ?Entity
    {
        $links = $this->entityManager
            ->getRDBRepository('ChatwootContact')
            ->where([
                'chatwootAccountId' => $accountId,
                'contactId' => $contactId,
                'syncStatus!=' => 'merged',
            ])
            ->limit(0, 20)
            ->find();

        $phoneless = null;
        $mismatched = 0;

        foreach ($links as $link) {
            $linkPhone = PhoneNormalizer::normalize((string) $link->get('phoneNumber'));

            if ($linkPhone === $phone) {
                return $link;
            }

            if (!$linkPhone) {
                $phoneless ??= $link;

                continue;
            }

            $mismatched++;
        }

        if ($phoneless) {
            return $phoneless;
        }

        if ($mismatched > 0) {
            $this->log->info(
                "SyncToChatwoot: Contact {$contactId} has {$mismatched} bridge(s) on "
                . "account {$accountId} holding other phone numbers; "
                . "will create/link a separate Chatwoot contact for {$phone}"
            );
        }

        return null;
    }

    /**
     * Resolve an E.164 phone from the contact's whatsapp identities.
     * LID source_ids are rejected by PhoneNormalizer.
     *
     * @param Entity[] $identities
     */
    private function phoneFromIdentities(array $identities): ?string
    {
        foreach ($identities as $identity) {
            if ($identity->get('channelType') !== 'whatsapp') {
                continue;
            }

            $phone = PhoneNormalizer::normalize((string) $identity->get('sourceId'));

            if ($phone) {
                return $phone;
            }
        }

        return null;
    }

    /**
     * Map handle-like social identities to Chatwoot's
     * `additional_attributes.social_profiles` keys.
     *
     * The dedicated handle column is preferred; legacy rows that carry a
     * handle-like value in sourceId (manual entry before the handle
     * column existed) are honored as a fallback. Numeric page-scoped user
     * IDs are never pushed — they are routing keys, not profile handles.
     * First identity per channel wins (primary rows are ordered first).
     *
     * @param Entity[] $identities
     * @return array<string, string>
     */
    private function buildSocialProfiles(array $identities): array
    {
        $channelToProfileKey = [
            'instagram' => 'instagram',
            'facebook' => 'facebook',
            'twitter' => 'twitter',
        ];

        $profiles = [];

        foreach ($identities as $identity) {
            $channelType = (string) $identity->get('channelType');
            $profileKey = $channelToProfileKey[$channelType] ?? null;

            if (!$profileKey || isset($profiles[$profileKey])) {
                continue;
            }

            $handle = trim((string) $identity->get('handle'));

            if ($handle === '') {
                // Legacy fallback: handle-keyed sourceId.
                $handle = trim((string) $identity->get('sourceId'));
            }

            // Handle-like only: reject numeric scoped IDs and anything
            // that is not a plausible username.
            if (
                $handle === '' ||
                ctype_digit($handle) ||
                !preg_match('/^[A-Za-z0-9._-]+$/', $handle)
            ) {
                continue;
            }

            $profiles[$profileKey] = $handle;
        }

        return $profiles;
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
