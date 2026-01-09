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

namespace Espo\Modules\Waha\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Exceptions\BadRequest;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Core\Acl;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use stdClass;

/**
 * Service for CommunicationChannel entity.
 * Handles the unified flow of creating WhatsApp connections with Chatwoot integration.
 */
class CommunicationChannel
{
    public const ENTITY_TYPE = 'CommunicationChannel';

    public function __construct(
        private EntityManager $entityManager,
        private WahaApiClient $wahaApiClient,
        private ChatwootApiClient $chatwootApiClient,
        private Log $log,
        private Acl $acl
    ) {}

    /**
     * Activate a communication channel.
     * This creates the WAHA session, Chatwoot inbox, and starts the connection process.
     *
     * @param string $channelId
     * @return Entity
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function activate(string $channelId): Entity
    {
        if (!$this->acl->checkScope(self::ENTITY_TYPE, 'edit')) {
            throw new Forbidden("No edit access to CommunicationChannel.");
        }

        $channel = $this->entityManager->getEntityById(self::ENTITY_TYPE, $channelId);

        if (!$channel) {
            throw new NotFound("CommunicationChannel with ID '{$channelId}' not found.");
        }

        $status = $channel->get('status');
        if (!in_array($status, ['DRAFT', 'FAILED', 'DISCONNECTED'])) {
            throw new BadRequest("Channel can only be activated from DRAFT, FAILED, or DISCONNECTED status.");
        }

        // Update status to CREATING
        $channel->set('status', 'CREATING');
        $channel->set('errorMessage', null);
        $this->entityManager->saveEntity($channel);

        try {
            // Step 1: Get platform and account details
            $wahaPlatform = $channel->get('wahaPlatform');
            $chatwootAccount = $channel->get('chatwootAccount');

            if (!$wahaPlatform) {
                throw new Error("WAHA Platform not set.");
            }
            if (!$chatwootAccount) {
                throw new Error("Chatwoot Account not set.");
            }

            $wahaUrl = $wahaPlatform->get('url');
            $wahaApiKey = $wahaPlatform->get('apiKey');
            $chatwootPlatform = $chatwootAccount->get('platform');

            if (!$chatwootPlatform) {
                throw new Error("Chatwoot Platform not found for account.");
            }

            $chatwootUrl = $chatwootPlatform->get('url');
            $chatwootAccessToken = $chatwootPlatform->get('accessToken');
            $chatwootAccountId = $chatwootAccount->get('chatwootAccountId');
            $chatwootAccountApiKey = $chatwootAccount->get('apiKey');

            // Step 2: Generate session name and app ID (needed for webhook URL)
            $sessionName = 'channel_' . $channelId;
            $appId = 'app_' . bin2hex(random_bytes(16));

            // Step 3: Build WAHA webhook URL for Chatwoot to call
            // Format: {waha_url}/webhooks/chatwoot/{session}/{app_id}
            $wahaWebhookUrl = rtrim($wahaUrl, '/') . '/webhooks/chatwoot/' . urlencode($sessionName) . '/' . urlencode($appId);

            // Step 4: Create Chatwoot Inbox (API channel) with WAHA webhook URL
            $inboxName = 'WhatsApp - ' . $channel->get('name');
            $inboxResult = $this->createChatwootInbox(
                $chatwootUrl,
                $chatwootAccountApiKey ?: $chatwootAccessToken,
                $chatwootAccountId,
                $inboxName,
                $wahaWebhookUrl
            );

            $channel->set('chatwootInboxId', $inboxResult['id']);
            $channel->set('chatwootInboxIdentifier', $inboxResult['inbox_identifier'] ?? null);

            // Step 5: Create WAHA Session
            $this->wahaApiClient->createSession($wahaUrl, $wahaApiKey, [
                'name' => $sessionName,
            ]);

            $channel->set('wahaSessionName', $sessionName);
            $channel->set('wahaAppId', $appId);

            // Step 6: Create WAHA Chatwoot App
            $appConfig = [
                'linkPreview' => 'OFF',
                'locale' => str_replace('_', '-', $chatwootAccount->get('locale') ?? 'en-US'),
                'url' => rtrim($chatwootUrl, '/'),
                'accountId' => (int) $chatwootAccountId,
                'accountToken' => $chatwootAccountApiKey ?? '',
                'inboxId' => (int) $inboxResult['id'],
                'inboxIdentifier' => $inboxResult['inbox_identifier'] ?? '',
                'templates' => new \stdClass(),
                'commands' => [
                    'server' => true,
                    'queue' => true,
                ],
                'conversations' => [
                    'sort' => 'created_newest',
                    'status' => ['open', 'pending', 'snoozed'],
                ],
            ];

            $this->wahaApiClient->createApp($wahaUrl, $wahaApiKey, [
                'id' => $appId,
                'session' => $sessionName,
                'app' => 'chatwoot',
                'enabled' => true,
                'config' => $appConfig,
            ]);

            // Step 7: Start WAHA Session
            $this->wahaApiClient->startSession($wahaUrl, $wahaApiKey, $sessionName);

            // Step 8: Update status to PENDING_QR
            $channel->set('status', 'PENDING_QR');
            $this->entityManager->saveEntity($channel);

            return $channel;

        } catch (\Exception $e) {
            $this->log->error("CommunicationChannel activation failed: " . $e->getMessage());
            $channel->set('status', 'FAILED');
            $channel->set('errorMessage', $e->getMessage());
            $this->entityManager->saveEntity($channel);
            throw new Error("Activation failed: " . $e->getMessage());
        }
    }

    /**
     * Complete the channel setup after QR code is scanned.
     * Updates WhatsApp info and sets status to ACTIVE.
     *
     * @param string $channelId
     * @return Entity
     * @throws Error
     */
    public function completeSetup(string $channelId): Entity
    {
        $channel = $this->entityManager->getEntityById(self::ENTITY_TYPE, $channelId);

        if (!$channel) {
            throw new NotFound("CommunicationChannel not found.");
        }

        $wahaPlatform = $channel->get('wahaPlatform');

        $wahaUrl = $wahaPlatform->get('url');
        $wahaApiKey = $wahaPlatform->get('apiKey');
        $sessionName = $channel->get('wahaSessionName');

        // Get session info to verify it's connected
        $sessionInfo = $this->wahaApiClient->getSession($wahaUrl, $wahaApiKey, $sessionName);

        if ($sessionInfo['status'] !== 'WORKING') {
            throw new Error("Session is not connected. Status: " . $sessionInfo['status']);
        }

        // Update WhatsApp info
        if (isset($sessionInfo['me'])) {
            $channel->set('whatsappId', $sessionInfo['me']['id'] ?? null);
            $channel->set('whatsappName', $sessionInfo['me']['pushName'] ?? null);
        }

        $channel->set('status', 'ACTIVE');
        $channel->set('connectedAt', date('Y-m-d H:i:s'));
        $channel->set('errorMessage', null);

        $this->entityManager->saveEntity($channel);

        return $channel;
    }

