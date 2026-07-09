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
 *   0. Prior bridge link (existingContactId), validated against tenant.
 *
 *   1. WhatsApp LID — WhatsApp's durable privacy identifier
 *      ("…@lid", from the Chatwoot contact identifier or a LID-shaped
 *      contact_inboxes[].source_id). Stored on the whatsapp identity's
 *      whatsappLid column, NEVER as its own identity row and NEVER
 *      treated as a phone number.
 *
 *   1b. ContactChannelIdentity by (tenantId, channelType, sourceId)
 *      — derived from `contact_inboxes[].source_id` + inbox channel_type.
 *      This is what makes Instagram / Telegram / Facebook contacts
 *      reconcile correctly when they have no phone and no email.
 *      A WhatsApp number is the canonical phone identity once we've
 *      seen it from any inbox, so this lets us merge a phone-typed
 *      Chatwoot contact with a previously seen WhatsApp identity.
 *
 *   2. Contact.phoneNumber within tenant, normalized via
 *      `PhoneNormalizer::normalize()`. Stops the `+5511…` vs `5511…`
 *      bug where the inbound dedup was using raw strings.
 *
 *   3. Contact.emailAddress within tenant, lowercased.
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
    /**
     * Social channels whose routable sourceId is a numeric page-scoped
     * user id, and whose human handle is a distinct, non-routable value.
     */
    public const HANDLE_CHANNELS = ['instagram', 'facebook', 'twitter'];

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    /**
     * Whether a sourceId is usable to route an outbound message on the
     * channel. For handle-channels (instagram/facebook/twitter) only the
     * numeric page-scoped user id routes — a handle does not. WhatsApp
     * LIDs are also non-routable as source_ids for initiation.
     */
    public static function isRoutableSourceId(string $channelType, string $sourceId): bool
    {
        if ($sourceId === '') {
            return false;
        }

        if (in_array($channelType, self::HANDLE_CHANNELS, true)) {
            return ctype_digit($sourceId);
        }

        if (str_ends_with($sourceId, '@lid')) {
            return false;
        }

        return true;
    }

    /**
     * Canonical handle form: trimmed, @-stripped, lowercased.
     */
    public static function normalizeHandle(?string $handle): ?string
    {
        if ($handle === null) {
            return null;
        }

        $normalized = strtolower(ltrim(trim($handle), '@'));

        return $normalized !== '' ? $normalized : null;
    }

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
     *   existingContactId?: ?string,
     *   contactInboxes?: list<array{source_id?: ?string, inbox?: array{id?: ?int, channel_type?: ?string}}>,
     *   inboxIdMap?: array<int, string>,
     *   socialProfiles?: array<string, ?string>,
     * } $input
     *
     * @return array{
     *   contact: ?Entity,
     *   isNew: bool,
     *   matchedBy: 'existing'|'identity'|'phone'|'email'|'created'|'skipped',
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
        $identifier = $this->str($input['identifier'] ?? null);
        $existingContactId = $this->str($input['existingContactId'] ?? null);
        $contactInboxes = $input['contactInboxes'] ?? [];
        $inboxIdMap = $input['inboxIdMap'] ?? [];

        // Chatwoot's channel webhooks store the human username on the
        // contact as additional_attributes.social_profiles (e.g.
        // Instagram's webhooks_base_service sets social_profiles.instagram
        // = user['username']). Normalized handles keyed by our channel
        // enum; attached to matching candidates below so handle-keyed
        // manual identities merge with scoped-id observations.
        $socialHandles = [];
        foreach (($input['socialProfiles'] ?? []) as $profileKey => $profileValue) {
            $channel = strtolower(trim((string) $profileKey));
            $handle = self::normalizeHandle($this->str($profileValue));
            if ($handle && in_array($channel, self::HANDLE_CHANNELS, true)) {
                $socialHandles[$channel] = $handle;
            }
        }

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
        /** @var array<string, array{channelType:string, sourceId:string, label:?string, chatwootInboxId:?string, whatsappLid?:?string}> $candidateIdentities */
        $candidateIdentities = [];

        // WhatsApp LID (privacy identifier, "…@lid") observed anywhere in
        // the payload. It is a durable identity but NOT a phone number, so
        // it never becomes its own identity row — it rides on the single
        // whatsapp identity (whatsappLid column).
        $observedLid = null;

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
            if (str_ends_with($sourceId, '@lid')) {
                $observedLid ??= $sourceId;
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
                'handle' => $socialHandles[$channel] ?? null,
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

        // WAHA contacts for LID-keyed WhatsApp chats carry the LID as their
        // Chatwoot identifier. Attach it to the (single) whatsapp identity
        // so the same person reconciles to one Contact even while the phone
        // number is unknown — and converges onto the phone-keyed identity
        // once the number resolves.
        if ($identifier && str_ends_with($identifier, '@lid')) {
            $observedLid ??= $identifier;
        }

        if ($observedLid) {
            $attached = false;
            foreach ($candidateIdentities as &$cand) {
                if ($cand['channelType'] === 'whatsapp') {
                    $cand['whatsappLid'] = $observedLid;
                    $attached = true;
                    break;
                }
            }
            unset($cand);

            if (!$attached) {
                // Phone unknown (yet): key the whatsapp identity by the LID.
                // upsertIdentity() rotates sourceId to the E.164 in place as
                // soon as a later sync learns the phone number.
                $candidateIdentities['whatsapp|' . $observedLid] = [
                    'channelType' => 'whatsapp',
                    'sourceId' => $observedLid,
                    'label' => null,
                    'chatwootInboxId' => null,
                    'whatsappLid' => $observedLid,
                ];
            }
        }

        $contact = null;
        $matchedBy = 'created';

        // (0) Highest priority: an existing bridge already resolved this
        // Chatwoot contact to a Contact. The bridge's chatwootContactId
        // is the only key that survives source_id rotation (Chatwoot
        // flips contact_inboxes[].source_id on some channels — e.g. a
        // WhatsApp group JID can become an internal UUID between syncs).
        // Honoring the prior link here makes rotation a no-op instead of
        // spawning a duplicate Contact. We still validate the contact
        // exists and belongs to this tenant before trusting it.
        if ($existingContactId) {
            $found = $this->findContactByIdAndTenant($existingContactId, $tenantId);
            if ($found) {
                $contact = $found;
                $matchedBy = 'existing';
            }
        }

        // (1) Match by WhatsApp LID — the most specific durable identity
        // (survives the phone number being unknown or hidden).
        if (!$contact && $observedLid) {
            $found = $this->findContactByWhatsappLid($tenantId, $observedLid);
            if ($found) {
                $contact = $found;
                $matchedBy = 'identity';
            }
        }

        // (1b) Match by any candidate identity.
        if (!$contact) {
            foreach ($candidateIdentities as $cand) {
                $found = $this->findContactByIdentity($tenantId, $cand['channelType'], $cand['sourceId']);
                if ($found) {
                    $contact = $found;
                    $matchedBy = 'identity';
                    break;
                }
            }
        }

        // (1c) Match by social handle. A manually entered identity is
        // keyed by the handle (the scoped id was unknown at entry time);
        // the first inbound message carries both the scoped id (source_id)
        // and the username (social_profiles). Matching here — and the
        // handle lookup inside upsertIdentity — is what rotates that
        // handle-keyed row into the routable scoped-id row instead of
        // spawning a duplicate Contact.
        if (!$contact) {
            foreach ($candidateIdentities as $cand) {
                if (empty($cand['handle'])) {
                    continue;
                }
                $found = $this->findContactByHandle($tenantId, $cand['channelType'], $cand['handle']);
                if ($found) {
                    $contact = $found;
                    $matchedBy = 'identity';
                    break;
                }
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
                $cand['chatwootInboxId'],
                $cand['whatsappLid'] ?? null,
                $cand['handle'] ?? null
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

        return $this->contactFromIdentity($identity);
    }

    /**
     * Find a Contact via the WhatsApp LID stored on a whatsapp
     * ContactChannelIdentity (whatsappLid column, or a LID-keyed
     * sourceId for identities whose phone never resolved).
     */
    private function findContactByWhatsappLid(string $tenantId, string $lid): ?Entity
    {
        $identity = $this->entityManager
            ->getRDBRepository('ContactChannelIdentity')
            ->where([
                'tenantId' => $tenantId,
                'channelType' => 'whatsapp',
                'OR' => [
                    ['whatsappLid' => $lid],
                    ['sourceId' => $lid],
                ],
            ])
            ->findOne();

        return $this->contactFromIdentity($identity);
    }

    /**
     * Find a Contact via a social identity's handle. Matches both the
     * handle column (scoped-id rows enriched with the username) and
     * handle-keyed sourceIds (manual entry before the scoped id was
     * known).
     */
    private function findContactByHandle(string $tenantId, string $channelType, string $handle): ?Entity
    {
        $identity = $this->entityManager
            ->getRDBRepository('ContactChannelIdentity')
            ->where([
                'tenantId' => $tenantId,
                'channelType' => $channelType,
                'OR' => [
                    ['handle' => $handle],
                    ['sourceId' => $handle],
                ],
            ])
            ->findOne();

        return $this->contactFromIdentity($identity);
    }

    private function contactFromIdentity(?Entity $identity): ?Entity
    {
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
     * Look up a Contact by id, but only if it belongs to the given
     * tenant. Used to honor a pre-existing bridge link without ever
     * trusting a cross-tenant id. Restores soft-deleted records.
     */
    private function findContactByIdAndTenant(string $contactId, string $tenantId): ?Entity
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from('Contact')
            ->where([
                'id' => $contactId,
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

        // A Contact auto-created from a LID-only WhatsApp chat got the raw
        // LID as its name. Replace it as soon as a real name shows up
        // (Chatwoot-side enrichment resolves it shortly after creation).
        $existingFirst = (string) ($contact->get('firstName') ?? '');
        if ($firstName
            && !str_ends_with($firstName, '@lid')
            && str_ends_with($existingFirst, '@lid')
        ) {
            $contact->set('firstName', $firstName);
            $contact->set('lastName', $lastName);
            $changed = true;
        }

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
     *
     * WhatsApp identities carry the LID (privacy identifier) in the
     * whatsappLid column. A row created before the phone number was
     * known is keyed by the LID; once the E.164 arrives we find it via
     * whatsappLid and rotate sourceId in place — one row per WhatsApp
     * identity, always.
     *
     * Social identities (instagram/facebook/twitter) follow the same
     * pattern with the handle: a manually entered row is keyed by the
     * handle; once the first inbound message reveals the numeric
     * page-scoped user id we find the row via the handle and rotate
     * sourceId to the scoped id, keeping the handle in the handle column.
     */
    public function upsertIdentity(
        string $contactId,
        string $tenantId,
        string $channelType,
        string $sourceId,
        ?string $label,
        ?string $chatwootAccountId,
        ?string $chatwootInboxId,
        ?string $whatsappLid = null,
        ?string $handle = null,
    ): Entity {
        $handle = self::normalizeHandle($handle);

        // Look up with-deleted so we can restore instead of duplicating.
        $existing = $this->findIdentityWithDeleted([
            'tenantId' => $tenantId,
            'channelType' => $channelType,
            'sourceId' => $sourceId,
        ]);

        // LID-keyed row from before the phone resolved.
        if (!$existing && $whatsappLid) {
            $existing = $this->findIdentityWithDeleted([
                'tenantId' => $tenantId,
                'channelType' => $channelType,
                'OR' => [
                    ['whatsappLid' => $whatsappLid],
                    ['sourceId' => $whatsappLid],
                ],
            ]);
        }

        // Handle-keyed row from before the scoped id resolved (manual
        // entry / import), or a scoped-id row already enriched with the
        // same username. Mirrors the LID pattern for social channels.
        if (!$existing && $handle && in_array($channelType, self::HANDLE_CHANNELS, true)) {
            $existing = $this->findIdentityWithDeleted([
                'tenantId' => $tenantId,
                'channelType' => $channelType,
                'OR' => [
                    ['handle' => $handle],
                    ['sourceId' => $handle],
                ],
            ]);
        }

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
            // Rotate the sourceId toward the more canonical/routable form:
            // LID-keyed → E.164 once the phone is known, handle-keyed →
            // scoped id once the first inbound message reveals it. Never
            // the other way around.
            if (
                $existing->get('sourceId') !== $sourceId
                && $this->shouldRotateSourceId($channelType, (string) $existing->get('sourceId'), $sourceId)
            ) {
                $existing->set('sourceId', $sourceId);
                $existing->set('name', $channelType . ':' . $sourceId);
                $changed = true;
            }
            if ($whatsappLid && $existing->get('whatsappLid') !== $whatsappLid) {
                $existing->set('whatsappLid', $whatsappLid);
                $changed = true;
            }
            if ($handle && $existing->get('handle') !== $handle) {
                $existing->set('handle', $handle);
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
            'whatsappLid' => $whatsappLid,
            'handle' => $handle,
            'label' => $label,
            'chatwootAccountId' => $chatwootAccountId,
            'chatwootInboxId' => $chatwootInboxId,
        ], ['silent' => true]);
    }

    /**
     * A stored sourceId is only ever replaced by a MORE canonical one:
     *
     * - never by a WhatsApp LID (the E.164 wins);
     * - for handle-channels, only a routable (numeric scoped-id) value
     *   may replace a non-routable (handle-keyed) one — a scoped id is
     *   never downgraded to a handle, and one scoped id never overwrites
     *   another (different pages yield different IGSIDs for the same
     *   person: those are distinct identity rows).
     */
    private function shouldRotateSourceId(string $channelType, string $existingSourceId, string $newSourceId): bool
    {
        if (str_ends_with($newSourceId, '@lid')) {
            return false;
        }

        if (in_array($channelType, self::HANDLE_CHANNELS, true)) {
            return self::isRoutableSourceId($channelType, $newSourceId)
                && !self::isRoutableSourceId($channelType, $existingSourceId);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $where
     */
    private function findIdentityWithDeleted(array $where): ?Entity
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from('ContactChannelIdentity')
            ->where($where)
            ->withDeleted()
            ->build();

        return $this->entityManager
            ->getRDBRepository('ContactChannelIdentity')
            ->clone($query)
            ->findOne();
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
