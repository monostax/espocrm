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

namespace Espo\Modules\FeatureSelfService\Tools\Signup;

use Espo\Entities\User;
use Espo\ORM\EntityManager;

/**
 * One-time tickets that log a user in once, through the normal login
 * request (see TicketLogin). Issued after a verified signup or a social
 * sign-in, so the browser can start a regular CRM session without a password.
 *
 * Only the SHA-256 of a ticket is stored. A ticket works once and for five
 * minutes, for an active regular user.
 */
class LoginTickets
{
    private const ENTITY_TYPE = 'SelfServiceLoginTicket';
    private const TTL_SECONDS = 300;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function issue(string $userId): string
    {
        $ticket = bin2hex(random_bytes(32));

        $this->entityManager->createEntity(self::ENTITY_TYPE, [
            'tokenHash' => hash('sha256', $ticket),
            'userId' => $userId,
            'expiresAt' => gmdate('Y-m-d H:i:s', time() + self::TTL_SECONDS),
        ], ['silent' => true]);

        return $ticket;
    }

    public function redeem(string $ticket): ?User
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $ticket)) {
            return null;
        }

        $tokenHash = hash('sha256', $ticket);
        $now = gmdate('Y-m-d H:i:s');

        // Claim and spend in one statement, so a ticket logs in at most once.
        $statement = $this->entityManager->getQueryExecutor()->execute(
            $this->entityManager
                ->getQueryBuilder()
                ->update()
                ->in(self::ENTITY_TYPE)
                ->set(['usedAt' => $now])
                ->where([
                    'tokenHash' => $tokenHash,
                    'usedAt' => null,
                    'expiresAt>' => $now,
                ])
                ->build()
        );

        if ($statement->rowCount() !== 1) {
            return null;
        }

        $record = $this->entityManager
            ->getRDBRepository(self::ENTITY_TYPE)
            ->where(['tokenHash' => $tokenHash])
            ->findOne();

        $user = $record ? $this->entityManager->getEntityById(User::ENTITY_TYPE, (string) $record->get('userId')) : null;

        if (!$user instanceof User || !$user->isActive() || $user->getType() !== User::TYPE_REGULAR) {
            return null;
        }

        return $user;
    }
}
