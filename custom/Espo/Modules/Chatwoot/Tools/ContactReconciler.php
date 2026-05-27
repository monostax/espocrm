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

namespace Espo\Modules\Chatwoot\Tools;

use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Shared reconciler for matching/auto-provisioning EspoCRM `Contact`
 * entities from Chatwoot data.
 *
 * Replaces the per-job dedup-by-phone-then-email-then-team-scope logic
 * that was duplicated in `SyncContactsFromChatwoot` and
 * `SyncConversationsFromChatwoot`.
 *
 * Match order (per-tenant, never global):
 *
 *   1. ContactChannelIdentity by (tenantId, channelType, sourceId)
 *      — derived from `contact_inboxes[].source_id` + inbox channel_type.
 *      This is what makes Instagram / Telegram / Facebook contacts
 *      reconcile correctly when they have no phone and no email.
 *
 *   2. ContactChannelIdentity by (tenantId, 'whatsapp', E.164(phone)).
 *      A WhatsApp number is the canonical phone identity once we've
 *      seen it from any inbox, so this lets us merge a phone-typed
 *      Chatwoot contact with a previously seen WhatsApp identity.
 *
 *   3. Contact.phoneNumber within tenant, normalized via
 *      `PhoneNormalizer::normalize()`. Stops the `+5511…` vs `5511…`
 *      bug where the inbound dedup was using raw strings.
 *
 *   4. Contact.emailAddress within tenant, lowercased.
 *
 * If none match, a new Contact is auto-provisioned (even with NULL
 * phone AND NULL email — the previous code skipped this case, which
 * is exactly what left Instagram contacts orphaned).
 *
 * On every match or create, we materialize a `ContactChannelIdentity`
 * row for every (channelType, sourceId) observed in the Chatwoot
 * payload, so subsequent reconciliations hit the fast path (step 1).
 */
