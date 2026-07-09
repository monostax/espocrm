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

namespace Espo\Modules\Chatwoot\Hooks\Contact;

use Espo\Core\Acl;
use Espo\Core\Acl\Table as AclTable;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Tools\ContactReconciler;
use Espo\Modules\Chatwoot\Tools\PhoneNormalizer;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Makes the virtual `channelIdentitiesData` field on Contact writable,
 * mirroring how the built-in phone field persists PhoneNumber rows.
 *
 * beforeSave: normalizes submitted items (E.164 for whatsapp/sms,
 * lowercased email, @-stripped instagram handle) and rejects with a 409
 * Conflict when a (tenantId, channelType, sourceId) key already belongs
 * to another contact.
 *
 * afterSave: diffs the submitted items against the existing
 * ContactChannelIdentity rows — creates (via ContactReconciler::upsertIdentity,
 * which restores soft-deleted rows in place), updates, and soft-deletes.
 *
 * Additionally supports the write-only helper fields `whatsappNumber` and
 * `instagramHandle` (used by CSV import and API integrations). These are
 * additive: they upsert a single identity and never delete other rows.
 *
 * Tenant / ACL:
 * - All lookups and uniqueness checks are scoped by the contact's tenantId.
 * - Since writes go through EntityManager (bypassing record-level ACL),
 *   beforeSave enforces ContactChannelIdentity scope ACL for the implied
 *   operations (create/edit/delete). System-user saves are exempt.
 * - The 409 conflict body discloses the owning contact only when the
 *   acting user has read access to it.
 *
 * The read side is handled by
 * Classes\FieldProcessing\Contact\ChannelIdentitiesLoader.
 */
class ChannelIdentities
{
    private const FIELD = 'channelIdentitiesData';

    /** Write-only single-value helper fields (CSV import / API). */
    private const ADDITIVE_FIELD_MAP = [
        'whatsappNumber' => 'whatsapp',
        'instagramHandle' => 'instagram',
    ];

    public const CONFLICT_REASON = 'channelIdentityConflict';

    /**
     * Memoized tenantId -> ChatwootAccount ID (or null when ambiguous).
     *
     * @var array<string, ?string>
     */
    private array $accountIdByTenant = [];

    /**
     * After Global\Hooks\Contact\SyncTenantFromTeam (order 5), which
     * populates tenantId.
     */
    public static int $order = 12;

    public function __construct(
        private EntityManager $entityManager,
        private ContactReconciler $contactReconciler,
        private Metadata $metadata,
        private Acl $acl,
        private User $user,
    ) {}

    /**
     * @param array<string, mixed> $options
     * @throws BadRequest
     * @throws Conflict
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        $toProcessList = $this->toProcess($entity, $options);
        $toProcessAdditive = $this->toProcessAdditive($entity, $options);

        if (!$toProcessList && !$toProcessAdditive) {
            return;
        }

        $tenantId = $entity->get('tenantId');

        if (!$tenantId) {
            // Uniqueness is keyed by tenant; without one we cannot
            // safely write identities.
            return;
        }

        if ($toProcessList) {
            $items = $this->normalizeItems($entity);

            foreach ($items as $item) {
                $this->checkConflict($entity, $tenantId, $item);
            }

            $this->checkListAcl($entity, $items);

            // Store the normalized list back so afterSave persists exactly
            // what was validated.
            $entity->set(self::FIELD, $items);
        }

        if ($toProcessAdditive) {
            foreach ($this->getAdditiveItems($entity) as $field => $item) {
                $this->checkConflict($entity, $tenantId, $item);
                $this->checkAdditiveAcl($entity, $item);

                // Store the normalized value back.
                $entity->set($field, $item->sourceId);
            }
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
    {
        $toProcessList = $this->toProcess($entity, $options);
        $toProcessAdditive = $this->toProcessAdditive($entity, $options);

        if (!$toProcessList && !$toProcessAdditive) {
            return;
        }

        $tenantId = $entity->get('tenantId');

        if (!$tenantId) {
            return;
        }

        if ($toProcessList) {
            $this->syncList($entity, $tenantId);
        }

        if ($toProcessAdditive) {
            foreach ($this->getAdditiveItems($entity) as $item) {
                $this->contactReconciler->upsertIdentity(
                    $entity->getId(),
                    $tenantId,
                    $item->channelType,
                    $item->sourceId,
                    $item->label,
                    $this->resolveAccountIdForTenant($tenantId),
                    null,
                    null,
                    $item->handle ?? null,
                );
            }
        }
    }

    /**
     * Resolve the ChatwootAccount to scope manually-entered identities to.
     *
     * Identities created outside a Chatwoot webhook/sync have no natural
     * account context. When the tenant has exactly one ChatwootAccount the
     * scoping is unambiguous, so we apply it — keeping account-scoped
     * consumers (channel picker, conversation initiation) working without
     * relying on tenant-level fallbacks. With zero or multiple accounts we
     * store null and let the tenant-scoped fallbacks handle resolution.
     */
    private function resolveAccountIdForTenant(string $tenantId): ?string
    {
        if (array_key_exists($tenantId, $this->accountIdByTenant)) {
            return $this->accountIdByTenant[$tenantId];
        }

        $accounts = $this->entityManager
            ->getRDBRepository('ChatwootAccount')
            ->where(['tenantId' => $tenantId])
            ->limit(0, 2)
            ->find();

        $ids = [];

        foreach ($accounts as $account) {
            $ids[] = $account->getId();
        }

        return $this->accountIdByTenant[$tenantId] = count($ids) === 1 ? $ids[0] : null;
    }

