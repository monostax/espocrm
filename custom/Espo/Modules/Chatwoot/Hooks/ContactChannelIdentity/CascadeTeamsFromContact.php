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

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Mirrors the parent Contact's teams onto the identity row so
 * team-scoped ACL works: tenant roles grant ContactChannelIdentity
 * `read: team`, and a row without entity_team links is invisible to
 * every non-admin user — which broke the channel-picker's identity
 * fetch (whatsapp/instagram rows disabled despite existing identities).
 *
 * Unlike the CascadeTeamsFromAccount hooks, this does NOT skip
 * ['silent' => true] saves: identities are created almost exclusively
 * by silent writes (ContactReconciler::upsertIdentity from sync jobs
 * and the ChannelIdentities contact hook), so a silent-guard here
 * would make the cascade dead code.
 *
 * Runs on create and on contact re-assignment. Later team changes on
 * the Contact are propagated by
 * Hooks\Contact\CascadeTeamsToChannelIdentities.
 */
class CascadeTeamsFromContact
{
    /** Before validation/other hooks, mirroring CascadeTeamsFromAccount. */
    public static int $order = 1;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        if (!$entity->isNew() && !$entity->isAttributeChanged('contactId')) {
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

        $teamsIds = method_exists($contact, 'getLinkMultipleIdList')
            ? $contact->getLinkMultipleIdList('teams')
            : [];

        if (!empty($teamsIds)) {
            $entity->set('teamsIds', $teamsIds);
        }
    }
}
