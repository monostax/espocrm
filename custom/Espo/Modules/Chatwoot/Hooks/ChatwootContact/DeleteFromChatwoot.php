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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootContact;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Delete the remote Chatwoot contact when a local ChatwootContact row is
 * removed.
 *
 * Triggered via three paths:
 *   - Direct removal of a ChatwootContact (admin action).
 *   - Cascade from Espo Contact removal — Contact.cascadeDelete now lists
 *     `chatwootContacts`, so the global CascadeDelete hook (order=5) calls
 *     removeEntity on each row with ['cascadeParent' => true].
 *   - Cascade from ChatwootAccount removal — ChatwootAccount.cascadeDelete
 *     already lists `contacts`. In that scenario, the entire Chatwoot
 *     account is being torn down, and Chatwoot's own DELETE /accounts/{id}
 *     already cascades contacts server-side, so per-contact delete here
 *     would be wasteful at best and 404-noisy at worst.
 *
 * Convention notes:
 *   - Runs in beforeRemove (matches existing DeleteFromChatwoot hooks for
 *     Team / Inbox / AccountUserMembership). On any non-404 API failure,
 *     re-throw so the local DELETE rolls back — strong consistency.
 *   - Skip on `silent`, `skipChatwootSync`, AND on `cascadeParent` when
 *     the parent is a ChatwootAccount being deleted (matches Team/Inbox
 *     `cascadeParent` skip). For the Contact → ChatwootContact cascade we
 *     deliberately propagate because there is no Espo→Chatwoot cascade for
 *     Contact removal.
 *   - Rows with syncStatus in {'merged','deleted'} are assumed not present
 *     on Chatwoot and the API call is skipped.
 */
class DeleteFromChatwoot
{
    public static int $order = 10;

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function beforeRemove(Entity $entity, array $options): void
    {
        // Loop guards — match the silent / skipChatwootSync conventions.
        if (!empty($options['silent'])) {
            return;
        }

        if (!empty($options['skipChatwootSync'])) {
            return;
        }

        // cascadeParent: only skip when the parent is the ChatwootAccount
        // (Chatwoot will tear down contacts server-side as part of its own
        // account deletion). For Contact-driven cascade, propagate.
        if (!empty($options['cascadeParent']) && $this->isCascadeFromAccount($entity)) {
            return;
        }

        $extContactId = (int) $entity->get('chatwootContactId');
        $espoAccountId = (string) $entity->get('chatwootAccountId');

        if (!$extContactId || !$espoAccountId) {
            // Never linked to a real Chatwoot contact — nothing to delete.
            return;
        }

        $syncStatus = (string) ($entity->get('syncStatus') ?? '');

        if (in_array($syncStatus, ['merged', 'deleted'], true)) {
            // Known not-present on Chatwoot side. Local delete proceeds.
            $this->log->debug(
                "ChatwootContact DeleteFromChatwoot: contact {$extContactId} " .
                "syncStatus={$syncStatus}, skipping remote delete"
            );
            return;
        }

        $account = $this->entityManager->getEntityById('ChatwootAccount', $espoAccountId);

        if (!$account) {
            $this->log->warning(
                "ChatwootContact DeleteFromChatwoot: account {$espoAccountId} " .
                "not found, allowing local delete"
            );
            return;
        }

        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $account->get('platformId'));
        $platformUrl = $platform?->get('backendUrl');
        $apiKey = $account->get('apiKey');
        $extAccountId = (int) $account->get('chatwootAccountId');

        if (!$platformUrl || !$apiKey || !$extAccountId) {
            $this->log->warning(
                "ChatwootContact DeleteFromChatwoot: missing credentials for account " .
                "{$espoAccountId}, allowing local delete"
            );
            return;
        }

        try {
            $this->apiClient->deleteContact(
                $platformUrl,
                $apiKey,
                $extAccountId,
                $extContactId
            );

            $this->log->info(
                "ChatwootContact DeleteFromChatwoot: deleted Chatwoot contact " .
                "{$extContactId} on account {$espoAccountId}"
            );
        } catch (\Throwable $e) {
            $msg = $e->getMessage();

            // Idempotent: 404 / not-found = already gone, success.
            // ChatwootApiClient::deleteContact already swallows 404 itself,
            // so this is belt-and-suspenders for older callers / proxy
            // errors that may surface a 404 in a wrapper exception.
            if (str_contains($msg, '404') || stripos($msg, 'not found') !== false) {
                $this->log->warning(
                    "ChatwootContact DeleteFromChatwoot: Chatwoot contact " .
                    "{$extContactId} not found (already deleted?). Allowing local delete."
                );
                return;
            }

            // Any other failure aborts the local DELETE. Same strong-consistency
            // policy as ChatwootTeam / ChatwootInbox / ChatwootAccountUserMembership.
            $this->log->error(
                "ChatwootContact DeleteFromChatwoot: Chatwoot contact " .
                "{$extContactId} delete failed: {$msg}"
            );

            throw new Error(
                "Failed to delete Chatwoot contact {$extContactId}: {$msg}"
            );
        }
    }

    /**
     * Detect whether this cascade was triggered by a ChatwootAccount removal.
     *
     * In that scenario, Chatwoot's own DELETE /accounts/{id} cascades
     * contacts server-side, so we must NOT issue per-contact DELETE calls
     * (would be wasteful and would 404 once the account is gone).
     *
     * Heuristic: under cascadeParent=true, check whether the parent
     * ChatwootAccount row is currently soft-deleted (the global
     * CascadeDelete hook marks the parent first, then iterates children).
     */
    private function isCascadeFromAccount(Entity $entity): bool
    {
        $accountId = $entity->get('chatwootAccountId');

        if (!$accountId) {
            return false;
        }

        // Include soft-deleted in the lookup — the parent is mid-delete.
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from('ChatwootAccount')
            ->where(['id' => $accountId, 'deleted' => true])
            ->withDeleted()
            ->build();

        $deletedParent = $this->entityManager
            ->getRDBRepository('ChatwootAccount')
            ->clone($query)
            ->findOne();

        return $deletedParent !== null;
    }
}
