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

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaInstagram\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Crypt;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\FeatureMetaInstagram\Services\InstagramGraphApiClient;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Scheduled job: keep Instagram long-lived tokens alive and in sync with Chatwoot.
 *
 * WHY THIS EXISTS
 * ---------------
 * Instagram "long-lived" tokens last ~60 days and are NOT auto-renewed by
 * EspoCRM's generic OAuth flow (Instagram uses a non-standard grant type on
 * graph.instagram.com). Historically the only time a fresh token reached
 * Chatwoot was at inbox provisioning / manual Reconnect. So tokens silently
 * lapsed, Chatwoot's Channel::Instagram flipped to `reauthorization_required`
 * after a single 190, and inbound DMs stopped — exactly the antonio.monostax
 * incident.
 *
 * WHAT IT DOES (per ACTIVE meta-instagram ChatwootInboxIntegration)
 * -----------------------------------------------------------------
 *   1. Read the linked OAuthAccount's long-lived token (decrypted).
 *   2. If the token is within the REFRESH_WINDOW of expiry (and old enough to
 *      be refreshable), call graph.instagram.com/refresh_access_token to mint
 *      a fresh ~60-day token.
 *        - Refresh REQUIRES a still-valid token. A token that already 190'd
 *          (expired / password-changed / revoked) cannot be refreshed — those
 *          are skipped and flagged so a human re-authorizes via Reconnect.
 *   3. Persist the new token + expiresAt back onto the OAuthAccount (encrypted,
 *      mirroring Tools\OAuth\TokenSetter — the ORM does NOT auto-encrypt
 *      password fields).
 *   4. PATCH the Chatwoot inbox's channel (access_token + expires_at) so the
 *      two systems never drift. Chatwoot's Channel::Instagram::EDITABLE_ATTRS
 *      whitelists exactly [instagram_id, access_token, expires_at].
 *   5. Re-subscribe the Meta App to the IG account's webhooks (idempotent) so a
 *      previously-deauthorized account starts delivering again.
 *
 * SAFETY
 * ------
 *   - Idempotent: a token outside the refresh window is left untouched.
 *   - Per-account try/catch: one bad account never blocks the others.
 *   - Never throws out of run(): scheduled jobs must not crash the queue.
 *
 * KNOWN LIMITATION
 * ----------------
 *   Chatwoot stores `authorization_error_count` in Redis and only clears it via
 *   the Rails-side `reauthorized!`. A token PATCH over the API does not reset
 *   that counter. By refreshing PROACTIVELY (before expiry) the counter never
 *   trips, so this is a non-issue in steady state. For an account that already
 *   tripped, a manual Reconnect (or re-auth) is still required.
 */
class RefreshInstagramTokens implements JobDataLess
{
    /**
     * Refresh when the token expires within this many seconds.
     * 14 days gives ~4 refresh attempts before a 60-day token would lapse,
     * comfortably absorbing transient Meta/API outages.
     */
    private const REFRESH_WINDOW_SECONDS = 14 * 24 * 60 * 60;

    /**
     * Meta requires a long-lived token to be at least 24h old before it can be
     * refreshed. Skip very fresh tokens to avoid a guaranteed-failing call.
     */
    private const MIN_TOKEN_AGE_SECONDS = 24 * 60 * 60;

    /** Fallback validity (~60 days) when Meta omits expires_in. */
    private const DEFAULT_TTL_SECONDS = 60 * 24 * 60 * 60;

    public function __construct(
        private EntityManager $entityManager,
        private InstagramGraphApiClient $instagramApiClient,
        private ChatwootApiClient $chatwootApiClient,
        private Crypt $crypt,
        private Log $log,
    ) {}

    public function run(): void
    {
        $this->log->debug('RefreshInstagramTokens: job started');

        $integrations = $this->entityManager
            ->getRDBRepository('ChatwootInboxIntegration')
            ->where([
                'channelType' => 'instagram',
                'status' => 'ACTIVE',
            ])
            ->find();

        $checked = 0;
        $refreshed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($integrations as $integration) {
            $checked++;

            try {
                $result = $this->processIntegration($integration);

                if ($result === 'refreshed') {
                    $refreshed++;
                } else {
                    $skipped++;
                }
            } catch (Throwable $e) {
                $failed++;
                $this->log->error(
                    'RefreshInstagramTokens: failed for integration ' .
                    $integration->getId() . ' (' . $integration->get('instagramUsername') . '): ' .
                    $e->getMessage()
                );
            }
        }

        $this->log->info(
            "RefreshInstagramTokens: done — checked={$checked} refreshed={$refreshed} " .
            "skipped={$skipped} failed={$failed}"
        );
    }

