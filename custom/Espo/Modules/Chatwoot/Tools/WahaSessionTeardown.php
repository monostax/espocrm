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

namespace Espo\Modules\Chatwoot\Tools;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\WahaApiClient;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Ends the WAHA side of a ChatwootInboxIntegration: deletes its WAHA app,
 * stops the session and deletes it, so the WhatsApp number is logged out.
 *
 * Used when the channel itself is removed (CleanupOnRemove), when its
 * ChatwootAccount is removed (children are then removed with `cascadeParent`,
 * which skips CleanupOnRemove), and by tenant offboarding.
 */
class WahaSessionTeardown
{
    public function __construct(
        private EntityManager $entityManager,
        private WahaApiClient $wahaApiClient,
        private Log $log,
    ) {}

    public static function hasSession(Entity $integration): bool
    {
        return WhatsAppChannel::usesWahaSession($integration->get('channelType'))
            && $integration->get('wahaSessionName')
            && $integration->get('wahaPlatformId');
    }

    /**
     * Safe to repeat: a session WAHA no longer knows counts as ended.
     *
     * @throws Error The session could not be deleted.
     */
    public function tearDown(Entity $integration): void
    {
        if (!self::hasSession($integration)) {
            return;
        }

        $sessionName = (string) $integration->get('wahaSessionName');
        $platformId = (string) $integration->get('wahaPlatformId');
        $platform = $this->entityManager->getEntityById('WahaPlatform', $platformId);
        $url = $platform?->get('backendUrl');
        $apiKey = $platform?->get('apiKey');

        if (!$url || !$apiKey) {
            throw new Error("WahaPlatform {$platformId} is missing or has no URL or API key; session {$sessionName} is still live.");
        }

        $appId = $integration->get('wahaAppId');

        if ($appId) {
            try {
                $this->wahaApiClient->deleteApp($url, $apiKey, (string) $appId);
            } catch (\Exception $e) {
                $this->log->warning("WAHA teardown: deleting app {$appId} failed: " . $e->getMessage());
            }
        }

        // A WORKING session can refuse deletion while the WhatsApp socket is
        // still connected: stop it first (mirrors provisionWahaSendOnlySession()).
        try {
            $this->wahaApiClient->stopSession($url, $apiKey, $sessionName);
        } catch (\Exception $e) {
            $this->log->debug("WAHA teardown: stopping {$sessionName} failed, deleting anyway: " . $e->getMessage());
        }

        try {
            $this->wahaApiClient->deleteSession($url, $apiKey, $sessionName);
        } catch (Error $e) {
            if (!str_contains($e->getMessage(), 'HTTP 404')) {
                throw $e;
            }
        }

        $this->log->info("WAHA teardown: session {$sessionName} ended for ChatwootInboxIntegration {$integration->getId()}.");
    }
}
