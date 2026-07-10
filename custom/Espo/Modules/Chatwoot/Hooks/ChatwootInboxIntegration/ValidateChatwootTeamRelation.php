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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootInboxIntegration;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Utils\Language;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Enforces account boundary for integration↔team linking.
 *
 * Only allows linking ChatwootTeam records that belong to the same
 * ChatwootAccount as the ChatwootInboxIntegration. Teams set here are
 * auto-linked to the inbox at provisioning (granting inbox access to the
 * team's members in Chatwoot), so cross-account links must never exist.
 */
class ValidateChatwootTeamRelation
{
    public static int $order = 9;

    public function __construct(
        private EntityManager $entityManager,
        private Language $language
    ) {}

    /**
     * Validate relation after linking from the integration side.
     *
     * Espo relation lifecycle does not trigger a generic beforeRelate hook,
     * so we enforce by reverting invalid links immediately in afterRelate.
     *
     * @param Entity $entity ChatwootInboxIntegration entity
     * @param array<string, mixed> $options
     * @param array<string, mixed> $relationParams
     * @throws BadRequest
     */
    public function afterRelate(Entity $entity, array $options, array $relationParams): void
    {
        if (($relationParams['relationName'] ?? null) !== 'chatwootTeams') {
            return;
        }

        $teamId = $relationParams['foreignId'] ?? null;

        if (!$teamId) {
            throw new BadRequest('A team ID is required to link a Chatwoot team.');
        }

        $team = $this->entityManager->getEntityById('ChatwootTeam', $teamId);

        if (!$team) {
            throw new BadRequest('Selected Chatwoot team was not found.');
        }

        $integrationAccountId = $entity->get('chatwootAccountId');
        $teamAccountId = $team->get('accountId');

        if (!$integrationAccountId || !$teamAccountId) {
            throw new BadRequest('Both Connection and Team must have a Chat Account before linking.');
        }

        if ($integrationAccountId !== $teamAccountId) {
            $this->entityManager
                ->getRDBRepository('ChatwootInboxIntegration')
                ->getRelation($entity, 'chatwootTeams')
                ->unrelateById($teamId, ['skipHooks' => true]);

            throw new BadRequest(
                $this->language->translate(
                    'chatwootTeamMustBelongToSameAccount',
                    'messages',
                    'ChatwootInboxIntegration'
                )
            );
        }
    }
}