    /**
     * @return string 'refreshed' | 'skipped'
     * @throws Throwable
     */
    private function processIntegration(Entity $integration): string
    {
        $oAuthAccountId = $integration->get('oAuthAccountId');
        $username = $integration->get('instagramUsername') ?: $integration->getId();

        if (!$oAuthAccountId) {
            $this->log->warning("RefreshInstagramTokens: integration {$integration->getId()} has no oAuthAccount; skipping.");
            return 'skipped';
        }

        $oAuthAccount = $this->entityManager->getEntityById('OAuthAccount', $oAuthAccountId);

        if (!$oAuthAccount) {
            $this->log->warning("RefreshInstagramTokens: OAuthAccount {$oAuthAccountId} not found for {$username}; skipping.");
            return 'skipped';
        }

        $currentToken = $this->decryptToken($oAuthAccount->get('accessToken'));

        if ($currentToken === null || $currentToken === '') {
            $this->log->warning("RefreshInstagramTokens: {$username} has no access token; skipping.");
            return 'skipped';
        }

        // Determine expiry. Prefer the OAuthAccount's expiresAt; fall back to the
        // integration's tokenExpiresAt mirror.
        $expiresAtRaw = $oAuthAccount->get('expiresAt') ?: $integration->get('tokenExpiresAt');
        $expiresTs = $expiresAtRaw ? strtotime((string) $expiresAtRaw) : false;

        // Not yet near expiry → nothing to do.
        if ($expiresTs !== false && $expiresTs > time() + self::REFRESH_WINDOW_SECONDS) {
            $this->log->debug(
                "RefreshInstagramTokens: {$username} token healthy (expires " .
                gmdate('c', $expiresTs) . "); skipping."
            );
            return 'skipped';
        }

        // Already expired → cannot be refreshed by Meta; needs human re-auth.
        if ($expiresTs !== false && $expiresTs <= time()) {
            $this->log->warning(
                "RefreshInstagramTokens: {$username} token already EXPIRED (" .
                gmdate('c', $expiresTs) . "). Refresh impossible — flagging for re-authorization."
            );
            $this->flagNeedsReauth($integration, 'Instagram token expired and cannot be auto-refreshed. Re-authorize via Reconnect.');
            return 'skipped';
        }

        // Too young to refresh (Meta requires >= 24h). Try next run.
        $tokenAge = $this->tokenAgeSeconds($oAuthAccount);
        if ($tokenAge !== null && $tokenAge < self::MIN_TOKEN_AGE_SECONDS) {
            $this->log->debug("RefreshInstagramTokens: {$username} token too young to refresh ({$tokenAge}s); skipping.");
            return 'skipped';
        }

        // In the refresh window and refreshable → attempt refresh.
        try {
            $refresh = $this->instagramApiClient->refreshLongLivedToken($currentToken);
        } catch (Throwable $e) {
            // A 190 here means the token died early (password/session change).
            $this->log->warning(
                "RefreshInstagramTokens: refresh rejected for {$username}: {$e->getMessage()}. " .
                "Flagging for re-authorization."
            );
            $this->flagNeedsReauth($integration, 'Instagram token refresh rejected by Meta: ' . $e->getMessage());
            return 'skipped';
        }

        $newToken = (string) $refresh['access_token'];
        $expiresIn = (int) ($refresh['expires_in'] ?? 0);
        $newExpiresTs = $expiresIn > 0 ? time() + $expiresIn : time() + self::DEFAULT_TTL_SECONDS;
        $newExpiresAtDb = gmdate('Y-m-d H:i:s', $newExpiresTs);

        // 1) Persist back onto the OAuthAccount (password field → encrypt manually).
        $oAuthAccount->set('accessToken', $this->crypt->encrypt($newToken));
        $oAuthAccount->set('expiresAt', $newExpiresAtDb);
        $oAuthAccount->set('metaIgLongLivedExchangedAt', gmdate('Y-m-d H:i:s'));
        $this->entityManager->saveEntity($oAuthAccount, ['skipHooks' => true, 'silent' => true]);

        // 2) Push to Chatwoot so the two systems stay in lockstep.
        $this->syncToChatwoot($integration, $newToken, $newExpiresTs, $username);

        // 3) Best-effort: (re)subscribe the IG account to webhooks (idempotent).
        $instagramId = $integration->get('instagramId');
        if ($instagramId) {
            try {
                $this->instagramApiClient->subscribeApp($newToken, (string) $instagramId);
            } catch (Throwable $e) {
                $this->log->warning("RefreshInstagramTokens: subscribeApp failed for {$username}: {$e->getMessage()}");
            }
        }

        // 4) Mirror expiry + clear any prior error state on the integration.
        $integration->set('tokenExpiresAt', $newExpiresAtDb);
        $integration->set('status', 'ACTIVE');
        $integration->set('errorMessage', null);
        $this->entityManager->saveEntity($integration, ['skipHooks' => true, 'silent' => true]);

        $this->log->info(
            "RefreshInstagramTokens: refreshed {$username} — new expiry {$newExpiresAtDb}, synced to Chatwoot."
        );

        return 'refreshed';
    }

