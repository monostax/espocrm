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

namespace Espo\Modules\FeatureMetaInstagram\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Crypt;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;

/**
 * End-to-end orchestrator for configuring the Meta App Instagram webhook.
 *
 * Why this service is Chatwoot-only (no Meta App-level API call):
 * ---------------------------------------------------------------
 * The "Instagram API with Instagram Login" product on Meta is authenticated
 * via `api.instagram.com` + `graph.instagram.com`. Its OAuth `client_id` is
 * the Instagram-scoped App ID — NOT the Meta App ID that `graph.facebook.com`
 * recognises. Calling `POST graph.facebook.com/v22.0/{appId}/subscriptions`
 * with an App Access Token built from those credentials fails with
 * "Error validating application. Cannot get application info due to a system
 * error." (code 190/101) because Meta literally cannot look the app up by
 * that ID.
 *
 * Therefore, the App-level webhook callback URL + verify token MUST be
 * configured manually in the Meta App Dashboard → Products → Instagram →
 * Webhooks. This service only handles what we CAN automate:
 *
 *  1. Ensure a verify_token exists on the `meta-instagram` OAuthProvider row
 *     (auto-generates one if blank).
 *  2. For each configured `ChatwootPlatform`, push the verify_token to
 *     Chatwoot's `PUT /platform/api/v1/instagram_webhook_config` endpoint so
 *     the Chatwoot install's `hub.verify_token` check will match whatever
 *     was pasted into Meta Dashboard.
 *  3. Return the plaintext verify_token in the response so the admin can
 *     copy-paste it into the Meta App Dashboard.
 *
 * Per-IG-account subscription (`graph.instagram.com/{ig_user_id}/subscribed_apps`)
 * happens at Chatwoot inbox activation time, not here.
 */
class ChatwootWebhookConfigService
{
    private const META_PROVIDER = 'meta-instagram';
    private const CHATWOOT_WEBHOOK_PATH = '/webhooks/instagram';
    private const CHATWOOT_CONFIG_PATH = '/platform/api/v1/instagram_webhook_config';
    private const CURL_TIMEOUT_SECONDS = 10;

    public function __construct(
        private EntityManager $entityManager,
        private Crypt $crypt,
        private Log $log,
    ) {}

    /**
     * @param string $oAuthProviderId The meta-instagram OAuthProvider row id.
     * @return array{
     *     verifyTokenGenerated: bool,
     *     verifyToken: string,
     *     callbackUrl: string,
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
                "OAuthProvider {$oAuthProviderId} is not a meta-instagram provider (got: " .
                (string) $provider->get('provider') . ')'
            );
        }

        // Decrypt the app secret so we can push it to Chatwoot. Meta signs
        // webhook events with this secret; Chatwoot needs it to validate the
        // X-Hub-Signature-256 header in Webhooks::InstagramController.
        $encryptedClientSecret = (string) ($provider->get('clientSecret') ?? '');
        $appSecret = $encryptedClientSecret !== ''
            ? $this->crypt->decrypt($encryptedClientSecret)
            : '';

        // Step 1: Ensure a verify_token exists on the provider row.
        $encryptedVerifyToken = (string) ($provider->get('webhookVerifyToken') ?? '');
        $verifyTokenGenerated = false;

        if ($encryptedVerifyToken === '') {
            $verifyToken = bin2hex(random_bytes(32));
            $provider->set('webhookVerifyToken', $this->crypt->encrypt($verifyToken));
            $this->entityManager->saveEntity($provider);
            $verifyTokenGenerated = true;

            $this->log->info(
                'ChatwootWebhookConfigService: generated new webhookVerifyToken for meta-instagram OAuthProvider.'
            );
        } else {
            $verifyToken = $this->crypt->decrypt($encryptedVerifyToken);
        }

        // Resolve ALL ChatwootPlatforms. We need at least one so the admin
        // has a sane callback URL to paste into the Meta Dashboard, and we
        // push the verify_token to every platform for Chatwoot-side sync.
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

        $primaryPlatform = $this->selectPrimaryPlatform($platformList);
        $callbackUrl = $this->buildCallbackUrl($primaryPlatform);

        // Step 2: Push this provider's slot (keyed by OAuthProvider.id) to
        // every ChatwootPlatform (best-effort). Each provider owns its own
        // slot so BYO Meta App customers don't overwrite each other.
        $chatwootResults = [];

        foreach ($platformList as $platform) {
            $chatwootResults[] = $this->syncToChatwootPlatform(
                $platform,
                $oAuthProviderId,
                $verifyToken,
                $appSecret
            );
        }

        return [
            'verifyTokenGenerated' => $verifyTokenGenerated,
            'verifyToken' => $verifyToken,
            'callbackUrl' => $callbackUrl,
            'chatwoot' => $chatwootResults,
        ];
    }

    /**
     * @param array<int, \Espo\ORM\Entity> $platforms
     */
    private function selectPrimaryPlatform(array $platforms): \Espo\ORM\Entity
    {
        foreach ($platforms as $p) {
            if ($p->get('isDefault')) {
                return $p;
            }
        }

        return $platforms[0];
    }

    private function buildCallbackUrl(\Espo\ORM\Entity $platform): string
    {
        $backendUrl = (string) ($platform->get('backendUrl') ?? '');

        if ($backendUrl === '') {
            throw new Error(
                "ChatwootPlatform '{$platform->get('name')}' is missing backendUrl."
            );
        }

        return rtrim($backendUrl, '/') . self::CHATWOOT_WEBHOOK_PATH;
    }

    /**
     * @return array{platformId: string, platformName: ?string, status: string, error?: string}
     */
    private function syncToChatwootPlatform(
        \Espo\ORM\Entity $platform,
        string $configId,
        string $verifyToken,
        string $appSecret,
    ): array {
        $platformId = (string) $platform->getId();
        $platformName = $platform->get('name');
        $backendUrl = (string) ($platform->get('backendUrl') ?? '');
        // ChatwootPlatform.accessToken is stored as plaintext (see all other
        // usages in the Chatwoot module). Do NOT decrypt it.
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
        $body = [
            'config_id' => $configId,
            'verify_token' => $verifyToken,
        ];

        if ($appSecret !== '') {
            $body['app_secret'] = $appSecret;
        }

        $payload = json_encode($body);

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
                "ChatwootWebhookConfigService: cURL error pushing verify_token to " .
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
                "ChatwootWebhookConfigService: HTTP {$httpCode} pushing verify_token to " .
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
            "ChatwootWebhookConfigService: verify_token synced to ChatwootPlatform '{$platformName}'."
        );

        return [
            'platformId' => $platformId,
            'platformName' => $platformName,
            'status' => 'success',
        ];
    }
}
