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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootAccountUserMembership;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Utils\Language;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Enforces account boundary for membership↔inbox linking.
 *
 * Covers relation operations initiated from the membership side as well.
 */
class ValidateInboxRelation
{
    public static int $order = 9;

    public function __construct(
        private EntityManager $entityManager,
        private Language $language
    ) {}

    /**
     * Validate relation after linking from the membership side.
     *
     * Espo relation lifecycle does not trigger a generic beforeRelate hook,
     * so we enforce by reverting invalid links immediately in afterRelate.
     *
     * @param Entity $entity ChatwootAccountUserMembership entity
     * @param array<string, mixed> $options
     * @param array<string, mixed> $relationParams
     * @throws BadRequest
     */
    public function afterRelate(Entity $entity, array $options, array $relationParams): void
    {
        if (($relationParams['relationName'] ?? null) !== 'chatwootInboxes') {
            return;
        }

        $inboxId = $relationParams['foreignId'] ?? null;

        if (!$inboxId) {
            throw new BadRequest('An inbox ID is required to link account membership.');
        }

        $inbox = $this->entityManager->getEntityById('ChatwootInbox', $inboxId);

        if (!$inbox) {
            throw new BadRequest('Selected inbox was not found.');
        }

        $membershipAccountId = $entity->get('chatwootAccountId');
        $inboxAccountId = $inbox->get('chatwootAccountId');

        if (!$membershipAccountId || !$inboxAccountId) {
            throw new BadRequest('Both Account Membership and Inbox must have a Chat Account before linking.');
        }

        if ($membershipAccountId !== $inboxAccountId) {
            $this->entityManager
                ->getRDBRepository('ChatwootAccountUserMembership')
                ->getRelation($entity, 'chatwootInboxes')
                ->unrelateById($inboxId, ['skipHooks' => true]);

            throw new BadRequest(
                $this->language->translate(
                    'inboxMustBelongToSameAccount',
                    'messages',
                    'ChatwootAccountUserMembership'
                )
            );
        }
    }
}