    /**
     * Full-list sync of `channelIdentitiesData`: create, update and
     * soft-delete ContactChannelIdentity rows.
     */
    private function syncList(Entity $entity, string $tenantId): void
    {
        /** @var \stdClass[] $items */
        $items = $entity->get(self::FIELD) ?? [];

        $existingList = $this->entityManager
            ->getRDBRepository('ContactChannelIdentity')
            ->where(['contactId' => $entity->getId()])
            ->find();

        $existingMap = [];

        foreach ($existingList as $existing) {
            $existingMap[$existing->getId()] = $existing;
        }

        $keptIdSet = [];

        foreach ($items as $item) {
            $id = $item->id ?? null;

            if ($id && isset($existingMap[$id])) {
                $this->updateIdentity($existingMap[$id], $item);

                $keptIdSet[$id] = true;

                continue;
            }

            // New item. upsertIdentity restores a soft-deleted row with
            // the same key instead of violating the unique index.
            $identity = $this->contactReconciler->upsertIdentity(
                $entity->getId(),
                $tenantId,
                $item->channelType,
                $item->sourceId,
                $item->label ?? null,
                $this->resolveAccountIdForTenant($tenantId),
                null,
                null,
                $item->handle ?? null,
            );

            if ((bool) $identity->get('isPrimary') !== (bool) ($item->isPrimary ?? false)) {
                $identity->set('isPrimary', (bool) ($item->isPrimary ?? false));

                $this->entityManager->saveEntity($identity, ['silent' => true]);
            }

            $keptIdSet[$identity->getId()] = true;
        }

        // Remove rows the user deleted from the field.
        foreach ($existingMap as $id => $existing) {
            if (isset($keptIdSet[$id])) {
                continue;
            }

            $this->entityManager->removeEntity($existing, ['silent' => true]);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function toProcess(Entity $entity, array $options): bool
    {
        if (!empty($options['skipChannelIdentitiesSave'])) {
            return false;
        }

        if (!$entity->has(self::FIELD)) {
            return false;
        }

        return $entity->isAttributeChanged(self::FIELD);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function toProcessAdditive(Entity $entity, array $options): bool
    {
        if (!empty($options['skipChannelIdentitiesSave'])) {
            return false;
        }

        foreach (array_keys(self::ADDITIVE_FIELD_MAP) as $field) {
            if (
                $entity->has($field) &&
                $entity->isAttributeChanged($field) &&
                trim((string) $entity->get($field)) !== ''
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalized items from the write-only helper fields, keyed by
     * field name.
     *
     * @return array<string, \stdClass>
     * @throws BadRequest
     */
    private function getAdditiveItems(Entity $entity): array
    {
        $items = [];

        foreach (self::ADDITIVE_FIELD_MAP as $field => $channelType) {
            if (!$entity->has($field) || !$entity->isAttributeChanged($field)) {
                continue;
            }

            $value = trim((string) $entity->get($field));

            if ($value === '') {
                continue;
            }

            [$sourceId, $label, $handle] = $this->normalizeValue($channelType, $value);

            $items[$field] = (object) [
                'id' => null,
                'channelType' => $channelType,
                'sourceId' => $sourceId,
                'label' => $label,
                'handle' => $handle,
                'isPrimary' => false,
            ];
        }

        return $items;
    }

    /**
     * Normalize a raw source value for a channel type.
     *
     * For handle-channels (instagram/facebook/twitter): a numeric value
     * is a page-scoped user id (routable — stored as-is with no handle);
     * anything else is a username, stored @-stripped/lowercased as BOTH
     * the sourceId (keying the row until the scoped id is observed) and
     * the handle column.
     *
     * @return array{string, ?string, ?string} [sourceId, label, handle]
     * @throws BadRequest
     */
    private function normalizeValue(string $channelType, string $sourceId, ?string $label = null): array
    {
        if (in_array($channelType, ['whatsapp', 'sms'])) {
            $normalized = PhoneNormalizer::normalize($sourceId);

            if (!$normalized) {
                throw new BadRequest(
                    "Invalid phone number '{$sourceId}' for {$channelType} identity."
                );
            }

            return [$normalized, $label, null];
        }

        if ($channelType === 'email') {
            return [strtolower($sourceId), $label, null];
        }

        if (in_array($channelType, ContactReconciler::HANDLE_CHANNELS, true)) {
            $trimmed = trim($sourceId);

            // Numeric input is a page-scoped user id, not a handle.
            if (ctype_digit(ltrim($trimmed, '@'))) {
                return [ltrim($trimmed, '@'), $label, null];
            }

            $handle = ContactReconciler::normalizeHandle($trimmed);

            if (!$handle) {
                throw new BadRequest(
                    "Invalid handle '{$sourceId}' for {$channelType} identity."
                );
            }

            return [$handle, $label ?? $handle, $handle];
        }

        return [$sourceId, $label, null];
    }

    /**
     * Normalize and validate submitted items.
     *
     * @return \stdClass[]
     * @throws BadRequest
     */
    private function normalizeItems(Entity $entity): array
    {
        $rawList = $entity->get(self::FIELD) ?? [];

        if (!is_array($rawList)) {
            throw new BadRequest("channelIdentitiesData must be an array.");
        }

        $allowedTypes = $this->metadata->get([
            'entityDefs', 'ContactChannelIdentity', 'fields', 'channelType', 'options',
        ]) ?? [];

        $items = [];
        $seenKeys = [];
        $primarySeen = [];

        foreach ($rawList as $raw) {
            $raw = (object) $raw;

            $channelType = trim((string) ($raw->channelType ?? ''));
            $sourceId = trim((string) ($raw->sourceId ?? ''));
            $label = isset($raw->label) && trim((string) $raw->label) !== '' ?
                trim((string) $raw->label) : null;

            if ($sourceId === '') {
                continue;
            }

            if (!in_array($channelType, $allowedTypes)) {
                throw new BadRequest("Invalid channel type '{$channelType}'.");
            }

            [$sourceId, $label, $handle] = $this->normalizeValue($channelType, $sourceId, $label);

            $key = $channelType . '|' . $sourceId;

            if (isset($seenKeys[$key])) {
                continue;
            }

            $seenKeys[$key] = true;

            // At most one primary per channel type; first one wins.
            $isPrimary = (bool) ($raw->isPrimary ?? false);

            if ($isPrimary && isset($primarySeen[$channelType])) {
                $isPrimary = false;
            }

            if ($isPrimary) {
                $primarySeen[$channelType] = true;
            }

            $items[] = (object) [
                'id' => isset($raw->id) && is_string($raw->id) ? $raw->id : null,
                'channelType' => $channelType,
                'sourceId' => $sourceId,
                'label' => $label,
                'handle' => $handle,
                'isPrimary' => $isPrimary,
            ];
        }

        return $items;
    }

    /**
     * Reject when an active identity with the same key belongs to
     * another contact.
     *
     * @throws Conflict
     */
    private function checkConflict(Entity $entity, string $tenantId, \stdClass $item): void
    {
        // For handle-channels the same person may already be keyed by the
        // scoped id with the handle stored on the handle column — a manual
        // handle entry must collide with that row too (upsertIdentity will
        // merge into it, so ownership must be validated here first).
        $sourceConditions = [['sourceId' => $item->sourceId]];

        if (!empty($item->handle)) {
            $sourceConditions[] = ['handle' => $item->handle];
        }

        $found = $this->entityManager
            ->getRDBRepository('ContactChannelIdentity')
            ->where([
                'tenantId' => $tenantId,
                'channelType' => $item->channelType,
                'OR' => $sourceConditions,
            ])
            ->findOne();

        if (!$found) {
            return;
        }

        if (!$entity->isNew() && $found->get('contactId') === $entity->getId()) {
            return;
        }

        $body = [
            'channelType' => $item->channelType,
            'sourceId' => $item->sourceId,
        ];

        // Only disclose the owning contact when the acting user is
        // allowed to read it (team/tenant-scoped ACL).
        $otherContact = $found->get('contactId') ?
            $this->entityManager->getEntityById('Contact', $found->get('contactId')) : null;

        if (
            $otherContact &&
            ($this->user->isSystem() || $this->acl->checkEntityRead($otherContact))
        ) {
            $body['contactId'] = $otherContact->getId();
            $body['contactName'] = $otherContact->get('name');
        }

        throw Conflict::createWithBody(
            self::CONFLICT_REASON,
            json_encode($body) ?: ''
        );
    }

    /**
     * Enforce ContactChannelIdentity scope ACL for the operations implied
     * by the submitted full list: create, edit, delete.
     *
     * Writes are performed via EntityManager (bypassing record-level ACL),
     * so the gate lives here. System-user saves (jobs, reconciliation)
     * are exempt.
     *
     * @param \stdClass[] $items
     * @throws Forbidden
     */
    private function checkListAcl(Entity $entity, array $items): void
    {
        if ($this->user->isSystem()) {
            return;
        }

        $existingMap = [];

        if (!$entity->isNew()) {
            $existingList = $this->entityManager
                ->getRDBRepository('ContactChannelIdentity')
                ->where(['contactId' => $entity->getId()])
                ->find();

            foreach ($existingList as $existing) {
                $existingMap[$existing->getId()] = $existing;
            }
        }

        $toCreate = false;
        $toEdit = false;

        $keptIdSet = [];

        foreach ($items as $item) {
            $id = $item->id ?? null;

            if (!$id || !isset($existingMap[$id])) {
                $toCreate = true;

                continue;
            }

            $keptIdSet[$id] = true;

            $existing = $existingMap[$id];

            if (
                $existing->get('channelType') !== $item->channelType ||
                $existing->get('sourceId') !== $item->sourceId ||
                ($item->label !== null && $existing->get('label') !== $item->label) ||
                (bool) $existing->get('isPrimary') !== $item->isPrimary
            ) {
                $toEdit = true;
            }
        }

        $toDelete = count(array_diff_key($existingMap, $keptIdSet)) > 0;

        $this->checkScopeAction($toCreate, AclTable::ACTION_CREATE);
        $this->checkScopeAction($toEdit, AclTable::ACTION_EDIT);
        $this->checkScopeAction($toDelete, AclTable::ACTION_DELETE);
    }

    /**
     * The additive helper fields create (or restore) an identity.
     *
     * @throws Forbidden
     */
    private function checkAdditiveAcl(Entity $entity, \stdClass $item): void
    {
        if ($this->user->isSystem()) {
            return;
        }

        // If the same contact already owns the identity it is a no-op /
        // label refresh; otherwise it is a creation.
        $owned = !$entity->isNew() && $this->entityManager
            ->getRDBRepository('ContactChannelIdentity')
            ->where([
                'contactId' => $entity->getId(),
                'channelType' => $item->channelType,
                'sourceId' => $item->sourceId,
            ])
            ->count() > 0;

        $this->checkScopeAction(!$owned, AclTable::ACTION_CREATE);
    }

    /**
     * @throws Forbidden
     */
    private function checkScopeAction(bool $needed, string $action): void
    {
        if (!$needed) {
            return;
        }

        if ($this->acl->checkScope('ContactChannelIdentity', $action)) {
            return;
        }

        throw new Forbidden(
            "No '{$action}' access to ContactChannelIdentity."
        );
    }

    private function updateIdentity(Entity $identity, \stdClass $item): void
    {
        $changed = false;

        if ($identity->get('channelType') !== $item->channelType) {
            $identity->set('channelType', $item->channelType);
            $changed = true;
        }

        // Never downgrade a routable scoped-id sourceId to a handle: the
        // user editing the handle of an already-resolved identity should
        // update the handle column, not destroy the routing key.
        $newSourceId = $item->sourceId;
        $existingSourceId = (string) $identity->get('sourceId');

        if (
            $existingSourceId !== $newSourceId
            && !empty($item->handle)
            && ContactReconciler::isRoutableSourceId($item->channelType, $existingSourceId)
            && !ContactReconciler::isRoutableSourceId($item->channelType, $newSourceId)
        ) {
            $newSourceId = $existingSourceId;
        }

        if ($identity->get('sourceId') !== $newSourceId) {
            $identity->set('sourceId', $newSourceId);
            $changed = true;
        }

        if ($changed) {
            $identity->set('name', $item->channelType . ':' . $newSourceId);
        }

        if (!empty($item->handle) && $identity->get('handle') !== $item->handle) {
            $identity->set('handle', $item->handle);
            $changed = true;
        }

        if ($item->label !== null && $identity->get('label') !== $item->label) {
            $identity->set('label', $item->label);
            $changed = true;
        }

        if ((bool) $identity->get('isPrimary') !== $item->isPrimary) {
            $identity->set('isPrimary', $item->isPrimary);
            $changed = true;
        }

        if ($changed) {
            $this->entityManager->saveEntity($identity, ['silent' => true]);
        }
    }
}
