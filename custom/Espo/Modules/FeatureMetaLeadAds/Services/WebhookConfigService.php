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

namespace Espo\Modules\FeatureMetaLeadAds\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Crypt;
use Espo\Core\Utils\Log;
use Espo\Entities\OAuthProvider;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * End-to-end orchestrator for configuring the Meta App Lead Ads webhook.
 *
 * Unlike the Instagram flow (which is Chatwoot-only because the Instagram
 * product uses an Instagram-scoped App ID that graph.facebook.com cannot
 * resolve), Lead Ads uses the standard Meta App ID — so we CAN register
 * the App-level webhook subscription programmatically.
 *
 * BYOA design:
 *   The callback URL is per-OAuthProvider:
 *     {siteUrl}/api/v1/MetaLeadAds/webhook/{oAuthProviderId}
 *   Each tenant brings their own Meta App as a distinct OAuthProvider row
 *   (provider='meta-leadads'), with its own clientId/clientSecret/
 *   webhookVerifyToken. The path-scoped providerId lets the webhook
 *   handler look up the exact row to validate X-Hub-Signature-256 against,
 *   eliminating any ambiguity when multiple Meta Apps coexist.
 *
 * Steps:
 *   1. Ensure a verify_token exists on the OAuthProvider (auto-generate if blank).
 *   2. Build the per-provider callback URL from siteUrl + oAuthProviderId.
 *   3. Call POST graph.facebook.com/{appId}/subscriptions with:
 *        callback_url, verify_token, object=page, fields=leadgen
 *      using an App Access Token ({appId}|{appSecret}).
 *   4. Return verify_token + callback_url + current subscriptions so the
 *      admin can visually confirm the registration in the result dialog.
 *
 * Page-level subscription (POST /{pageId}/subscribed_apps?subscribed_fields=leadgen)
 * remains the responsibility of PageSyncService — it runs per-page after
 * pages are imported and the user has reauthorized with `pages_manage_ads`.
 */
class WebhookConfigService
{
    private const PROVIDER_DISCRIMINATOR = 'meta-leadads';
    private const WEBHOOK_PATH = '/api/v1/MetaLeadAds/webhook';

    public function __construct(
        private EntityManager $entityManager,
        private Crypt $crypt,
        private Config $config,
        private Log $log,
        private MetaGraphApiClient $graphApi,
    ) {}

    /**
     * @param string $oAuthProviderId The meta-leadads OAuthProvider row id.
     * @return array{
     *     verifyTokenGenerated: bool,
     *     verifyToken: string,
     *     callbackUrl: string,
     *     appSubscriptionRegistered: bool,
     *     subscriptions: array<int, array<string, mixed>>,
     *     warnings: string[]
     * }
     * @throws Error
     */
    public function configure(string $oAuthProviderId): array
    {
        $provider = $this->entityManager->getEntityById(OAuthProvider::ENTITY_TYPE, $oAuthProviderId);

        if (!$provider) {
            throw new Error("OAuthProvider not found: {$oAuthProviderId}");
        }

        if ($provider->get('provider') !== self::PROVIDER_DISCRIMINATOR) {
            throw new Error(
                "OAuthProvider {$oAuthProviderId} is not a meta-leadads provider (got: " .
                (string) $provider->get('provider') . ')'
            );
        }

        $appId = (string) ($provider->get('clientId') ?? '');
        $encryptedClientSecret = (string) ($provider->get('clientSecret') ?? '');

        if ($appId === '') {
            throw new Error('OAuthProvider is missing clientId (Meta App ID).');
        }

        if ($encryptedClientSecret === '') {
            throw new Error('OAuthProvider is missing clientSecret (Meta App Secret).');
        }

        try {
            $appSecret = $this->crypt->decrypt($encryptedClientSecret);
        } catch (Throwable $e) {
            throw new Error('Failed to decrypt clientSecret: ' . $e->getMessage());
        }

        // Step 1: ensure verify_token exists.
        // Note: persistence is delegated to EncryptMetaLeadAdsWebhookVerifyToken
        // (BeforeSave hook). We set the PLAINTEXT here — the hook will encrypt
        // it on save. Double-encryption would lock the webhook out for good.
        $encryptedVerifyToken = (string) ($provider->get('webhookVerifyToken') ?? '');
        $verifyTokenGenerated = false;

        if ($encryptedVerifyToken === '') {
            $verifyToken = bin2hex(random_bytes(32));
            $provider->set('webhookVerifyToken', $verifyToken);
            $this->entityManager->saveEntity($provider);
            $verifyTokenGenerated = true;

            $this->log->info(
                "WebhookConfigService: generated new webhookVerifyToken for meta-leadads provider {$oAuthProviderId}."
            );
        } else {
            try {
                $verifyToken = $this->crypt->decrypt($encryptedVerifyToken);
            } catch (Throwable $e) {
                throw new Error('Failed to decrypt existing webhookVerifyToken: ' . $e->getMessage());
            }
        }

        // Step 2: build per-provider callback URL (BYOA-safe).
        $callbackUrl = $this->buildCallbackUrl($oAuthProviderId);

        // Step 3: register the App-level webhook subscription with Meta.
        $warnings = [];
        $appSubscriptionRegistered = false;
        $subscriptions = [];

        try {
            $this->graphApi->registerAppWebhook(
                $appId,
                $appSecret,
                $callbackUrl,
                $verifyToken,
                ['leadgen'],
            );

            $appSubscriptionRegistered = true;

            $this->log->info(
                "WebhookConfigService: registered app-level webhook for Meta App {$appId} → {$callbackUrl}"
            );
        } catch (Throwable $e) {
            $warnings[] = 'Failed to register app-level webhook with Meta: ' . $e->getMessage()
                . '. You can configure it manually in the Meta App Dashboard.';
            $this->log->error('WebhookConfigService: registerAppWebhook failed: ' . $e->getMessage());
        }

        // Step 4: introspect current subscriptions (best-effort, for UI feedback).
        try {
            $subscriptions = $this->graphApi->getAppWebhookSubscriptions($appId, $appSecret);
        } catch (Throwable $e) {
            $warnings[] = 'Could not read current webhook subscriptions: ' . $e->getMessage();
            $this->log->warning('WebhookConfigService: getAppWebhookSubscriptions failed: ' . $e->getMessage());
        }

        return [
            'verifyTokenGenerated' => $verifyTokenGenerated,
            'verifyToken' => $verifyToken,
            'callbackUrl' => $callbackUrl,
            'appSubscriptionRegistered' => $appSubscriptionRegistered,
            'subscriptions' => $subscriptions,
            'warnings' => $warnings,
        ];
    }

    /**
     * Per-provider webhook callback URL. The providerId in the path lets
     * `MetaLeadAdsWebhook` resolve the exact OAuthProvider row (and its
     * clientSecret + webhookVerifyToken) without ambiguity, enabling BYOA:
     * multiple Meta Apps can each point at their own URL without colliding
     * on signature verification.
     */
    private function buildCallbackUrl(string $oAuthProviderId): string
    {
        $siteUrl = (string) $this->config->get('siteUrl');

        if ($siteUrl === '') {
            throw new Error('System siteUrl is not configured. Set it under Admin → Settings.');
        }

        return rtrim($siteUrl, '/') . self::WEBHOOK_PATH . '/' . rawurlencode($oAuthProviderId);
    }
}
