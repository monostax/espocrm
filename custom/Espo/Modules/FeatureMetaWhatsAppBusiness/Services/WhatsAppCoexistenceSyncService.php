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

namespace Espo\Modules\FeatureMetaWhatsAppBusiness\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use Espo\Tools\OAuth\TokensProvider;

/**
 * Drives the WhatsApp Coexistence (WhatsApp Business app onboarding) data sync.
 *
 * Coexistence onboarding has two sides:
 *
 *  1. The customer pastes a 6-digit verification code in the
 *     WhatsApp Business app (Settings → Account → Business Platform).
 *     Meta flips `GET /{phone_number_id}.is_on_biz_app` to `true` and
 *     `platform_type` to `CLOUD_API`. **Only after both flip will Meta
 *     start delivering `messages` and `smb_message_echoes` webhooks.**
 *
 *  2. Our backend has 24 hours to call:
 *        POST /{phone_number_id}/smb_app_data {sync_type:smb_app_state_sync}
 *        POST /{phone_number_id}/smb_app_data {sync_type:history}     (optional)
 *
 *     Missing this window means the customer must offboard + redo
 *     onboarding entirely.
 *
 * This service is invoked:
 *   - immediately after a successful Embedded Signup `FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING`
 *     event (via {@see WhatsAppEmbeddedSignup} controller), and
 *   - by the {@see \Espo\Modules\Chatwoot\Jobs\SyncWhatsAppCoexistenceData}
 *     job (retry path, e.g. when the customer hadn't finished the in-app
 *     step yet when we first tried).
 */
class WhatsAppCoexistenceSyncService
{
    public function __construct(
        private EntityManager $entityManager,
        private TokensProvider $tokensProvider,
        private MetaGraphApiClient $metaGraphApiClient,
        private Log $log,
    ) {}

    /**
     * A connected phone does not imply webhook delivery. Re-establish the
     * OAuth provider's app subscription after reauthorization or unsubscription.
     * Existing subscriptions (including callback overrides) are left intact.
     */
    public function ensureWebhookSubscription(string $oAuthAccountId, ?string $businessAccountId = null): void
    {
        $account = $this->entityManager->getEntityById('OAuthAccount', $oAuthAccountId);

        if (!$account) {
            throw new Error("OAuthAccount not found: {$oAuthAccountId}");
        }

        $businessAccountId = $businessAccountId ?: $account->get('whatsappBusinessAccountId');
        $provider = $this->entityManager->getEntityById('OAuthProvider', (string) $account->get('providerId'));
        $appId = (string) ($provider?->get('clientId') ?? '');

        if (!$businessAccountId || !$appId) {
            throw new Error('WhatsApp Business Account ID and Meta App ID are required for webhook subscription.');
        }

        $accessToken = $this->tokensProvider->get($oAuthAccountId)->getAccessToken();

        if (!$accessToken) {
            throw new Error("Unable to obtain access token for OAuthAccount {$oAuthAccountId}.");
        }

        $isSubscribed = static function (array $apps) use ($appId): bool {
            foreach ($apps as $app) {
                if ((string) ($app['whatsapp_business_api_data']['id'] ?? '') === $appId) {
                    return true;
                }
            }

            return false;
        };

        if ($isSubscribed($this->metaGraphApiClient->getSubscribedApps($accessToken, $businessAccountId))) {
            return;
        }

        $this->metaGraphApiClient->subscribeApp($accessToken, $businessAccountId);

        if (!$isSubscribed($this->metaGraphApiClient->getSubscribedApps($accessToken, $businessAccountId))) {
            throw new Error("Meta App {$appId} webhook subscription could not be confirmed for WABA {$businessAccountId}.");
        }

        $this->log->info("WhatsAppCoexistenceSyncService: restored Meta App {$appId} webhook subscription for WABA {$businessAccountId}.");
    }

