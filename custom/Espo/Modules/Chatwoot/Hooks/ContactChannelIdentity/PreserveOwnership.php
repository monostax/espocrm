<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Hooks\ContactChannelIdentity;

use Espo\Core\Exceptions\Conflict;
use Espo\ORM\Entity;

/** Corrections are tombstones: imports, ordinary edits and sync cannot undo them. */
class PreserveOwnership
{
    public static int $order = 1;

    public function beforeSave(Entity $entity, array $options): void
    {
        if ($entity->isNew() || $entity->getFetched('ownershipStatus') !== 'rejected') {
            return;
        }
        foreach (['contactId', 'tenantId', 'channelType', 'sourceId', 'ownershipStatus', 'ownershipReason',
            'ownershipReviewedAt', 'ownershipReviewedById', 'ownershipConversationId', 'ownershipEvidenceMessageId'] as $field) {
            if ($entity->isAttributeChanged($field)) {
                throw new Conflict('A rejected identity association cannot be overwritten.');
            }
        }
    }

    public function beforeRemove(Entity $entity, array $options): void
    {
        if ($entity->get('ownershipStatus') === 'rejected') {
            throw new Conflict('A rejected identity association must be retained for outreach suppression.');
        }
    }
}
