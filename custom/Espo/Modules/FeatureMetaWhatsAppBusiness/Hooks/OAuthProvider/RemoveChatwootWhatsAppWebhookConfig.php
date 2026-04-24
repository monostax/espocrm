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

namespace Espo\Modules\FeatureMetaWhatsAppBusiness\Hooks\OAuthProvider;

use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * When a meta-whatsapp OAuthProvider is deleted, remove its slot from every
 * ChatwootPlatform's install-wide WhatsApp webhook config map. Prevents a
 * stale `app_secret` from remaining accepted by Chatwoot's signature check
 * after provider rotation/removal.
 */
class RemoveChatwootWhatsAppWebhookConfig
{
    public static int $order = 10;

    private const META_PROVIDER = 'meta-whatsapp';
    private const CHATWOOT_CONFIG_PATH = '/platform/api/v1/whatsapp_webhook_config';
    private const CURL_TIMEOUT_SECONDS = 10;

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function afterRemove(Entity $entity, array $options): void
    {
        if ($entity->get('provider') !== self::META_PROVIDER) {
            return;
        }

        $configId = (string) $entity->getId();

        $platforms = $this->entityManager
            ->getRDBRepository('ChatwootPlatform')
            ->find();

        foreach ($platforms as $platform) {
            $this->removeFromPlatform($platform, $configId);
        }
    }

    private function removeFromPlatform(Entity $platform, string $configId): void
    {
        $backendUrl = (string) ($platform->get('backendUrl') ?? '');
        $accessToken = (string) ($platform->get('accessToken') ?? '');
        $platformName = $platform->get('name');

        if ($backendUrl === '' || $accessToken === '') {
            return;
        }

        $url = rtrim($backendUrl, '/') . self::CHATWOOT_CONFIG_PATH;
        $payload = json_encode(['config_id' => $configId]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::CURL_TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CURL_TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'api_access_token: ' . $accessToken,
        ]);

        curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->log->warning(
                "RemoveChatwootWhatsAppWebhookConfig: cURL error removing slot from ChatwootPlatform " .
                "'{$platformName}' for config_id {$configId}: {$curlError}"
            );
            return;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->log->warning(
                "RemoveChatwootWhatsAppWebhookConfig: HTTP {$httpCode} removing slot from ChatwootPlatform " .
                "'{$platformName}' for config_id {$configId}"
            );
            return;
        }

        $this->log->info(
            "RemoveChatwootWhatsAppWebhookConfig: removed slot for config_id {$configId} from ChatwootPlatform '{$platformName}'."
        );
    }
}
