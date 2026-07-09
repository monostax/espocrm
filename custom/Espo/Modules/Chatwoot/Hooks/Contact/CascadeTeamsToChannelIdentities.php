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

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Propagates Contact team changes to its ContactChannelIdentity rows.
 *
 * Identity rows mirror the contact's teams for team-scoped ACL (see
 * Hooks\ContactChannelIdentity\CascadeTeamsFromContact, which handles
 * the create-time cascade). When a contact is moved between teams the
 * identity rows must follow, or they become invisible (or wrongly
 * visible) to team-scoped users.
 *
 * Identity saves here are ['silent' => true]; the identity-side hooks
 * are unaffected (SyncFieldsToContact ignores saves that change
 * neither sourceId nor contactId, CascadeTeamsFromContact ignores
 * non-new rows with an unchanged contactId).
 */
class CascadeTeamsToChannelIdentities
{
    /**
     * After SyncTenantFromTeam (5) and ChannelIdentities (12) so the
     * row set is final for this save.
     */
    public static int $order = 15;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
    {
        if (!$entity->has('teamsIds') || !$entity->isAttributeChanged('teamsIds')) {
            return;
        }

        $teamsIds = $entity->get('teamsIds') ?? [];

        if (!is_array($teamsIds)) {
            return;
        }

        $identities = $this->entityManager
            ->getRDBRepository('ContactChannelIdentity')
            ->where(['contactId' => $entity->getId()])
            ->find();

        foreach ($identities as $identity) {
            $existing = method_exists($identity, 'getLinkMultipleIdList')
                ? $identity->getLinkMultipleIdList('teams')
                : [];

            sort($existing);
            $target = array_values(array_unique($teamsIds));
            sort($target);

            if ($existing === $target) {
                continue;
            }

            $identity->set('teamsIds', $target);

            $this->entityManager->saveEntity($identity, ['silent' => true]);
        }
    }
}
