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

namespace Espo\Modules\Chatwoot\Hooks\ContactChannelIdentity;

use Espo\Core\Acl;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Tools\ContactFieldSync;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Mirrors every address-bearing identity onto the parent Contact's
 * native multi-value fields:
 *
 *   - whatsapp / sms -> phoneNumber
 *   - email          -> emailAddress
 *
 * Hooking the ContactChannelIdentity entity (rather than the callers)
 * catches ALL creation paths with one implementation:
 *   - Chatwoot sync jobs (ContactReconciler::upsertIdentity),
 *   - manual entry on the Contact form (Hooks\Contact\ChannelIdentities),
 *   - LID -> E.164 sourceId rotation (the phone only becomes known then),
 *   - identity re-assignment to another contact (contactId change),
 *   - direct ContactChannelIdentity record CRUD.
 *
 * Note: reconciler/hook saves use ['silent' => true], which does NOT
 * skip entity hooks (it only suppresses stream/workflow side effects),
 * so this fires for sync-job writes too — intentionally.
 *
 * Additive only: a deleted identity does not remove the value from the
 * contact (phone numbers and emails are user data; identities are
 * observations).
 *
 * Tenant / ACL:
 *   - The write is skipped when identity.tenantId is empty or differs
 *     from contact.tenantId — an identity can never leak contact info
 *     onto a contact of another tenant.
 *   - Non-system actors must have record-level edit access to the
 *     target Contact (Acl::checkEntityEdit); the Contact-form path
 *     trivially satisfies this (the user is saving that contact), the
 *     guard matters for direct identity CRUD. Sync jobs / rebuilds run
 *     as system and are exempt, mirroring Hooks\Contact\ChannelIdentities.
 *   - The contact save itself goes through ContactFieldSync with
 *     ['silent' => true, 'skipChannelIdentitiesSave' => true], so it
 *     cannot echo back into Chatwoot pushes or identity re-diffs.
 */
class SyncFieldsToContact
{
    public static int $order = 10;

    public function __construct(
        private EntityManager $entityManager,
        private ContactFieldSync $contactFieldSync,
        private Acl $acl,
        private User $user,
        private Log $log,
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
    {
        if (!empty($options['skipFieldSyncToContact'])) {
            return;
        }

        // Only when the address-bearing state could have changed.
        if (
            !$entity->isNew() &&
            !$entity->isAttributeChanged('sourceId') &&
            !$entity->isAttributeChanged('contactId')
        ) {
            return;
        }

        $e164 = $this->contactFieldSync->phoneFromIdentity($entity);
        $email = $this->contactFieldSync->emailFromIdentity($entity);

        if (!$e164 && !$email) {
            return;
        }

        $contactId = $entity->get('contactId');

        if (!$contactId) {
            return;
        }

        $contact = $this->entityManager->getEntityById('Contact', $contactId);

        if (!$contact) {
            return;
        }

        $tenantId = $entity->get('tenantId');

        if (!$tenantId || $contact->get('tenantId') !== $tenantId) {
            $this->log->warning(
                "SyncFieldsToContact: identity {$entity->getId()} tenant mismatch "
                . "(identity: " . ($tenantId ?: 'none') . ", contact {$contactId}: "
                . ($contact->get('tenantId') ?: 'none') . "); skipping field sync"
            );

            return;
        }

        if (!$this->user->isSystem() && !$this->acl->checkEntityEdit($contact)) {
            return;
        }

        if ($e164) {
            $this->contactFieldSync->appendPhone($contact, $e164);
        }

        if ($email) {
            $this->contactFieldSync->appendEmail($contact, $email);
        }
    }
}
