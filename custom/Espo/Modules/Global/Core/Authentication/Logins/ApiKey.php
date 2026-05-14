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

namespace Espo\Modules\Global\Core\Authentication\Logins;

use Espo\Core\Api\Request;
use Espo\Core\Authentication\Helper\UserFinder;
use Espo\Core\Authentication\Login;
use Espo\Core\Authentication\Login\Data;
use Espo\Core\Authentication\Result;
use Espo\Core\Authentication\Result\FailReason;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\ORM\EntityManager;

/**
 * Custom `ApiKey` login method that ALSO accepts tokens persisted in
 * `UserApiKey` records (any user type — admin, regular, portal, api).
 *
 * Stock EspoCRM only authenticates `X-Api-Key` headers when the value
 * matches `user.apiKey` AND the user is `type=api` AND the user's
 * `authMethod` is `ApiKey`. That forces operators to maintain a separate
 * "API user" per integration (e.g. one per Chatwoot AI agent), which
 * causes user-limit pressure and breaks the membership model used by
 * automations that want to act AS a real user.
 *
 * This override extends the lookup so that:
 *
 *   1. The header value is first looked up in `UserApiKey` (active,
 *      non-expired record → the linked user). On hit, we touch
 *      `lastUsedAt` and return a Success.
 *   2. If no UserApiKey row matches, the call falls back to the stock
 *      `User.apiKey` path so existing integrations keep working without
 *      migration.
 *
 * Wiring: registered as the `ApiKey` implementation via
 * `Resources/metadata/app/authenticationMethods.json` in this module.
 */
class ApiKey implements Login
{
    public const NAME = 'ApiKey';

    public function __construct(
        private UserFinder $userFinder,
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function login(Data $data, Request $request): Result
    {
        $apiKey = $request->getHeader('X-Api-Key');

        if (!$apiKey) {
            return Result::fail(FailReason::WRONG_CREDENTIALS);
        }

        $user = $this->findByUserApiKey($apiKey);

        if ($user) {
            return Result::success($user);
        }

        // Fallback: stock single-key-per-user path. Preserves backwards
        // compatibility with integrations that still authenticate via
        // legacy `user.apiKey` (type=api users).
        $user = $this->userFinder->findApiApiKey($apiKey);

        if (!$user) {
            return Result::fail(FailReason::WRONG_CREDENTIALS);
        }

        return Result::success($user);
    }

    /**
     * Resolve a presented API key against the `UserApiKey` table.
     *
     * Constraints:
     *   • record is not deleted
     *   • `isActive = true`
     *   • `expiresAt` is null OR in the future
     *   • linked `user.isActive = true` and not deleted
     *
     * On hit, bumps `lastUsedAt` to "now" (best-effort; failure to
     * persist the bump does NOT fail the login — telemetry should not
     * make auth less reliable).
     */
    private function findByUserApiKey(string $apiKey): ?User
    {
        $record = $this->entityManager
            ->getRDBRepository('UserApiKey')
            ->where(['apiKey' => $apiKey, 'isActive' => true])
            ->findOne();

        if (!$record) {
            return null;
        }

        $expiresAt = $record->get('expiresAt');

        if ($expiresAt) {
            // Espo stores datetimes as "Y-m-d H:i:s" in UTC. strtotime
            // returns a Unix timestamp; comparing against time() is
            // sufficient. We expressly do not throw on parse failure —
            // a malformed `expiresAt` shouldn't grant access.
            $expiresAtTs = strtotime((string) $expiresAt);

            if ($expiresAtTs === false || $expiresAtTs < time()) {
                return null;
            }
        }

        $userId = $record->get('userId');

        if (!$userId) {
            return null;
        }

        /** @var ?User $user */
        $user = $this->entityManager
            ->getRDBRepositoryByClass(User::class)
            ->where(['id' => $userId, 'isActive' => true])
            ->findOne();

        if (!$user) {
            return null;
        }

        // Best-effort lastUsedAt bump. Wrapped in try/catch so a DB hiccup
        // never blocks an otherwise valid login.
        try {
            $record->set('lastUsedAt', date('Y-m-d H:i:s'));
            $this->entityManager->saveEntity($record, ['skipHooks' => true]);
        } catch (\Throwable $e) {
            $this->log->warning('UserApiKey: failed to update lastUsedAt: ' . $e->getMessage());
        }

        return $user;
    }
}