    /**
     * Run the full Coexistence sync for a single OAuthAccount.
     *
     * Steps:
     *   1. Read `whatsappPhoneNumberId` from the OAuthAccount.
     *   2. Verify `platform_type=CLOUD_API` AND `is_on_biz_app=true`.
     *      If not, return early with `pending` status — the job will retry.
     *   3. POST /{phone_number_id}/smb_app_data {sync_type:smb_app_state_sync}.
     *   4. POST /{phone_number_id}/smb_app_data {sync_type:history}
     *      (best-effort: history sync may fail with code 2593109 if the
     *      customer didn't allow history sharing in the in-app prompt;
     *      we log and continue.)
     *   5. Stamp `whatsappCoexistenceSyncedAt` on the OAuthAccount.
     *
     * @return array{
     *     status: 'synced'|'pending'|'failed',
     *     isOnBizApp: bool,
     *     platformType: ?string,
     *     historySynced?: bool,
     *     error?: string,
     * }
     * @throws Error
     */
    public function sync(string $oAuthAccountId): array
    {
        $account = $this->entityManager->getEntityById('OAuthAccount', $oAuthAccountId);

        if (!$account) {
            throw new Error("OAuthAccount not found: {$oAuthAccountId}");
        }

        $phoneNumberId = (string) ($account->get('whatsappPhoneNumberId') ?? '');

        if ($phoneNumberId === '') {
            throw new Error(
                "OAuthAccount {$oAuthAccountId} has no whatsappPhoneNumberId. " .
                "Cannot run Coexistence sync — the Embedded Signup session_info must have set it."
            );
        }

        $tokens = $this->tokensProvider->get($oAuthAccountId);
        $accessToken = $tokens->getAccessToken();

        if (!$accessToken) {
            throw new Error("Unable to obtain access token for OAuthAccount {$oAuthAccountId}.");
        }

        // Step 1: probe the phone number for Coexistence readiness.
        $phone = $this->metaGraphApiClient->getPhoneNumber($accessToken, $phoneNumberId);
        $isOnBizApp = (bool) ($phone['is_on_biz_app'] ?? false);
        $platformType = $phone['platform_type'] ?? null;

        if ($platformType !== 'CLOUD_API' || !$isOnBizApp) {
            $this->log->info(
                "WhatsAppCoexistenceSyncService: phone {$phoneNumberId} not Coexistence-ready yet " .
                "(platform_type={$platformType}, is_on_biz_app=" . ($isOnBizApp ? 'true' : 'false') . "). " .
                "Sync will retry."
            );

            return [
                'status' => 'pending',
                'isOnBizApp' => $isOnBizApp,
                'platformType' => $platformType,
            ];
        }

        // Subscribe before requesting data; successful SMB sync alone does not
        // guarantee Meta will deliver its events to this app.
        $this->ensureWebhookSubscription($oAuthAccountId);

        // Step 2: required state sync.
        try {
            $this->metaGraphApiClient->postSmbAppData(
                $accessToken,
                $phoneNumberId,
                'smb_app_state_sync',
            );
        } catch (\Exception $e) {
            $this->log->error(
                "WhatsAppCoexistenceSyncService: smb_app_state_sync failed for {$phoneNumberId}: " .
                $e->getMessage()
            );

            return [
                'status' => 'failed',
                'isOnBizApp' => $isOnBizApp,
                'platformType' => $platformType,
                'error' => $e->getMessage(),
            ];
        }

        // Step 3: best-effort history sync. The user may have declined the
        // "share chat history" prompt inside the WhatsApp Business app; in
        // that case Meta returns code 2593109 and we proceed.
        $historySynced = false;

        try {
            $this->metaGraphApiClient->postSmbAppData(
                $accessToken,
                $phoneNumberId,
                'history',
            );
            $historySynced = true;
        } catch (\Exception $e) {
            $this->log->warning(
                "WhatsAppCoexistenceSyncService: history sync failed for {$phoneNumberId} " .
                "(continuing without history): " . $e->getMessage()
            );
        }

        // Step 4: stamp success on the OAuthAccount.
        $account->set('whatsappCoexistenceSyncedAt', gmdate('Y-m-d H:i:s'));
        $this->entityManager->saveEntity($account, ['silent' => true]);

        $this->log->info(
            "WhatsAppCoexistenceSyncService: phone {$phoneNumberId} successfully synced " .
            "(history=" . ($historySynced ? 'yes' : 'no') . ")."
        );

        return [
            'status' => 'synced',
            'isOnBizApp' => $isOnBizApp,
            'platformType' => $platformType,
            'historySynced' => $historySynced,
        ];
    }
}
