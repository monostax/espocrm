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

namespace Espo\Modules\Chatwoot\Services;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Resolves the numeric Chatwoot inbox id (the one Chatwoot's REST API expects)
 * for a ChatwootInboxIntegration.
 *
 * WHY THIS EXISTS
 * ---------------
 * `ChatwootInboxIntegration` declares a `chatwootInbox` hasOne link, and the
 * link wins the `chatwootInboxId` attribute name. The built ORM metadata is:
 *
 *     chatwootInboxId  type=foreign  notStorable=true  relation=chatwootInbox  foreign=id
 *
 * So on an *integration* that attribute is the linked ChatwootInbox's Espo row
 * id (e.g. "6a95c5059bb2b709f") — NOT the numeric Chatwoot id (e.g. 80). There
 * is no `chatwoot_inbox_id` column on the table, so it can never hold one.
 *
 * Casting it with `(int)` yields a small bogus number (every Espo id currently
 * begins with "6", so the cast is a constant 6) which silently targets the
 * wrong inbox in REST calls.
 *
 * The numeric id lives on the ChatwootInbox entity, where `chatwootInboxId` IS
 * a real stored int.
 */
class ChatwootInboxIdResolver
{
    public function __construct(
        private EntityManager $entityManager
    ) {}

    /**
     * @param Entity $channel A ChatwootInboxIntegration.
     * @param bool $includeDeleted Also consider a soft-deleted ChatwootInbox.
     *        Required from remove-time hooks: the generic CascadeDelete hook
     *        (order 5) soft-deletes the linked inbox before entity-specific
     *        cleanup hooks (order 10) run, so a live-only lookup finds nothing
     *        exactly when cleanup needs the id.
     */
    public function resolve(Entity $channel, bool $includeDeleted = false): ?int
    {
        // Preferred: the linked record, which stores the real numeric id.
        $chatwootInbox = $channel->get('chatwootInbox');

        if ($chatwootInbox && $chatwootInbox->get('chatwootInboxId')) {
            return (int) $chatwootInbox->get('chatwootInboxId');
        }

        $reference = $channel->get('chatwootInboxId');

        if (!$reference) {
            return $includeDeleted ? $this->resolveFromDeletedInbox($channel) : null;
        }

        // A numeric value can only come from an in-request set() (legacy code
        // assigns the API's inbox id onto the integration); it never survives a
        // reload because the attribute is not storable.
        if (is_numeric($reference)) {
            return (int) $reference;
        }

        $localInbox = $this->entityManager->getEntityById('ChatwootInbox', (string) $reference);

        if ($localInbox && $localInbox->get('chatwootInboxId')) {
            return (int) $localInbox->get('chatwootInboxId');
        }

        return $includeDeleted ? $this->resolveFromDeletedInbox($channel) : null;
    }

    /**
     * Last resort: find the inbox by back-reference, including soft-deleted
     * rows, so remove-time cleanup still knows which remote inbox to target.
     */
    private function resolveFromDeletedInbox(Entity $channel): ?int
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from('ChatwootInbox')
            ->where(['chatwootInboxIntegrationId' => $channel->getId()])
            ->withDeleted()
            ->build();

        $inbox = $this->entityManager
            ->getRDBRepository('ChatwootInbox')
            ->clone($query)
            ->findOne();

        if ($inbox && $inbox->get('chatwootInboxId')) {
            return (int) $inbox->get('chatwootInboxId');
        }

        return null;
    }
}