class ContactReconciler
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    /**
     * Find or create a Contact for the given Chatwoot identity material.
     *
     * @param array{
     *   tenantId: ?string,
     *   teamsIds: array<string>,
     *   chatwootAccountId: ?string,
     *   name: ?string,
     *   phoneNumber: ?string,
     *   email: ?string,
     *   identifier: ?string,
     *   contactInboxes?: list<array{source_id?: ?string, inbox?: array{id?: ?int, channel_type?: ?string}}>,
     *   inboxIdMap?: array<int, string>,
     * } $input
     *
     * @return array{
     *   contact: ?Entity,
     *   isNew: bool,
     *   matchedBy: 'identity'|'phone'|'email'|'created'|'skipped',
     *   identities: list<Entity>,
     * }
     */
    public function reconcile(array $input): array
    {
        $tenantId = $this->str($input['tenantId'] ?? null);
        $teamsIds = $input['teamsIds'] ?? [];
        $chatwootAccountId = $this->str($input['chatwootAccountId'] ?? null);
        $name = $this->str($input['name'] ?? null);
        $rawPhone = $this->str($input['phoneNumber'] ?? null);
        $rawEmail = $this->str($input['email'] ?? null);
        $contactInboxes = $input['contactInboxes'] ?? [];
        $inboxIdMap = $input['inboxIdMap'] ?? [];

        // Without a tenant we can't safely scope the dedup, so we
        // refuse to act rather than do a global match (the previous
        // bug). Caller should pass tenantId from ChatwootAccount.
        if (!$tenantId) {
            $this->log->warning(
                'ContactReconciler: no tenantId provided; skipping reconciliation '
                . '(name=' . ($name ?? 'null') . ')'
            );
            return [
                'contact' => null,
                'isNew' => false,
                'matchedBy' => 'skipped',
                'identities' => [],
            ];
        }

        $normalizedPhone = PhoneNormalizer::normalize($rawPhone);
        $normalizedEmail = $rawEmail !== null ? strtolower(trim($rawEmail)) : null;

        // Build the set of (channelType, sourceId) pairs to consider.
        // We dedupe via a string key so two inboxes of the same channel
        // with the same source_id collapse to one identity row.
        /** @var array<string, array{channelType:string, sourceId:string, label:?string, chatwootInboxId:?string}> $candidateIdentities */
        $candidateIdentities = [];

        foreach ($contactInboxes as $ci) {
            $rawChannel = $ci['inbox']['channel_type'] ?? null;
            $sourceId = $this->str($ci['source_id'] ?? null);
            if (!$rawChannel || !$sourceId) {
                continue;
            }
            $channel = $this->mapChannelType($rawChannel);
            if (!$channel) {
                continue;
            }
            // Normalize phone-bearing channels so we never store two
            // representations of the same number.
            if ($channel === 'whatsapp' || $channel === 'sms') {
                $norm = PhoneNormalizer::normalize($sourceId);
                if ($norm) {
                    $sourceId = $norm;
                }
            }
            if ($channel === 'email') {
                $sourceId = strtolower(trim($sourceId));
            }
            $key = $channel . '|' . $sourceId;
            if (isset($candidateIdentities[$key])) {
                continue;
            }
            $cwInboxId = $ci['inbox']['id'] ?? null;
            $candidateIdentities[$key] = [
                'channelType' => $channel,
                'sourceId' => $sourceId,
                'label' => $this->str($ci['inbox']['name'] ?? null),
                'chatwootInboxId' => $cwInboxId !== null ? ($inboxIdMap[$cwInboxId] ?? null) : null,
            ];
        }

        // Also synthesize identities from raw phone/email even when no
        // inbox payload is available (e.g. CRM-initiated contact
        // creation). They live on whatsapp/email channel slots so
        // future Chatwoot syncs collide on them.
        if ($normalizedPhone && !isset($candidateIdentities['whatsapp|' . $normalizedPhone])) {
            $candidateIdentities['whatsapp|' . $normalizedPhone] = [
                'channelType' => 'whatsapp',
                'sourceId' => $normalizedPhone,
                'label' => null,
                'chatwootInboxId' => null,
            ];
        }
        if ($normalizedEmail && !isset($candidateIdentities['email|' . $normalizedEmail])) {
            $candidateIdentities['email|' . $normalizedEmail] = [
                'channelType' => 'email',
                'sourceId' => $normalizedEmail,
                'label' => null,
                'chatwootInboxId' => null,
            ];
        }

        $contact = null;
        $matchedBy = 'created';

        // (1) Match by any candidate identity.
        foreach ($candidateIdentities as $cand) {
            $found = $this->findContactByIdentity($tenantId, $cand['channelType'], $cand['sourceId']);
            if ($found) {
                $contact = $found;
                $matchedBy = 'identity';
                break;
            }
        }

        // (2,3) Match by Contact.phoneNumber (E.164-normalized) within tenant.
        if (!$contact && $normalizedPhone) {
            $found = $this->findContactByField($tenantId, 'phoneNumber', $normalizedPhone)
                ?? $this->findContactByField($tenantId, 'phoneNumber', $rawPhone);
            if ($found) {
                $contact = $found;
                $matchedBy = 'phone';
            }
        }

        // (4) Match by Contact.emailAddress within tenant.
        if (!$contact && $normalizedEmail) {
            $found = $this->findContactByField($tenantId, 'emailAddress', $normalizedEmail);
            if ($found) {
                $contact = $found;
                $matchedBy = 'email';
            }
        }

        $isNew = false;
        if (!$contact) {
            // Auto-provision if we have ANY identity material — name
            // alone is enough as long as we have at least one identity
            // to attach (channel source_id, phone, or email). For
            // social-only contacts (Instagram/Telegram), the inbox
            // source_id IS the identity.
            if (!$this->shouldAutoCreate($name, $candidateIdentities, $normalizedPhone, $normalizedEmail)) {
                $this->log->debug(
                    'ContactReconciler: not auto-creating Contact (insufficient identity material)'
                );
                return [
                    'contact' => null,
                    'isNew' => false,
                    'matchedBy' => 'skipped',
                    'identities' => [],
                ];
            }

            $contact = $this->createContact(
                $tenantId,
                $teamsIds,
                $name,
                $normalizedPhone,
                $normalizedEmail
            );
            $isNew = true;
            $matchedBy = 'created';
        } else {
            // Conservative back-fill: only fill empty fields on existing
            // Contacts (matches prior `updateEspoContact` behavior).
            $this->backfillContactFields($contact, $name, $normalizedPhone, $normalizedEmail, $teamsIds);
        }

        // Materialize every observed identity for this contact.
        $identityEntities = [];
        foreach ($candidateIdentities as $cand) {
            $identityEntities[] = $this->upsertIdentity(
                $contact->getId(),
                $tenantId,
                $cand['channelType'],
                $cand['sourceId'],
                $cand['label'],
                $chatwootAccountId,
                $cand['chatwootInboxId']
            );
        }

        return [
            'contact' => $contact,
            'isNew' => $isNew,
            'matchedBy' => $matchedBy,
            'identities' => $identityEntities,
        ];
    }

    /**
     * Find a Contact whose tenant matches and which has the given
     * (channelType, sourceId) registered as a ContactChannelIdentity.
     * Restores soft-deleted contacts.
     */
    private function findContactByIdentity(string $tenantId, string $channelType, string $sourceId): ?Entity
    {
        $identity = $this->entityManager
            ->getRDBRepository('ContactChannelIdentity')
            ->where([
                'tenantId' => $tenantId,
                'channelType' => $channelType,
                'sourceId' => $sourceId,
            ])
            ->findOne();

        if (!$identity) {
            return null;
        }

        $contactId = $identity->get('contactId');
        if (!$contactId) {
            return null;
        }

        // Restore + return.
        $this->entityManager->getRDBRepository('Contact')->restoreDeleted($contactId);
        return $this->entityManager->getEntityById('Contact', $contactId);
    }

    /**
     * Find a Contact by a flat field within a tenant. Includes
     * soft-deleted records and restores them when matched.
     */
    private function findContactByField(string $tenantId, string $field, string $value): ?Entity
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from('Contact')
            ->where([
                $field => $value,
                'tenantId' => $tenantId,
            ])
            ->withDeleted()
            ->build();

        $contact = $this->entityManager
            ->getRDBRepository('Contact')
            ->clone($query)
            ->findOne();

        if (!$contact) {
            return null;
        }

        $this->entityManager->getRDBRepository('Contact')->restoreDeleted($contact->getId());
        return $this->entityManager->getEntityById('Contact', $contact->getId());
    }

    /**
     * Decide whether the available data justifies creating a new
     * Contact. We require at least one durable identifier so the
     * record stays linkable; a bare-name contact would be a dead-end.
     *
     * @param array<string, array{channelType:string, sourceId:string}> $candidateIdentities
     */
    private function shouldAutoCreate(
        ?string $name,
        array $candidateIdentities,
        ?string $normalizedPhone,
        ?string $normalizedEmail,
    ): bool {
        $hasIdentity = $candidateIdentities !== []
            || $normalizedPhone !== null
            || $normalizedEmail !== null;

        // Name is helpful but no longer mandatory: an Instagram contact
        // whose Chatwoot `name` is the same as their `identifier`
        // (e.g. a numeric scoped id) still deserves a Contact row so
        // the AI agent can attach opportunities. We fall back to
        // "Unknown" in createContact() when name is missing.
        return $hasIdentity;
    }

    /**
     * Create a new Contact with explicit tenant + team assignment.
     */
    private function createContact(
        string $tenantId,
        array $teamsIds,
        ?string $name,
        ?string $normalizedPhone,
        ?string $normalizedEmail,
    ): Entity {
        [$firstName, $lastName] = $this->splitName($name);

        $data = [
            'firstName' => $firstName,
            'lastName' => $lastName,
            'phoneNumber' => $normalizedPhone,
            'emailAddress' => $normalizedEmail,
            'tenantId' => $tenantId,
            'description' => 'Imported from Chatwoot',
        ];

        if (!empty($teamsIds)) {
            $data['teamsIds'] = $teamsIds;
        }

        $contact = $this->entityManager->createEntity('Contact', $data, ['silent' => true]);

        // linkMultiple sometimes requires an explicit save (createEntity
        // does not always persist teams in `silent` mode).
        if (!empty($teamsIds)) {
            $contact->set('teamsIds', $teamsIds);
            $this->entityManager->saveEntity($contact, ['silent' => true]);
        }

        return $contact;
    }

    /**
     * Conservatively fill empty Contact fields from a freshly observed
     * Chatwoot payload. Never overwrites human-edited data.
     */
    private function backfillContactFields(
        Entity $contact,
        ?string $name,
        ?string $normalizedPhone,
        ?string $normalizedEmail,
        array $teamsIds,
    ): void {
        $changed = false;
        [$firstName, $lastName] = $this->splitName($name);

        if (!$contact->get('firstName') && $firstName) {
            $contact->set('firstName', $firstName);
            $changed = true;
        }
        if (!$contact->get('lastName') && $lastName) {
            $contact->set('lastName', $lastName);
            $changed = true;
        }
        if (!$contact->get('phoneNumber') && $normalizedPhone) {
            $contact->set('phoneNumber', $normalizedPhone);
            $changed = true;
        }
        if (!$contact->get('emailAddress') && $normalizedEmail) {
            $contact->set('emailAddress', $normalizedEmail);
            $changed = true;
        }

        if (!empty($teamsIds)) {
            $existingTeams = method_exists($contact, 'getLinkMultipleIdList')
                ? $contact->getLinkMultipleIdList('teams')
                : [];
            $missing = array_values(array_diff($teamsIds, $existingTeams));
            if ($missing !== []) {
                $contact->set('teamsIds', array_values(array_unique(array_merge($existingTeams, $teamsIds))));
                $changed = true;
            }
        }

        if ($changed) {
            $this->entityManager->saveEntity($contact, ['silent' => true]);
        }
    }

    /**
     * Upsert a ContactChannelIdentity row keyed by
     * (tenantId, channelType, sourceId).
     *
     * Restores soft-deleted rows in-place so the unique index never
     * blocks a re-add.
     */
    public function upsertIdentity(
        string $contactId,
        string $tenantId,
        string $channelType,
        string $sourceId,
        ?string $label,
        ?string $chatwootAccountId,
        ?string $chatwootInboxId,
    ): Entity {
        // Look up with-deleted so we can restore instead of duplicating.
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from('ContactChannelIdentity')
            ->where([
                'tenantId' => $tenantId,
                'channelType' => $channelType,
                'sourceId' => $sourceId,
            ])
            ->withDeleted()
            ->build();

        $existing = $this->entityManager
            ->getRDBRepository('ContactChannelIdentity')
            ->clone($query)
            ->findOne();

        if ($existing) {
            $this->entityManager
                ->getRDBRepository('ContactChannelIdentity')
                ->restoreDeleted($existing->getId());

            $existing = $this->entityManager->getEntityById('ContactChannelIdentity', $existing->getId());

            $changed = false;
            if ($existing->get('contactId') !== $contactId) {
                $existing->set('contactId', $contactId);
                $changed = true;
            }
            if ($label && $existing->get('label') !== $label) {
                $existing->set('label', $label);
                $changed = true;
            }
            if ($chatwootAccountId && !$existing->get('chatwootAccountId')) {
                $existing->set('chatwootAccountId', $chatwootAccountId);
                $changed = true;
            }
            if ($chatwootInboxId && !$existing->get('chatwootInboxId')) {
                $existing->set('chatwootInboxId', $chatwootInboxId);
                $changed = true;
            }
            if ($changed) {
                $this->entityManager->saveEntity($existing, ['silent' => true]);
            }
            return $existing;
        }

        return $this->entityManager->createEntity('ContactChannelIdentity', [
            'name' => $channelType . ':' . $sourceId,
            'contactId' => $contactId,
            'tenantId' => $tenantId,
            'channelType' => $channelType,
            'sourceId' => $sourceId,
            'label' => $label,
            'chatwootAccountId' => $chatwootAccountId,
            'chatwootInboxId' => $chatwootInboxId,
        ], ['silent' => true]);
    }

    /**
     * Look up the tenantId for a ChatwootAccount. Returns null when
     * the account has not yet been backfilled (in which case the
     * caller should fall back to the legacy team-scoped path).
     */
    public function resolveTenantIdForAccount(string $chatwootAccountId): ?string
    {
        $account = $this->entityManager->getEntityById('ChatwootAccount', $chatwootAccountId);
        if (!$account) {
            return null;
        }
        return $this->str($account->get('tenantId'));
    }

    /**
     * Normalize a raw Chatwoot channel type string to our enum. Returns
     * null for unrecognized values (which we then skip rather than
     * persisting garbage data).
     */
    public function mapChannelType(?string $channelType): ?string
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
            'Channel::Line' => 'line',
            'Channel::Viber' => 'viber',
        ];

        if (isset($map[$channelType])) {
            return $map[$channelType];
        }

        // Accept already-mapped values too (so callers can pass either
        // the raw 'Channel::Instagram' or the mapped 'instagram').
        $known = ['whatsapp', 'email', 'web_widget', 'api', 'telegram', 'sms', 'facebook', 'instagram', 'line', 'viber', 'twitter', 'other'];
        if (in_array($channelType, $known, true)) {
            return $channelType;
        }

        $fallback = strtolower(str_replace('Channel::', '', $channelType));
        return in_array($fallback, $known, true) ? $fallback : null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(?string $name): array
    {
        $name = trim($name ?? '');
        if ($name === '') {
            return ['Unknown', ''];
        }
        $parts = explode(' ', $name, 2);
        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    private function str(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $trimmed = trim($v);
        return $trimmed !== '' ? $trimmed : null;
    }
}