    /**
     * Disconnect a communication channel.
     * Stops the WAHA session but keeps the configuration.
     *
     * @param string $channelId
     * @return Entity
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function disconnect(string $channelId): Entity
    {
        if (!$this->acl->checkScope(self::ENTITY_TYPE, 'edit')) {
            throw new Forbidden("No edit access to CommunicationChannel.");
        }

        $channel = $this->entityManager->getEntityById(self::ENTITY_TYPE, $channelId);

        if (!$channel) {
            throw new NotFound("CommunicationChannel not found.");
        }

        $wahaPlatform = $channel->get('wahaPlatform');
        $sessionName = $channel->get('wahaSessionName');

        if ($wahaPlatform && $sessionName) {
            try {
                $wahaUrl = $wahaPlatform->get('url');
                $wahaApiKey = $wahaPlatform->get('apiKey');
                
                // Stop the session
                $this->wahaApiClient->stopSession($wahaUrl, $wahaApiKey, $sessionName);
            } catch (\Exception $e) {
                $this->log->warning("Failed to stop WAHA session: " . $e->getMessage());
            }
        }

        $channel->set('status', 'DISCONNECTED');
        $this->entityManager->saveEntity($channel);

        return $channel;
    }

    /**
     * Reconnect a disconnected channel.
     * Restarts the WAHA session.
     *
     * @param string $channelId
     * @return Entity
     * @throws Error
     */
    public function reconnect(string $channelId): Entity
    {
        $channel = $this->entityManager->getEntityById(self::ENTITY_TYPE, $channelId);

        if (!$channel) {
            throw new NotFound("CommunicationChannel not found.");
        }

        if ($channel->get('status') !== 'DISCONNECTED') {
            throw new BadRequest("Channel can only be reconnected from DISCONNECTED status.");
        }

        $wahaPlatform = $channel->get('wahaPlatform');
        $sessionName = $channel->get('wahaSessionName');

        if (!$wahaPlatform || !$sessionName) {
            // No existing session, need to activate from scratch
            return $this->activate($channelId);
        }

        $wahaUrl = $wahaPlatform->get('url');
        $wahaApiKey = $wahaPlatform->get('apiKey');

        try {
            // Try to start the existing session
            $this->wahaApiClient->startSession($wahaUrl, $wahaApiKey, $sessionName);
            
            // Check session status
            $sessionInfo = $this->wahaApiClient->getSession($wahaUrl, $wahaApiKey, $sessionName);
            
            if ($sessionInfo['status'] === 'WORKING') {
                $channel->set('status', 'ACTIVE');
                if (isset($sessionInfo['me'])) {
                    $channel->set('whatsappId', $sessionInfo['me']['id'] ?? null);
                    $channel->set('whatsappName', $sessionInfo['me']['pushName'] ?? null);
                }
            } elseif ($sessionInfo['status'] === 'SCAN_QR_CODE') {
                $channel->set('status', 'PENDING_QR');
            } else {
                $channel->set('status', 'CONNECTING');
            }

            $channel->set('errorMessage', null);
            $this->entityManager->saveEntity($channel);

            return $channel;

        } catch (\Exception $e) {
            $this->log->error("Reconnect failed: " . $e->getMessage());
            $channel->set('status', 'FAILED');
            $channel->set('errorMessage', $e->getMessage());
            $this->entityManager->saveEntity($channel);
            throw new Error("Reconnect failed: " . $e->getMessage());
        }
    }

