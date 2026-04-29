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

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Keeps `accountToken` inside every WAHA Chatwoot app aligned with the
 * current `ChatwootAccount.apiKey`.
 *
 * ### Why this exists
 *
 * When `ChatwootInboxIntegration::activateWhatsappQrcode` provisions a
 * WAHA app it embeds a snapshot of the account's `apiKey` (User Access
 * Token) into the app's `config.accountToken`. WAHA then uses that token
 * to POST bot replies, status commands and markAsRead requests against
 * the Chatwoot API for the life of the session.
 *
 * When the token rotates — which happens every time:
 *
 *   - `RotateAutomationToConcierge` migrates an account from the legacy
 *     automation user to the new concierge user (and deletes the old
 *     user on Chatwoot, instantly invalidating its token);
 *   - `SeedChatwootAccount` or `Hooks\ChatwootAccount\SyncWithChatwoot`
 *     rebootstraps the concierge user on an account whose `apiKey`
 *     linkage is missing/broken;
 *   - an operator manually regenerates the User Access Token in Chatwoot
 *     and edits the `ChatwootAccount.apiKey` field in CRM.
 *
 * …every WAHA app that was provisioned BEFORE the rotation is left
 * holding a stale token, which produces:
 *
 *     ChatWoot API Error: Unauthorized
 *     Status: 401
 *     Body: {"error":"Invalid Access Token"}
 *
 * on every `ChatWootInboxCommandsConsumer` run. Webhook delivery from
 * Chatwoot → WAHA keeps working (it uses the inbox identifier), which
 * makes the breakage easy to miss: conversations look alive but no
 * command responses, read-receipts or markAsRead ever land.
 *
 * ### What it does
 *
 * For a given `ChatwootAccount`, iterates every `ChatwootInboxIntegration`
 * under it that has both `wahaAppId` and `wahaPlatform` set, fetches the
 * current app config from WAHA, overwrites `config.accountToken` with
 * the account's current `apiKey`, and PUTs it back. Everything else in
 * the config is preserved verbatim so we don't stomp on locale,
 * templates, command flags, etc.
 *
 * All failures are logged and non-fatal — one broken integration must
 * not prevent the others from being refreshed, and the caller (hook or
 * migration) should never be aborted by a transient WAHA hiccup.
 */
class ChatwootWahaAppTokenSync
{
    public function __construct(
        private EntityManager $entityManager,
        private WahaApiClient $wahaApiClient,
        private Log $log,
    ) {}

    /**
     * Resync every WAHA Chatwoot app bound to this ChatwootAccount with
     * the account's current apiKey.
     *
     * @return array{refreshed:int, skipped:int, failed:int}
     */
    public function syncForAccount(Entity $chatwootAccount): array
    {
        $stats = ['refreshed' => 0, 'skipped' => 0, 'failed' => 0];

        $accountId = $chatwootAccount->getId();
        $apiKey = $chatwootAccount->get('apiKey');

        if (!$apiKey) {
            $this->log->info(
                "ChatwootWahaAppTokenSync: Account $accountId has no apiKey — nothing to propagate"
            );
            return $stats;
        }

        $integrations = $this->entityManager
            ->getRDBRepository('ChatwootInboxIntegration')
            ->where([
                'chatwootAccountId' => $accountId,
                'deleted' => false,
            ])
            ->find();

        foreach ($integrations as $integration) {
            $result = $this->syncIntegration($integration, (string) $apiKey);
            $stats[$result]++;
        }

        $this->log->info(
            "ChatwootWahaAppTokenSync: Account $accountId — refreshed: {$stats['refreshed']}, " .
            "skipped: {$stats['skipped']}, failed: {$stats['failed']}"
        );

        return $stats;
    }

    /**
     * @return 'refreshed'|'skipped'|'failed'
     */
    private function syncIntegration(Entity $integration, string $apiKey): string
    {
        $integrationId = $integration->getId();
        $wahaAppId = $integration->get('wahaAppId');

        if (!$wahaAppId) {
            // Inbox integrations that never went through QR activation (e.g.
            // whatsappCloudApi channels) don't have a WAHA app — skip quietly.
            return 'skipped';
        }

        $wahaPlatformId = $integration->get('wahaPlatformId');

        if (!$wahaPlatformId) {
            $this->log->warning(
                "ChatwootWahaAppTokenSync: Integration $integrationId has wahaAppId but " .
                "no wahaPlatformId — cannot resolve WAHA credentials"
            );
            return 'skipped';
        }

        $wahaPlatform = $this->entityManager->getEntityById('WahaPlatform', $wahaPlatformId);

        if (!$wahaPlatform) {
            $this->log->warning(
                "ChatwootWahaAppTokenSync: WahaPlatform $wahaPlatformId not found for " .
                "integration $integrationId"
            );
            return 'skipped';
        }

        $platformUrl = $wahaPlatform->get('backendUrl') ?: $wahaPlatform->get('url');
        $wahaApiKey = $wahaPlatform->get('apiKey');

        if (!$platformUrl || !$wahaApiKey) {
            $this->log->warning(
                "ChatwootWahaAppTokenSync: WahaPlatform $wahaPlatformId missing URL or apiKey"
            );
            return 'skipped';
        }

        try {
            $existing = $this->wahaApiClient->getApp($platformUrl, $wahaApiKey, $wahaAppId);
        } catch (\Throwable $e) {
            // A 404 here usually means the app was already wiped from WAHA
            // (e.g. session was recreated or manually purged). Nothing to fix.
            $this->log->warning(
                "ChatwootWahaAppTokenSync: Could not fetch WAHA app $wahaAppId for " .
                "integration $integrationId — " . $e->getMessage()
            );
            return 'skipped';
        }

        if (($existing['app'] ?? null) !== 'chatwoot') {
            // Defensive: if the app on the other end isn't actually a Chatwoot
            // app, don't rewrite it. Should never happen in practice because
            // ChatwootInboxIntegration only ever creates chatwoot-type apps.
            return 'skipped';
        }

        $config = $existing['config'] ?? [];
        if (!is_array($config)) {
            $config = (array) $config;
        }

        $currentToken = $config['accountToken'] ?? null;

        if ($currentToken === $apiKey) {
            // Already in sync — no PUT needed.
            return 'skipped';
        }

        $config['accountToken'] = $apiKey;

        // WAHA's PUT /api/apps/:id validator requires the full descriptor
        // (id, session, app) alongside the mutable fields. Missing any of
        // those yields a 400 "Bad Request" before the config is even parsed.
        $payload = [
            'id' => $existing['id'] ?? $wahaAppId,
            'session' => $existing['session'] ?? $integration->get('wahaSessionName'),
            'app' => 'chatwoot',
            'enabled' => (bool) ($existing['enabled'] ?? true),
            'config' => $config,
        ];

        if (!$payload['session']) {
            $this->log->warning(
                "ChatwootWahaAppTokenSync: Integration $integrationId has no session name — " .
                "skipping WAHA app update"
            );
            return 'skipped';
        }

        try {
            $this->wahaApiClient->updateApp($platformUrl, $wahaApiKey, $wahaAppId, $payload);
            $this->log->info(
                "ChatwootWahaAppTokenSync: Refreshed accountToken on WAHA app $wahaAppId " .
                "(integration $integrationId, session {$payload['session']})"
            );
            return 'refreshed';
        } catch (\Throwable $e) {
            $this->log->error(
                "ChatwootWahaAppTokenSync: Failed to PUT WAHA app $wahaAppId for " .
                "integration $integrationId — " . $e->getMessage()
            );
            return 'failed';
        }
    }
}
