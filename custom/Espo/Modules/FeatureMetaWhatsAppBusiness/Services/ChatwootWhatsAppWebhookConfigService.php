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
use Espo\Core\Utils\Crypt;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;

/**
 * Registers a meta-whatsapp OAuthProvider's `clientSecret` with every
 * ChatwootPlatform so Chatwoot can validate HMAC signatures on inbound
 * WhatsApp webhook events.
 *
 * Why no verify_token push (unlike Instagram):
 * --------------------------------------------
 * WhatsApp uses Meta's per-WABA `override_callback_uri` feature — each
 * Channel::Whatsapp inbox owns its own `provider_config.webhook_verify_token`
 * generated at channel-create time, and the callback URL includes the
 * phone_number segment so Chatwoot can look up the right token per request.
 * Install-wide verify tokens are unnecessary for WhatsApp.
 *
 * What we push:
 * - `app_secret` — keyed by `config_id = OAuthProvider.id` so multiple Meta
 *   Apps (Monostax-shared + BYO customer apps) coexist, each signing its own
 *   events; Webhooks::WhatsappController tries all configured secrets.
 */
class ChatwootWhatsAppWebhookConfigService
{
    private const META_PROVIDER = 'meta-whatsapp';
    private const CHATWOOT_CONFIG_PATH = '/platform/api/v1/whatsapp_webhook_config';
    private const CURL_TIMEOUT_SECONDS = 10;

    public function __construct(
        private EntityManager $entityManager,
        private Crypt $crypt,
        private Log $log,
    ) {}

    /**
     * @return array{
     *     chatwoot: array<int, array{platformId: string, platformName: ?string, status: string, error?: string}>
     * }
     * @throws Error
     */
    public function configure(string $oAuthProviderId): array
    {
        $provider = $this->entityManager->getEntityById('OAuthProvider', $oAuthProviderId);

        if (!$provider) {
            throw new Error("OAuthProvider not found: {$oAuthProviderId}");
        }

        if ($provider->get('provider') !== self::META_PROVIDER) {
            throw new Error(
                "OAuthProvider {$oAuthProviderId} is not a meta-whatsapp provider (got: " .
                (string) $provider->get('provider') . ')'
            );
        }

        $encryptedClientSecret = (string) ($provider->get('clientSecret') ?? '');

        if ($encryptedClientSecret === '') {
            throw new Error(
                'The meta-whatsapp OAuthProvider is missing clientSecret. ' .
                'Configure it in Admin → OAuth Providers first.'
            );
        }

        $appSecret = $this->crypt->decrypt($encryptedClientSecret);

        $platforms = $this->entityManager
            ->getRDBRepository('ChatwootPlatform')
            ->find();

        $platformList = iterator_to_array($platforms);

        if (empty($platformList)) {
            throw new Error(
                'No ChatwootPlatform configured. Create at least one ChatwootPlatform ' .
                '(with backendUrl and accessToken) before configuring the Meta webhook.'
            );
        }

        $chatwootResults = [];

        foreach ($platformList as $platform) {
            $chatwootResults[] = $this->syncToChatwootPlatform($platform, $oAuthProviderId, $appSecret);
        }

        return [
            'chatwoot' => $chatwootResults,
        ];
    }

    /**
     * @return array{platformId: string, platformName: ?string, status: string, error?: string}
     */
    private function syncToChatwootPlatform(
        \Espo\ORM\Entity $platform,
        string $configId,
        string $appSecret,
    ): array {
        $platformId = (string) $platform->getId();
        $platformName = $platform->get('name');
        $backendUrl = (string) ($platform->get('backendUrl') ?? '');
        // ChatwootPlatform.accessToken is plaintext per convention.
        $accessToken = (string) ($platform->get('accessToken') ?? '');

        if ($backendUrl === '' || $accessToken === '') {
            return [
                'platformId' => $platformId,
                'platformName' => $platformName,
                'status' => 'skipped',
                'error' => 'Platform missing backendUrl or accessToken.',
            ];
        }

        $url = rtrim($backendUrl, '/') . self::CHATWOOT_CONFIG_PATH;
        $payload = json_encode([
            'config_id' => $configId,
            'app_secret' => $appSecret,
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::CURL_TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CURL_TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'api_access_token: ' . $accessToken,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->log->error(
                "ChatwootWhatsAppWebhookConfigService: cURL error pushing app_secret to " .
                "ChatwootPlatform '{$platformName}': {$curlError}"
            );

            return [
                'platformId' => $platformId,
                'platformName' => $platformName,
                'status' => 'failed',
                'error' => $curlError,
            ];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $decoded = json_decode((string) $response, true);
            $errorMessage = is_array($decoded)
                ? ($decoded['error'] ?? $decoded['message'] ?? (string) $response)
                : (string) $response;

            $this->log->error(
                "ChatwootWhatsAppWebhookConfigService: HTTP {$httpCode} pushing app_secret to " .
                "ChatwootPlatform '{$platformName}': {$errorMessage}"
            );

            return [
                'platformId' => $platformId,
                'platformName' => $platformName,
                'status' => 'failed',
                'error' => "HTTP {$httpCode}: {$errorMessage}",
            ];
        }

        $this->log->info(
            "ChatwootWhatsAppWebhookConfigService: app_secret synced to ChatwootPlatform '{$platformName}'."
        );

        return [
            'platformId' => $platformId,
            'platformName' => $platformName,
            'status' => 'success',
        ];
    }
}