    /**
     * PATCH the Chatwoot Instagram inbox's channel with the new token + expiry.
     *
     * @throws Throwable on resolution/HTTP failure (caller logs per-account).
     */
    private function syncToChatwoot(Entity $integration, string $newToken, int $expiresTs, string $username): void
    {
        // The ChatwootInboxIntegration carries the CRM ChatwootInbox via the
        // `chatwootInbox` hasOne link. The NUMERIC Chatwoot inbox id (the one the
        // Chatwoot REST API expects) lives on that linked entity's
        // `chatwootInboxId` (int) — NOT on the integration itself, whose own
        // `chatwootInboxId` attribute is not a backed column and resolves to the
        // link's entity-id string. Casting that to int previously produced a
        // bogus inbox id and the PATCH silently hit the wrong/no inbox.
        $chatwootInbox = $integration->get('chatwootInbox');
        $numericInboxId = $chatwootInbox ? $chatwootInbox->get('chatwootInboxId') : null;

        if (!$numericInboxId) {
            $this->log->warning("RefreshInstagramTokens: {$username} has no resolvable Chatwoot inbox id; token persisted in CRM only.");
            return;
        }

        $chatwootAccount = $integration->get('chatwootAccount')
            ?? ($chatwootInbox ? $chatwootInbox->get('chatwootAccount') : null);

        if (!$chatwootAccount) {
            $this->log->warning("RefreshInstagramTokens: {$username} integration has no ChatwootAccount; skipping Chatwoot sync.");
            return;
        }

        $platform = $chatwootAccount->get('platform');
        $platformUrl = $platform ? $platform->get('backendUrl') : null;
        $apiKey = $chatwootAccount->get('apiKey');
        $chatwootAccountId = $chatwootAccount->get('chatwootAccountId');

        if (!$platformUrl || !$apiKey || !$chatwootAccountId) {
            throw new \RuntimeException('Missing Chatwoot platform URL, API key, or account ID.');
        }

        $this->chatwootApiClient->updateInbox(
            $platformUrl,
            $apiKey,
            (int) $chatwootAccountId,
            (int) $numericInboxId,
            [
                'channel' => [
                    'access_token' => $newToken,
                    'expires_at' => gmdate('c', $expiresTs),
                ],
            ]
        );
    }

    private function flagNeedsReauth(Entity $integration, string $message): void
    {
        // Don't clobber an already-disconnected row repeatedly; only transition
        // from ACTIVE so we preserve the first/most-specific error.
        if ($integration->get('status') === 'DISCONNECTED') {
            return;
        }

        $integration->set('status', 'DISCONNECTED');
        $integration->set('errorMessage', $message);
        $this->entityManager->saveEntity($integration, ['skipHooks' => true, 'silent' => true]);
    }

    private function decryptToken(mixed $stored): ?string
    {
        if (!$stored) {
            return null;
        }

        try {
            return $this->crypt->decrypt((string) $stored);
        } catch (Throwable $e) {
            $this->log->error('RefreshInstagramTokens: failed to decrypt access token: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Age of the current token in seconds, derived from metaIgLongLivedExchangedAt
     * (set whenever we exchange/refresh). Null if unknown.
     */
    private function tokenAgeSeconds(Entity $oAuthAccount): ?int
    {
        $exchangedAt = $oAuthAccount->get('metaIgLongLivedExchangedAt');

        if (!$exchangedAt) {
            return null;
        }

        $ts = strtotime((string) $exchangedAt);

        return $ts !== false ? max(0, time() - $ts) : null;
    }
}