    /**
     * Get QR code for a channel.
     *
     * @param string $channelId
     * @return stdClass
     * @throws Error
     */
    public function getQrCode(string $channelId): stdClass
    {
        $channel = $this->entityManager->getEntityById(self::ENTITY_TYPE, $channelId);

        if (!$channel) {
            throw new NotFound("CommunicationChannel not found.");
        }

        $wahaPlatform = $channel->get('wahaPlatform');
        $sessionName = $channel->get('wahaSessionName');

        if (!$wahaPlatform || !$sessionName) {
            throw new Error("Channel not activated yet.");
        }

        $wahaUrl = $wahaPlatform->get('url');
        $wahaApiKey = $wahaPlatform->get('apiKey');

        $qrData = $this->wahaApiClient->getQrCode($wahaUrl, $wahaApiKey, $sessionName);

        return (object) [
            'mimetype' => $qrData['mimetype'],
            'data' => $qrData['data'],
            'dataUrl' => 'data:' . $qrData['mimetype'] . ';base64,' . $qrData['data']
        ];
    }

    /**
     * Check and update channel status from WAHA.
     *
     * @param string $channelId
     * @return Entity
     */
    public function checkStatus(string $channelId): Entity
    {
        $channel = $this->entityManager->getEntityById(self::ENTITY_TYPE, $channelId);

        if (!$channel) {
            throw new NotFound("CommunicationChannel not found.");
        }

        $wahaPlatform = $channel->get('wahaPlatform');
        $sessionName = $channel->get('wahaSessionName');

        if (!$wahaPlatform || !$sessionName) {
            return $channel;
        }

        $wahaUrl = $wahaPlatform->get('url');
        $wahaApiKey = $wahaPlatform->get('apiKey');

        try {
            $sessionInfo = $this->wahaApiClient->getSession($wahaUrl, $wahaApiKey, $sessionName);
            $wahaStatus = $sessionInfo['status'] ?? 'UNKNOWN';

            $currentStatus = $channel->get('status');
            $newStatus = $currentStatus;

            switch ($wahaStatus) {
                case 'WORKING':
                    if ($currentStatus === 'PENDING_QR' || $currentStatus === 'CONNECTING') {
                        // Session just connected, complete setup
                        if (!$channel->get('wahaAppId')) {
                            $channel = $this->completeSetup($channelId);
                        } else {
                            $newStatus = 'ACTIVE';
                        }
                    } elseif ($currentStatus !== 'ACTIVE') {
                        $newStatus = 'ACTIVE';
                    }
                    if (isset($sessionInfo['me'])) {
                        $channel->set('whatsappId', $sessionInfo['me']['id'] ?? null);
                        $channel->set('whatsappName', $sessionInfo['me']['pushName'] ?? null);
                    }
                    break;

                case 'SCAN_QR_CODE':
                    if ($currentStatus !== 'PENDING_QR') {
                        $newStatus = 'PENDING_QR';
                    }
                    break;

                case 'STARTING':
                    if ($currentStatus !== 'CONNECTING') {
                        $newStatus = 'CONNECTING';
                    }
                    break;

                case 'STOPPED':
                case 'FAILED':
                    if ($currentStatus === 'ACTIVE') {
                        $newStatus = 'DISCONNECTED';
                    }
                    break;
            }

            if ($newStatus !== $currentStatus) {
                $channel->set('status', $newStatus);
                $this->entityManager->saveEntity($channel);
            }

        } catch (\Exception $e) {
            $this->log->warning("Failed to check channel status: " . $e->getMessage());
        }

        return $channel;
    }

    /**
     * Create a Chatwoot API channel inbox.
     *
     * @param string $chatwootUrl
     * @param string $apiKey
     * @param int $accountId
     * @param string $inboxName
     * @param string $webhookUrl The WAHA webhook URL for Chatwoot to send events to
     * @return array
     * @throws Error
     */
    private function createChatwootInbox(
        string $chatwootUrl,
        string $apiKey,
        int $accountId,
        string $inboxName,
        string $webhookUrl
    ): array {
        $url = rtrim($chatwootUrl, '/') . "/api/v1/accounts/{$accountId}/inboxes";

        $payload = json_encode([
            'name' => $inboxName,
            'lock_to_single_conversation' => true,
            'channel' => [
                'type' => 'api',
                'webhook_url' => $webhookUrl,
            ],
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'api_access_token: ' . $apiKey,
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            $error = json_decode($result, true);
            throw new Error("Failed to create Chatwoot inbox: " . ($error['message'] ?? $result));
        }

        return json_decode($result, true);
    }
}

