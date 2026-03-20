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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootInbox;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Utils\Language;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Enforces account boundary for inbox↔membership linking.
 *
 * Only allows linking ChatwootAccountUserMembership records that belong to
 * the same ChatwootAccount as the ChatwootInbox.
 */
class ValidateAccountUserMembershipRelation
{
    public static int $order = 9;

    public function __construct(
        private EntityManager $entityManager,
        private Language $language
    ) {}

    /**
     * Validate relation after linking from the inbox side.
     *
     * Espo relation lifecycle does not trigger a generic beforeRelate hook,
     * so we enforce by reverting invalid links immediately in afterRelate.
     *
     * @param Entity $entity ChatwootInbox entity
     * @param array<string, mixed> $options
     * @param array<string, mixed> $relationParams
     * @throws BadRequest
     */
    public function afterRelate(Entity $entity, array $options, array $relationParams): void
    {
        if (($relationParams['relationName'] ?? null) !== 'accountUserMemberships') {
            return;
        }

        $membershipId = $relationParams['foreignId'] ?? null;

        if (!$membershipId) {
            throw new BadRequest('A membership ID is required to link inbox membership.');
        }

        $membership = $this->entityManager->getEntityById('ChatwootAccountUserMembership', $membershipId);

        if (!$membership) {
            throw new BadRequest('Selected account membership was not found.');
        }

        $inboxAccountId = $entity->get('chatwootAccountId');
        $membershipAccountId = $membership->get('chatwootAccountId');

        if (!$inboxAccountId || !$membershipAccountId) {
            throw new BadRequest('Both Inbox and Account Membership must have a Chat Account before linking.');
        }

        if ($inboxAccountId !== $membershipAccountId) {
            $this->entityManager
                ->getRDBRepository('ChatwootInbox')
                ->getRelation($entity, 'accountUserMemberships')
                ->unrelateById($membershipId, ['skipHooks' => true]);

            throw new BadRequest(
                $this->language->translate(
                    'accountMembershipMustBelongToSameAccount',
                    'messages',
                    'ChatwootInbox'
                )
            );
        }
    }
}
