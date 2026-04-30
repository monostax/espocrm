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

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Exceptions\BadRequest;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Crypt;
use Espo\Core\Acl;
use Espo\Modules\Chatwoot\Services\WahaApiClient;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\FeatureCredential\Tools\Credential\CredentialResolver;
use Espo\Modules\FeatureMetaInstagram\Services\InstagramGraphApiClient;
use Espo\Tools\OAuth\TokensProvider;
use stdClass;

/**
 * Service for ChatwootInboxIntegration entity.
 * Handles the unified flow of creating WhatsApp connections with Chatwoot integration.
 */
class ChatwootInboxIntegration
{
    public const ENTITY_TYPE = 'ChatwootInboxIntegration';

    public function __construct(
        private EntityManager $entityManager,
        private WahaApiClient $wahaApiClient,
        private ChatwootApiClient $chatwootApiClient,
        private CredentialResolver $credentialResolver,
        private TokensProvider $tokensProvider,
        private InstagramGraphApiClient $instagramApiClient,
        private Crypt $crypt,
        private Log $log,
        private Acl $acl,
        private Config $config
    ) {}

    /**
     * Activate a communication channel.
     * Routes to the appropriate activation flow based on channel type.
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
            throw new Forbidden("No edit access to ChatwootInboxIntegration.");
        }

        $channel = $this->entityManager->getEntityById(self::ENTITY_TYPE, $channelId);

        if (!$channel) {
            throw new NotFound("ChatwootInboxIntegration with ID '{$channelId}' not found.");
        }

        $status = $channel->get('status');
        if (!in_array($status, ['DRAFT', 'FAILED', 'DISCONNECTED'])) {
            throw new BadRequest("Channel can only be activated from DRAFT, FAILED, or DISCONNECTED status.");
        }

        // Update status to CREATING
        $channel->set('status', 'CREATING');
        $channel->set('errorMessage', null);
        $this->entityManager->saveEntity($channel);

        $channelType = $channel->get('channelType');

        if ($channelType === 'whatsappCloudApi') {
            return $this->activateWhatsappCloudApi($channel);
        }

        if ($channelType === 'instagram') {
            return $this->activateInstagram($channel);
        }

        return $this->activateWhatsappQrcode($channel);
    }

    /**
     * Activate a WhatsApp QR code channel via WAHA.
     * Creates the WAHA session, Chatwoot API inbox, and starts the connection process.
     *
     * @param Entity $channel
     * @return Entity
     * @throws Error
     */
    private function activateWhatsappQrcode(Entity $channel): Entity
    {
        $channelId = $channel->getId();

        try {
            $chatwootAccount = $channel->get('chatwootAccount');

            if (!$chatwootAccount) {
                throw new Error("Chatwoot Account not set.");
            }

            // Auto-select default WahaPlatform if not set
            $wahaPlatform = $channel->get('wahaPlatform');
            if (!$wahaPlatform) {
                $wahaPlatform = $this->entityManager
                    ->getRDBRepository('WahaPlatform')
                    ->where(['isDefault' => true])
                    ->findOne();

                if (!$wahaPlatform) {
                    throw new Error("No default WAHA Platform configured. Please contact administrator.");
                }

                $channel->set('wahaPlatformId', $wahaPlatform->getId());
            }

            $wahaUrl = $wahaPlatform->get('backendUrl');
            $wahaApiKey = $wahaPlatform->get('apiKey');
            $chatwootPlatform = $chatwootAccount->get('platform');

            if (!$chatwootPlatform) {
                throw new Error("Chatwoot Platform not found for account.");
            }

            $chatwootUrl = $chatwootPlatform->get('backendUrl');
            $chatwootAccountId = $chatwootAccount->get('chatwootAccountId');
            $chatwootAccountApiKey = $chatwootAccount->get('apiKey');

            // Generate session name and app ID (needed for webhook URL)
            $sessionName = 'channel_' . $channelId;
            $appId = 'app_' . bin2hex(random_bytes(16));

            // Build WAHA webhook URL for Chatwoot to call
            $wahaWebhookUrl = rtrim($wahaUrl, '/') . '/webhooks/chatwoot/' . urlencode($sessionName) . '/' . urlencode($appId);

            // Clean up any existing apps for this session to prevent duplicates/orphans
            try {
                // We attempt to list and delete apps even before creating the session object in memory locally,
                // because the session might already exist in WAHA server.
                // However, listApps requires the session to exist.
                // It is safer to do this cleanup AFTER ensuring the session exists.
                // But we can check if there's a stored wahaAppId on the entity and try to delete it at least.
                $oldAppId = $channel->get('wahaAppId');
                if ($oldAppId) {
                    try {
                        $this->wahaApiClient->deleteApp($wahaUrl, $wahaApiKey, $oldAppId);
                    } catch (\Exception $e) {
                         // Check if it's a 404, otherwise log warning
                         $this->log->warning("Failed to delete old WAHA app during activation: " . $e->getMessage());
                    }
                }
            } catch (\Exception $e) {
                // Non-critical cleanup
            }

            // Create Chatwoot Inbox (API channel) with WAHA webhook URL
            if (!$chatwootAccountApiKey) {
                throw new Error("ChatwootAccount is missing API key. Please generate a User Access Token in Chatwoot (Settings > Account Settings > API Access Tokens) and add it to the ChatwootAccount.");
            }
            
            $inboxName = 'WhatsApp - ' . $channel->get('name');
            $inboxResult = $this->createChatwootInbox(
                $chatwootUrl,
                $chatwootAccountApiKey,
                $chatwootAccountId,
                $inboxName,
                $wahaWebhookUrl
            );

            $channel->set('chatwootInboxId', $inboxResult['id']);
            $channel->set('chatwootInboxIdentifier', $inboxResult['inbox_identifier'] ?? null);
            $channel->set('chatwootInboxRecordId', $this->upsertLocalChatwootInbox($channel, $inboxResult));

            // Ensure clean internal slate for the session
            try {
                // Check if session exists first
                $existingSession = null;
                try {
                    $existingSession = $this->wahaApiClient->getSession($wahaUrl, $wahaApiKey, $sessionName);
                } catch (\Exception $e) {
                    // Session not found or error, proceed
                }

                if ($existingSession) {
                    $this->log->info("ChatwootInboxIntegration: Session {$sessionName} already exists, deleting for clean activation.");
                    try {
                        $this->wahaApiClient->stopSession($wahaUrl, $wahaApiKey, $sessionName);
                        sleep(1); // Give it a moment to stop
                        $this->wahaApiClient->deleteSession($wahaUrl, $wahaApiKey, $sessionName);
                        sleep(2); // Wait for FS cleanup
                    } catch (\Exception $e) {
                        $this->log->warning("ChatwootInboxIntegration: Failed to delete existing session {$sessionName}: " . $e->getMessage());
                    }
                }
            } catch (\Exception $e) {
                // Ignore pre-check errors
            }

            // Create WAHA Session
            try {
                $this->wahaApiClient->createSession($wahaUrl, $wahaApiKey, [
                    'name' => $sessionName,
                ]);
            } catch (\Exception $e) {
                // If it still says "already exists", we might have failed to delete it or it's stuck.
                // We will try to proceed, assuming it might be in a usable state or manual intervention is needed.
                $msg = $e->getMessage();
                if (strpos($msg, 'already exists') !== false) {
                     $this->log->warning("ChatwootInboxIntegration: Session {$sessionName} creation failed (already exists), attempting to reuse.");
                     // Try to stop/start to reset it?
                     try {
                         $this->wahaApiClient->stopSession($wahaUrl, $wahaApiKey, $sessionName);
                         sleep(1);
                         $this->wahaApiClient->startSession($wahaUrl, $wahaApiKey, $sessionName);
                     } catch (\Exception $ex) {
                         // Ignore
                     }
                } else {
                    throw $e;
                }
            }

            $channel->set('wahaSessionName', $sessionName);
            $channel->set('wahaAppId', $appId);

            // Generate webhook secret and register label webhook
            $webhookSecret = bin2hex(random_bytes(32));
            $channel->set('wahaWebhookSecret', $webhookSecret);

            $ignoreConfig = [
                'status' => (bool) $channel->get('wahaIgnoreStatus'),
                'groups' => (bool) $channel->get('wahaIgnoreGroups'),
                'channels' => (bool) $channel->get('wahaIgnoreChannels'),
                'broadcast' => (bool) $channel->get('wahaIgnoreBroadcast'),
            ];

            $crmBackendUrl = getenv('CRM_BACKEND_URL') ?: $this->config->get('siteUrl');
            if ($crmBackendUrl) {
                $labelWebhookUrl = rtrim($crmBackendUrl, '/') . '/api/v1/WahaLabelWebhook/' . $channelId;

                $this->wahaApiClient->updateSession($wahaUrl, $wahaApiKey, $sessionName, [
                    'config' => [
                        'ignore' => $ignoreConfig,
                        'webhooks' => [[
                            'url' => $labelWebhookUrl,
                            'events' => ['label.chat.added', 'label.chat.deleted'],
                            'hmac' => ['key' => $webhookSecret],
                        ]],
                    ],
                ]);

                $this->log->info("ChatwootInboxIntegration: Registered label webhook at {$labelWebhookUrl}");
            } else {
                $this->wahaApiClient->updateSession($wahaUrl, $wahaApiKey, $sessionName, [
                    'config' => [
                        'ignore' => $ignoreConfig,
                    ],
                ]);

                $this->log->warning("ChatwootInboxIntegration: CRM_BACKEND_URL and siteUrl not configured, skipping label webhook registration");
            }

            // Cleanup any existing Chatwoot apps for this session in WAHA
            // This prevents "App not found" errors and performance issues with multiple apps
            try {
                $existingApps = $this->wahaApiClient->listApps($wahaUrl, $wahaApiKey, $sessionName);
                foreach ($existingApps as $app) {
                    // Delete all apps associated with this session to ensure a clean state
                    if (isset($app['id'])) {
                         $this->wahaApiClient->deleteApp($wahaUrl, $wahaApiKey, $app['id']);
                         $this->log->info("ChatwootInboxIntegration: Removed stale app {$app['id']} from session {$sessionName}");
                    }
                }
            } catch (\Exception $e) {
                // Ignore errors here as the session might be new or listApps failed
                $this->log->warning("ChatwootInboxIntegration: Failed to cleanup stale apps: " . $e->getMessage());
            }

            // Create WAHA Chatwoot App
            $appConfig = [
                'linkPreview' => 'OFF',
                'editMessage' => 'ON',
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

            // Start WAHA Session
            $this->wahaApiClient->startSession($wahaUrl, $wahaApiKey, $sessionName);

            // Update status to PENDING_QR
            $channel->set('status', 'PENDING_QR');
            $this->entityManager->saveEntity($channel);

            return $channel;

        } catch (\Exception $e) {
            $this->log->error("ChatwootInboxIntegration activation failed (QR code): " . $e->getMessage());
            $channel->set('status', 'FAILED');
            $channel->set('errorMessage', $e->getMessage());
            $this->entityManager->saveEntity($channel);
            throw new Error("Activation failed: " . $e->getMessage());
        }
    }

    /**
     * Activate a WhatsApp Cloud API channel.
     * Resolves credentials from FeatureCredential, creates a native Chatwoot WhatsApp
     * inbox (provider: whatsapp_cloud), and sets the channel to ACTIVE immediately.
     * No WAHA session or QR code is needed.
     *
     * @param Entity $channel
     * @return Entity
     * @throws Error
     */
    private function activateWhatsappCloudApi(Entity $channel): Entity
    {
        $channelId = $channel->getId();

        try {
            $chatwootAccount = $channel->get('chatwootAccount');

            if (!$chatwootAccount) {
                throw new Error("Chatwoot Account not set.");
            }

            // Resolve access token and business account ID.
            // Prefer the new OAuthAccount-based flow; fall back to credential for backward compat.
            $oAuthAccountId = $channel->get('oAuthAccountId');
            $businessAccountId = $channel->get('businessAccountId');
            $accessToken = null;

            if ($oAuthAccountId) {
                // New flow: get tokens directly from OAuthAccount via TokensProvider.
                $tokens = $this->tokensProvider->get($oAuthAccountId);
                $accessToken = $tokens->getAccessToken();
            } else {
                // Legacy fallback: resolve from credential.
                $credentialId = $channel->get('credentialId');

                if (!$credentialId) {
                    throw new Error("Meta Account (OAuth) not set. Please select a Meta Account and Business Account for this channel type.");
                }

                $resolvedConfig = $this->credentialResolver->resolve($credentialId);
                $accessToken = $resolvedConfig->accessToken ?? null;
                $businessAccountId = $resolvedConfig->businessAccountId ?? null;
            }

            // phoneNumberId lives exclusively on the integration entity.
            $phoneNumberId = $channel->get('phoneNumberId');

            if (!$accessToken) {
                throw new Error("Unable to obtain access token. Ensure the Meta Account is connected and has a valid token.");
            }
            if (!$phoneNumberId) {
                throw new Error("Phone Number ID is not set. Please select a phone number for this integration.");
            }
            if (!$businessAccountId) {
                throw new Error("Business Account ID is not set. Please select a WhatsApp Business Account.");
            }

            // Get Chatwoot connection details
            $chatwootPlatform = $chatwootAccount->get('platform');

            if (!$chatwootPlatform) {
                throw new Error("Chatwoot Platform not found for account.");
            }

            $chatwootUrl = $chatwootPlatform->get('backendUrl');
            $chatwootAccountId = $chatwootAccount->get('chatwootAccountId');
            $chatwootAccountApiKey = $chatwootAccount->get('apiKey');

            if (!$chatwootAccountApiKey) {
                throw new Error("ChatwootAccount is missing API key. Please generate a User Access Token in Chatwoot (Settings > Account Settings > API Access Tokens) and add it to the ChatwootAccount.");
            }

            $phoneNumber = $channel->get('phoneNumber');
            if (!$phoneNumber) {
                throw new Error("Phone number is required for WhatsApp Cloud API integration. Please set the phone number field.");
            }

            // Normalize phone number to E.164 format for Chatwoot.
            // Meta returns display format like "+55 11 5039-2320" but Chatwoot
            // matches incoming webhooks using E.164 ("+551150392320").
            $normalizedPhoneNumber = '+' . preg_replace('/[^0-9]/', '', $phoneNumber);

            // Create native Chatwoot WhatsApp Cloud inbox
            $inboxName = 'WhatsApp - ' . $channel->get('name');
            $inboxResult = $this->createChatwootWhatsappCloudInbox(
                $chatwootUrl,
                $chatwootAccountApiKey,
                $chatwootAccountId,
                $inboxName,
                $normalizedPhoneNumber,
                $accessToken,
                $phoneNumberId,
                $businessAccountId
            );

            $channel->set('chatwootInboxId', $inboxResult['id']);
            $channel->set('chatwootInboxIdentifier', $inboxResult['inbox_identifier'] ?? null);
            $channel->set('chatwootInboxRecordId', $this->upsertLocalChatwootInbox($channel, $inboxResult));

            // Set channel to ACTIVE immediately (no QR code step needed)
            $channel->set('status', 'ACTIVE');
            $channel->set('connectedAt', date('Y-m-d H:i:s'));
            $channel->set('errorMessage', null);
            $this->entityManager->saveEntity($channel);

            $this->log->info("ChatwootInboxIntegration: WhatsApp Cloud API channel {$channelId} activated successfully.");

            return $channel;

        } catch (\Exception $e) {
            $this->log->error("ChatwootInboxIntegration activation failed (Cloud API): " . $e->getMessage());
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
            throw new NotFound("ChatwootInboxIntegration not found.");
        }

        $wahaPlatform = $channel->get('wahaPlatform');

        $wahaUrl = $wahaPlatform->get('backendUrl');
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
     * For QR code channels: stops the WAHA session but keeps the configuration.
     * For Cloud API channels: marks the channel as disconnected (Chatwoot inbox remains).
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
            throw new Forbidden("No edit access to ChatwootInboxIntegration.");
        }

        $channel = $this->entityManager->getEntityById(self::ENTITY_TYPE, $channelId);

        if (!$channel) {
            throw new NotFound("ChatwootInboxIntegration not found.");
        }

        $channelType = $channel->get('channelType');

        // Only stop WAHA session for QR code channels
        if ($channelType === 'whatsappQrcode') {
            $wahaPlatform = $channel->get('wahaPlatform');
            $sessionName = $channel->get('wahaSessionName');

            if ($wahaPlatform && $sessionName) {
                try {
                    $wahaUrl = $wahaPlatform->get('backendUrl');
                    $wahaApiKey = $wahaPlatform->get('apiKey');

                    // Delete the associated App first
                    $wahaAppId = $channel->get('wahaAppId');
                    if ($wahaAppId) {
                        try {
                            $this->wahaApiClient->deleteApp($wahaUrl, $wahaApiKey, $wahaAppId);
                        } catch (\Exception $e) {
                            $this->log->warning("Failed to delete WAHA app: " . $e->getMessage());
                        }
                    }
                    
                    $this->wahaApiClient->stopSession($wahaUrl, $wahaApiKey, $sessionName);
                } catch (\Exception $e) {
                    $this->log->warning("Failed to stop WAHA session: " . $e->getMessage());
                }
            }
        }

        // For Cloud API channels, disconnecting simply marks the status.
        // The Chatwoot inbox remains intact and can be reconnected.
        //
        // Instagram: status-only disconnect, Chatwoot inbox is preserved.
        // We do NOT delete the inbox or unsubscribe webhooks; reconnect re-activates idempotently.

        $channel->set('status', 'DISCONNECTED');
        $this->entityManager->saveEntity($channel);

        return $channel;
    }

    /**
     * Reconnect a disconnected channel.
     * For QR code channels: restarts the WAHA session.
     * For Cloud API channels: verifies the Chatwoot inbox still exists and marks ACTIVE.
     *
     * @param string $channelId
     * @return Entity
     * @throws Error
     */
    public function reconnect(string $channelId): Entity
    {
        $channel = $this->entityManager->getEntityById(self::ENTITY_TYPE, $channelId);

        if (!$channel) {
            throw new NotFound("ChatwootInboxIntegration not found.");
        }

        if ($channel->get('status') !== 'DISCONNECTED') {
            throw new BadRequest("Channel can only be reconnected from DISCONNECTED status.");
        }

        $channelType = $channel->get('channelType');

        if ($channelType === 'whatsappCloudApi') {
            return $this->reconnectWhatsappCloudApi($channel);
        }

        if ($channelType === 'instagram') {
            return $this->reconnectInstagram($channel);
        }

        return $this->reconnectWhatsappQrcode($channel);
    }

    /**
     * Reconnect a WhatsApp QR code channel by restarting the WAHA session.
     *
     * @param Entity $channel
     * @return Entity
     * @throws Error
     */
    private function reconnectWhatsappQrcode(Entity $channel): Entity
    {
        $channelId = $channel->getId();
        $wahaPlatform = $channel->get('wahaPlatform');
        $sessionName = $channel->get('wahaSessionName');

        if (!$wahaPlatform || !$sessionName) {
            // No existing session, need to activate from scratch
            return $this->activate($channelId);
        }

        $wahaUrl = $wahaPlatform->get('backendUrl');
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
            $this->log->error("Reconnect failed (QR code): " . $e->getMessage());
            $channel->set('status', 'FAILED');
            $channel->set('errorMessage', $e->getMessage());
            $this->entityManager->saveEntity($channel);
            throw new Error("Reconnect failed: " . $e->getMessage());
        }
    }

    /**
     * Reconnect a WhatsApp Cloud API channel.
     * If the Chatwoot inbox still exists, marks the channel as ACTIVE.
     * Otherwise, re-activates from scratch.
     *
     * @param Entity $channel
     * @return Entity
     * @throws Error
     */
    private function reconnectWhatsappCloudApi(Entity $channel): Entity
    {
        $channelId = $channel->getId();
        $chatwootInboxId = $channel->get('chatwootInboxId');

        if (!$chatwootInboxId) {
            // No existing inbox, need to activate from scratch
            return $this->activate($channelId);
        }

        try {
            // Verify the Chatwoot inbox still exists by listing inboxes
            $chatwootAccount = $channel->get('chatwootAccount');

            if (!$chatwootAccount) {
                throw new Error("Chatwoot Account not set.");
            }

            $chatwootPlatform = $chatwootAccount->get('platform');

            if (!$chatwootPlatform) {
                throw new Error("Chatwoot Platform not found for account.");
            }

            $chatwootUrl = $chatwootPlatform->get('backendUrl');
            $chatwootAccountApiKey = $chatwootAccount->get('apiKey');
            $chatwootAccountId = $chatwootAccount->get('chatwootAccountId');

            if ($chatwootAccountApiKey && $chatwootAccountId) {
                $inboxes = $this->chatwootApiClient->listInboxes(
                    $chatwootUrl,
                    $chatwootAccountApiKey,
                    (int) $chatwootAccountId
                );

                $inboxExists = false;
                $inboxList = $inboxes['payload'] ?? $inboxes;
                foreach ($inboxList as $inbox) {
                    if (($inbox['id'] ?? null) == $chatwootInboxId) {
                        $inboxExists = true;
                        break;
                    }
                }

                if (!$inboxExists) {
                    $this->log->info("ChatwootInboxIntegration: Chatwoot inbox {$chatwootInboxId} no longer exists, re-activating.");
                    $channel->set('chatwootInboxId', null);
                    $channel->set('chatwootInboxIdentifier', null);
                    $this->entityManager->saveEntity($channel);
                    return $this->activate($channelId);
                }
            }

            // Inbox exists, mark as ACTIVE
            $channel->set('status', 'ACTIVE');
            $channel->set('errorMessage', null);
            $this->entityManager->saveEntity($channel);

            $this->log->info("ChatwootInboxIntegration: WhatsApp Cloud API channel {$channelId} reconnected successfully.");

            return $channel;

        } catch (\Exception $e) {
            $this->log->error("Reconnect failed (Cloud API): " . $e->getMessage());
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
            throw new NotFound("ChatwootInboxIntegration not found.");
        }

        $wahaPlatform = $channel->get('wahaPlatform');
        $sessionName = $channel->get('wahaSessionName');

        if (!$wahaPlatform || !$sessionName) {
            throw new Error("Channel not activated yet.");
        }

        $wahaUrl = $wahaPlatform->get('backendUrl');
        $wahaApiKey = $wahaPlatform->get('apiKey');

        $this->recoverSessionForQrCode($channel, $wahaUrl, $wahaApiKey, $sessionName);

        try {
            $qrData = $this->wahaApiClient->getQrCode($wahaUrl, $wahaApiKey, $sessionName);
        } catch (\Exception $e) {
            $message = $e->getMessage();
            $isStatusMismatch = strpos($message, 'Session status is not as expected') !== false ||
                strpos($message, 'HTTP 422') !== false;

            if (!$isStatusMismatch) {
                throw $e;
            }

            $this->log->warning("ChatwootInboxIntegration: QR fetch failed due to session status mismatch for {$sessionName}, retrying after restart.");
            $this->recoverSessionForQrCode($channel, $wahaUrl, $wahaApiKey, $sessionName, true);
            $qrData = $this->wahaApiClient->getQrCode($wahaUrl, $wahaApiKey, $sessionName);
        }

        return (object) [
            'mimetype' => $qrData['mimetype'],
            'data' => $qrData['data'],
            'dataUrl' => 'data:' . $qrData['mimetype'] . ';base64,' . $qrData['data']
        ];
    }

    private function recoverSessionForQrCode(
        Entity $channel,
        string $wahaUrl,
        string $wahaApiKey,
        string $sessionName,
        bool $forceRestart = false
    ): void {
        try {
            $sessionInfo = $this->wahaApiClient->getSession($wahaUrl, $wahaApiKey, $sessionName);
        } catch (\Exception $e) {
            $this->log->warning("ChatwootInboxIntegration: Failed to read WAHA session {$sessionName} before QR fetch: " . $e->getMessage());
            return;
        }

        $status = $sessionInfo['status'] ?? 'UNKNOWN';

        if ($forceRestart || $status === 'FAILED' || $status === 'STOPPED') {
            $this->log->warning("ChatwootInboxIntegration: Restarting WAHA session {$sessionName} from status {$status}.");
            $this->wahaApiClient->restartSession($wahaUrl, $wahaApiKey, $sessionName);
            $sessionInfo = $this->waitForSessionStatus($wahaUrl, $wahaApiKey, $sessionName, ['SCAN_QR_CODE', 'WORKING']);
            $status = $sessionInfo['status'] ?? 'UNKNOWN';
        }

        if ($status === 'SCAN_QR_CODE' && $channel->get('status') !== 'PENDING_QR') {
            $channel->set('status', 'PENDING_QR');
            $channel->set('errorMessage', null);
            $this->entityManager->saveEntity($channel);
            return;
        }

        if ($status === 'WORKING' && $channel->get('status') !== 'ACTIVE') {
            $channel->set('status', 'ACTIVE');
            $channel->set('errorMessage', null);
            $this->entityManager->saveEntity($channel);
        }
    }

    /**
     * @param array<int, string> $expectedStatuses
     * @return array<string, mixed>
     */
    private function waitForSessionStatus(
        string $wahaUrl,
        string $wahaApiKey,
        string $sessionName,
        array $expectedStatuses,
        int $maxAttempts = 8,
        int $delaySeconds = 1
    ): array {
        $lastSessionInfo = ['status' => 'UNKNOWN'];

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            try {
                $lastSessionInfo = $this->wahaApiClient->getSession($wahaUrl, $wahaApiKey, $sessionName);
                $status = $lastSessionInfo['status'] ?? 'UNKNOWN';

                if (in_array($status, $expectedStatuses, true)) {
                    return $lastSessionInfo;
                }
            } catch (\Exception $e) {
                $this->log->warning("ChatwootInboxIntegration: Failed to poll WAHA session {$sessionName}: " . $e->getMessage());
            }

            sleep($delaySeconds);
        }

        return $lastSessionInfo;
    }

    public function findLinkedChatwootInboxRecordId(string $channelId): ?string
    {
        $inbox = $this->entityManager
            ->getRDBRepository('ChatwootInbox')
            ->where(['chatwootInboxIntegrationId' => $channelId])
            ->findOne();

        return $inbox ? $inbox->getId() : null;
    }

    public function removeIntegration(string $channelId): void
    {
        try {
            $inboxList = $this->entityManager
                ->getRDBRepository('ChatwootInbox')
                ->where(['chatwootInboxIntegrationId' => $channelId])
                ->find();

            foreach ($inboxList as $inbox) {
                $this->entityManager->removeEntity($inbox, ['cascadeParent' => true]);
            }
        } catch (\Exception $e) {
            $this->log->error("ChatwootInboxIntegration: Failed to cleanup linked inboxes for {$channelId}: " . $e->getMessage());
        }

        $channel = $this->entityManager->getEntityById(self::ENTITY_TYPE, $channelId);

        if (!$channel) {
            return;
        }

        try {
            $this->entityManager->removeEntity($channel, ['cascadeParent' => true]);
        } catch (\Exception $e) {
            $this->log->error("ChatwootInboxIntegration: Failed to rollback integration {$channelId}: " . $e->getMessage());
        }
    }

    /**
     * Check and update channel status.
     * For QR code channels: polls WAHA session status.
     * For Cloud API channels: verifies credential health via Meta Graph API.
     *
     * @param string $channelId
     * @return Entity
     */
    public function checkStatus(string $channelId): Entity
    {
        $channel = $this->entityManager->getEntityById(self::ENTITY_TYPE, $channelId);

        if (!$channel) {
            throw new NotFound("ChatwootInboxIntegration not found.");
        }

        $channelType = $channel->get('channelType');

        if ($channelType === 'whatsappCloudApi') {
            return $this->checkStatusWhatsappCloudApi($channel);
        }

        if ($channelType === 'instagram') {
            return $this->checkStatusInstagram($channel);
        }

        return $this->checkStatusWhatsappQrcode($channel);
    }

    /**
     * Check status for a WhatsApp QR code channel via WAHA session.
     *
     * @param Entity $channel
     * @return Entity
     */
    private function checkStatusWhatsappQrcode(Entity $channel): Entity
    {
        $channelId = $channel->getId();
        $wahaPlatform = $channel->get('wahaPlatform');
        $sessionName = $channel->get('wahaSessionName');

        if (!$wahaPlatform || !$sessionName) {
            return $channel;
        }

        $wahaUrl = $wahaPlatform->get('backendUrl');
        $wahaApiKey = $wahaPlatform->get('apiKey');

        try {
            $sessionInfo = $this->wahaApiClient->getSession($wahaUrl, $wahaApiKey, $sessionName);
            $wahaStatus = $sessionInfo['status'] ?? 'UNKNOWN';

            $currentStatus = $channel->get('status');
            $newStatus = $currentStatus;

            switch ($wahaStatus) {
                case 'WORKING':
                    if ($currentStatus === 'PENDING_QR' || $currentStatus === 'CONNECTING') {
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
            $this->log->warning("Failed to check channel status (QR code): " . $e->getMessage());
        }

        return $channel;
    }

    /**
     * Check status for a WhatsApp Cloud API channel.
     * Verifies the credential is still valid by checking the Meta Graph API.
     *
     * @param Entity $channel
     * @return Entity
     */
    private function checkStatusWhatsappCloudApi(Entity $channel): Entity
    {
        $phoneNumberId = $channel->get('phoneNumberId');

        if (!$phoneNumberId) {
            return $channel;
        }

        try {
            $accessToken = null;
            $apiVersion = 'v21.0';

            // Prefer the new OAuthAccount-based flow; fall back to credential for backward compat.
            $oAuthAccountId = $channel->get('oAuthAccountId');

            if ($oAuthAccountId) {
                $tokens = $this->tokensProvider->get($oAuthAccountId);
                $accessToken = $tokens->getAccessToken();
            } else {
                $credentialId = $channel->get('credentialId');

                if (!$credentialId) {
                    return $channel;
                }

                $resolvedConfig = $this->credentialResolver->resolve($credentialId);
                $accessToken = $resolvedConfig->accessToken ?? null;
                $apiVersion = $resolvedConfig->apiVersion ?? 'v21.0';
            }

            if (!$accessToken) {
                if ($channel->get('status') === 'ACTIVE') {
                    $channel->set('status', 'DISCONNECTED');
                    $channel->set('errorMessage', 'Unable to obtain access token from Meta Account.');
                    $this->entityManager->saveEntity($channel);
                }
                return $channel;
            }

            // Quick health check against Meta Graph API using phone number.
            $url = "https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}";
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $accessToken,
            ]);

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $currentStatus = $channel->get('status');

            if ($httpCode === 200) {
                if ($currentStatus !== 'ACTIVE') {
                    $channel->set('status', 'ACTIVE');
                    $channel->set('errorMessage', null);
                    $this->entityManager->saveEntity($channel);
                }
            } else {
                if ($currentStatus === 'ACTIVE') {
                    $errorData = json_decode($result, true);
                    $errorMsg = $errorData['error']['message'] ?? "Meta API returned HTTP {$httpCode}";
                    $channel->set('status', 'DISCONNECTED');
                    $channel->set('errorMessage', $errorMsg);
                    $this->entityManager->saveEntity($channel);
                }
            }

        } catch (\Exception $e) {
            $this->log->warning("Failed to check channel status (Cloud API): " . $e->getMessage());
        }

        return $channel;
    }

    /**
     * Create a Chatwoot API channel inbox (used for whatsappQrcode via WAHA).
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

    private function upsertLocalChatwootInbox(Entity $channel, array $chatwootInbox): string
    {
        $remoteInboxId = $chatwootInbox['id'] ?? null;

        if (!$remoteInboxId) {
            throw new Error("Chatwoot inbox response did not include an inbox ID.");
        }

        $chatwootAccount = $channel->get('chatwootAccount');

        if (!$chatwootAccount) {
            throw new Error("Chatwoot Account not set.");
        }

        $chatwootAccountId = $chatwootAccount->getId();

        $inbox = $this->entityManager
            ->getRDBRepository('ChatwootInbox')
            ->where([
                'chatwootInboxId' => (int) $remoteInboxId,
                'chatwootAccountId' => $chatwootAccountId,
            ])
            ->findOne();

        if (!$inbox) {
            $inbox = $this->entityManager->getNewEntity('ChatwootInbox');
        }

        $inbox->set('name', $chatwootInbox['name'] ?? ('Inbox #' . $remoteInboxId));
        $inbox->set('chatwootInboxId', (int) $remoteInboxId);
        $inbox->set('chatwootAccountId', $chatwootAccountId);
        $inbox->set('channelType', $chatwootInbox['channel_type'] ?? null);
        $inbox->set('phoneNumber', $chatwootInbox['phone_number'] ?? ($channel->get('phoneNumber') ?? null));
        $inbox->set('provider', $chatwootInbox['provider'] ?? null);
        $inbox->set('medium', $chatwootInbox['medium'] ?? null);
        $inbox->set('greetingEnabled', $chatwootInbox['greeting_enabled'] ?? false);
        $inbox->set('greetingMessage', $chatwootInbox['greeting_message'] ?? null);
        $inbox->set('avatarUrl', $chatwootInbox['avatar_url'] ?? null);
        $inbox->set('inboxIdentifier', $chatwootInbox['inbox_identifier'] ?? null);
        $inbox->set('lastSyncedAt', date('Y-m-d H:i:s'));
        $inbox->set('chatwootInboxIntegrationId', $channel->getId());

        $teamIds = $chatwootAccount->getLinkMultipleIdList('teams');
        if (!empty($teamIds)) {
            $inbox->set('teamsIds', $teamIds);
        }

        $this->entityManager->saveEntity($inbox, ['silent' => true]);

        return $inbox->getId();
    }

    /**
     * Create a native Chatwoot WhatsApp Cloud API inbox.
     * Uses Chatwoot's Channel::Whatsapp with provider "whatsapp_cloud".
     * Chatwoot will automatically set up Meta webhooks on creation.
     *
     * @param string $chatwootUrl
     * @param string $apiKey Chatwoot account API key (User Access Token)
     * @param int $accountId Chatwoot account ID
     * @param string $inboxName Display name for the inbox
     * @param string $phoneNumber WhatsApp phone number (e.g., "+5511999999999")
     * @param string $accessToken Meta Graph API access token
     * @param string $phoneNumberId WhatsApp Phone Number ID from Meta
     * @param string $businessAccountId WhatsApp Business Account ID from Meta
     * @return array
     * @throws Error
     */
    private function createChatwootWhatsappCloudInbox(
        string $chatwootUrl,
        string $apiKey,
        int $accountId,
        string $inboxName,
        string $phoneNumber,
        string $accessToken,
        string $phoneNumberId,
        string $businessAccountId
    ): array {
        $url = rtrim($chatwootUrl, '/') . "/api/v1/accounts/{$accountId}/inboxes";

        $payload = json_encode([
            'name' => $inboxName,
            'lock_to_single_conversation' => true,
            'channel' => [
                'type' => 'whatsapp',
                'phone_number' => $phoneNumber,
                'provider' => 'whatsapp_cloud',
                'provider_config' => [
                    'api_key' => $accessToken,
                    'phone_number_id' => $phoneNumberId,
                    'business_account_id' => $businessAccountId,
                ],
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
            $errorMessage = $error['message'] ?? $error['error'] ?? $result;
            throw new Error("Failed to create Chatwoot WhatsApp Cloud inbox: " . $errorMessage);
        }

        $this->log->info("ChatwootInboxIntegration: Created Chatwoot WhatsApp Cloud inbox '{$inboxName}' for account {$accountId}");

        return json_decode($result, true);
    }

    /**
     * Activate an Instagram channel.
     *
     * Orchestrates:
     *   1. Resolve short-lived access token from OAuthAccount via TokensProvider.
     *   2. Exchange short-lived → long-lived token via Instagram Graph API.
     *   3. Resolve Instagram Business Account via /me (or use pre-selected instagramBusinessAccountId).
     *   4. Create the Chatwoot Instagram inbox (flat channel attributes).
     *   5. Persist instagramId/instagramUsername/tokenExpiresAt on the integration.
     *   6. Upsert the local ChatwootInbox mirror (inbox_identifier may be null).
     *
     * @param Entity $channel
     * @return Entity
     * @throws Error
     */
    private function activateInstagram(Entity $channel): Entity
    {
        $channelId = $channel->getId();

        try {
            $chatwootAccount = $channel->get('chatwootAccount');

            if (!$chatwootAccount) {
                throw new Error("Chatwoot Account not set.");
            }

            $oAuthAccountId = $channel->get('oAuthAccountId');

            if (!$oAuthAccountId) {
                throw new Error("Meta Account (OAuth) not set. Please select an Instagram Meta Account.");
            }

            $oAuthAccount = $this->entityManager->getEntityById('OAuthAccount', $oAuthAccountId);

            if (!$oAuthAccount) {
                throw new Error("OAuthAccount not found.");
            }

            // Step 1: Resolve current access token.
            $tokens = $this->tokensProvider->get($oAuthAccountId);
            $currentAccessToken = $tokens->getAccessToken();

            if (!$currentAccessToken) {
                throw new Error("Unable to obtain access token from the Meta (Instagram) OAuth Account.");
            }

            // Step 2: Exchange short-lived → long-lived token if not already exchanged.
            // Instagram uses a non-standard grant type at a different domain, so we
            // cannot rely on EspoCRM's generic OAuth refresh_token flow.
            // The exchange is REQUIRED: Chatwoot's Channel::Instagram#access_token getter
            // returns nil when expires_at is blank (via Instagram::RefreshOauthTokenService),
            // which makes `validates :access_token, presence: true` fail.
            $longLivedToken = $currentAccessToken;
            $expiresAt = $oAuthAccount->get('expiresAt');

            // "Already exchanged" is true when either:
            //   (a) the explicit marker is set (post-fix flow), OR
            //   (b) expiresAt is more than 2 days in the future (fallback for
            //       OAuthAccounts created before the marker column existed —
            //       a short-lived IG token is always ~1 hour, so anything
            //       >2 days ahead MUST be long-lived already).
            // Re-exchanging a long-lived token hits
            //   https://graph.instagram.com/access_token?grant_type=ig_exchange_token
            // with a token that IG expects to be short-lived and fails with
            // OAuthException code 190, so avoiding that call is important.
            $alreadyExchanged = (bool) $oAuthAccount->get('metaIgLongLivedExchangedAt');

            if (!$alreadyExchanged && $expiresAt) {
                $expiresTs = strtotime((string) $expiresAt);

                if ($expiresTs !== false && $expiresTs > time() + 2 * 24 * 60 * 60) {
                    $alreadyExchanged = true;
                }
            }

            if (!$alreadyExchanged) {
                $providerId = $oAuthAccount->get('providerId');
                $provider = $providerId
                    ? $this->entityManager->getEntityById('OAuthProvider', $providerId)
                    : null;

                $encryptedSecret = $provider ? ($provider->get('clientSecret') ?? '') : '';

                if (!$encryptedSecret) {
                    throw new Error("Meta (Instagram) OAuth Provider is missing a client secret.");
                }

                // OAuthProvider.clientSecret is stored encrypted (password field); decrypt it.
                $clientSecret = $this->crypt->decrypt($encryptedSecret);

                $exchange = $this->instagramApiClient->exchangeForLongLivedToken(
                    $currentAccessToken,
                    $clientSecret
                );
                $longLivedToken = $exchange['access_token'] ?? null;
                $expiresIn = (int) ($exchange['expires_in'] ?? 0);

                if (!$longLivedToken) {
                    throw new Error("Instagram long-lived token exchange did not return an access_token.");
                }

                $newExpiresAt = $expiresIn > 0
                    ? gmdate('Y-m-d H:i:s', time() + $expiresIn)
                    : gmdate('Y-m-d H:i:s', time() + 60 * 24 * 60 * 60); // fallback: 60 days

                // OAuthAccount.accessToken is a `password`-type field. It is NOT
                // auto-encrypted by the ORM on save (the standard EspoCRM flow in
                // Tools\OAuth\TokenSetter explicitly encrypts before setting);
                // TokensProvider assumes the stored value is ciphertext and runs
                // $crypt->decrypt() on it. Writing plaintext here would corrupt
                // the token pipeline with "OpenSSL decrypt failure" on every
                // subsequent read. Mirror TokenSetter::set() and encrypt here.
                $oAuthAccount->set('accessToken', $this->crypt->encrypt($longLivedToken));
                $oAuthAccount->set('expiresAt', $newExpiresAt);
                $oAuthAccount->set('metaIgLongLivedExchangedAt', gmdate('Y-m-d H:i:s'));
                $this->entityManager->saveEntity($oAuthAccount);

                $expiresAt = $newExpiresAt;
            }

            // Safety net: Chatwoot REQUIRES a non-null expires_at (the Instagram channel's
            // access_token getter returns nil when expires_at is blank).
            if (!$expiresAt) {
                $expiresAt = gmdate('Y-m-d H:i:s', time() + 60 * 24 * 60 * 60);
            }

            // Step 3: Resolve Instagram Business Account via /me.
            $me = $this->instagramApiClient->getMe($longLivedToken);
            $instagramId = (string) ($me['user_id'] ?? '');
            $instagramUsername = $me['username'] ?? null;

            if (!$instagramId) {
                throw new Error("Unable to resolve Instagram account (user_id) from the OAuth token.");
            }

            // Step 3.5: Explicitly subscribe the Meta App to this IG account's webhook
            // events BEFORE creating the Chatwoot inbox. Chatwoot's
            // `Channel::Instagram#subscribe` (fired by `after_create_commit`) makes the
            // same call but silently `rescue StandardError` — so if Meta rejects
            // (missing `instagram_business_manage_messages` scope, account not
            // messaging-enabled, revoked token, etc.), the Chatwoot inbox would be
            // created in a broken state and the user would never receive messages.
            //
            // Doing this first means:
            //   - We surface Meta's real error message to the user.
            //   - We fail fast, before any Chatwoot-side resource is created.
            //   - The call is idempotent, so Chatwoot's redundant retry is safe.
            try {
                $this->instagramApiClient->subscribeApp($longLivedToken, $instagramId);
                $this->log->info(
                    "ChatwootInboxIntegration: Instagram webhook subscription confirmed for {$instagramId}."
                );
            } catch (\Exception $e) {
                throw new Error(
                    'Failed to subscribe this Instagram account to webhook events at Meta. ' .
                    'Make sure the Meta App webhook is configured (Configure Meta Webhook action on the ' .
                    "meta-instagram OAuthProvider) and the token has the required scopes. Original error: " .
                    $e->getMessage()
                );
            }

            // Get Chatwoot connection details.
            $chatwootPlatform = $chatwootAccount->get('platform');

            if (!$chatwootPlatform) {
                throw new Error("Chatwoot Platform not found for account.");
            }

            $chatwootUrl = $chatwootPlatform->get('backendUrl');
            $chatwootAccountId = $chatwootAccount->get('chatwootAccountId');
            $chatwootAccountApiKey = $chatwootAccount->get('apiKey');

            if (!$chatwootAccountApiKey) {
                throw new Error("ChatwootAccount is missing API key. Please generate a User Access Token in Chatwoot (Settings > Account Settings > API Access Tokens) and add it to the ChatwootAccount.");
            }

            // Step 4: Create the Chatwoot Instagram inbox.
            $inboxName = 'Instagram - ' . $channel->get('name');

            // Normalise expiresAt to ISO-8601 if present.
            $expiresAtIso = $this->normaliseExpiresAt($expiresAt);

            $inboxResult = $this->createChatwootInstagramInbox(
                $chatwootUrl,
                $chatwootAccountApiKey,
                (int) $chatwootAccountId,
                $inboxName,
                $longLivedToken,
                $instagramId,
                $expiresAtIso
            );

            // Step 5: Persist Instagram metadata on the integration entity.
            $channel->set('instagramId', $instagramId);
            $channel->set('instagramUsername', $instagramUsername);
            $channel->set('tokenExpiresAt', $expiresAt ?: null);

            // Step 6: Mirror into local ChatwootInbox (inbox_identifier tolerated as null).
            $channel->set('chatwootInboxId', $inboxResult['id']);
            $channel->set('chatwootInboxIdentifier', $inboxResult['inbox_identifier'] ?? null);
            $channel->set('chatwootInboxRecordId', $this->upsertLocalChatwootInbox($channel, $inboxResult));

            $channel->set('status', 'ACTIVE');
            $channel->set('connectedAt', date('Y-m-d H:i:s'));
            $channel->set('errorMessage', null);
            $this->entityManager->saveEntity($channel);

            $this->log->info("ChatwootInboxIntegration: Instagram channel {$channelId} activated successfully.");

            return $channel;

        } catch (\Exception $e) {
            $this->log->error("ChatwootInboxIntegration activation failed (Instagram): " . $e->getMessage());
            $channel->set('status', 'FAILED');
            $channel->set('errorMessage', $e->getMessage());
            $this->entityManager->saveEntity($channel);
            throw new Error("Activation failed: " . $e->getMessage());
        }
    }

    /**
     * Reconnect an Instagram channel.
     * If the Chatwoot inbox still exists, marks the channel ACTIVE.
     * Otherwise re-runs activation.
     *
     * Note: Instagram does NOT support standard OAuth2 refresh_token grant.
     * If the stored token has expired, the user must re-authorize in the CRM.
     *
     * @param Entity $channel
     * @return Entity
     * @throws Error
     */
    private function reconnectInstagram(Entity $channel): Entity
    {
        $channelId = $channel->getId();
        $chatwootInboxId = $channel->get('chatwootInboxId');

        if (!$chatwootInboxId) {
            return $this->activate($channelId);
        }

        try {
            $tokenExpiresAt = $channel->get('tokenExpiresAt');

            if ($tokenExpiresAt && strtotime($tokenExpiresAt) < time()) {
                throw new Error(
                    "Instagram long-lived token has expired. Please re-authorize the Meta (Instagram) OAuth Account."
                );
            }

            $chatwootAccount = $channel->get('chatwootAccount');

            if (!$chatwootAccount) {
                throw new Error("Chatwoot Account not set.");
            }

            $chatwootPlatform = $chatwootAccount->get('platform');

            if (!$chatwootPlatform) {
                throw new Error("Chatwoot Platform not found for account.");
            }

            $chatwootUrl = $chatwootPlatform->get('backendUrl');
            $chatwootAccountApiKey = $chatwootAccount->get('apiKey');
            $chatwootAccountId = $chatwootAccount->get('chatwootAccountId');

            if ($chatwootAccountApiKey && $chatwootAccountId) {
                $inboxes = $this->chatwootApiClient->listInboxes(
                    $chatwootUrl,
                    $chatwootAccountApiKey,
                    (int) $chatwootAccountId
                );

                $inboxExists = false;
                $inboxList = $inboxes['payload'] ?? $inboxes;
                foreach ($inboxList as $inbox) {
                    if (($inbox['id'] ?? null) == $chatwootInboxId) {
                        $inboxExists = true;
                        break;
                    }
                }

                if (!$inboxExists) {
                    $this->log->info("ChatwootInboxIntegration: Chatwoot inbox {$chatwootInboxId} no longer exists, re-activating Instagram channel.");
                    $channel->set('chatwootInboxId', null);
                    $channel->set('chatwootInboxIdentifier', null);
                    $this->entityManager->saveEntity($channel);
                    return $this->activate($channelId);
                }
            }

            $channel->set('status', 'ACTIVE');
            $channel->set('errorMessage', null);
            $this->entityManager->saveEntity($channel);

            $this->log->info("ChatwootInboxIntegration: Instagram channel {$channelId} reconnected successfully.");

            return $channel;

        } catch (\Exception $e) {
            $this->log->error("Reconnect failed (Instagram): " . $e->getMessage());
            $channel->set('status', 'FAILED');
            $channel->set('errorMessage', $e->getMessage());
            $this->entityManager->saveEntity($channel);
            throw new Error("Reconnect failed: " . $e->getMessage());
        }
    }

    /**
     * Health-check an Instagram channel against graph.instagram.com.
     * HTTP 200 ⇒ ACTIVE. Any other code ⇒ DISCONNECTED with errorMessage.
     */
    private function checkStatusInstagram(Entity $channel): Entity
    {
        $instagramId = $channel->get('instagramId');
        $oAuthAccountId = $channel->get('oAuthAccountId');

        if (!$instagramId || !$oAuthAccountId) {
            return $channel;
        }

        try {
            $tokens = $this->tokensProvider->get($oAuthAccountId);
            $accessToken = $tokens->getAccessToken();

            if (!$accessToken) {
                if ($channel->get('status') === 'ACTIVE') {
                    $channel->set('status', 'DISCONNECTED');
                    $channel->set('errorMessage', 'Unable to obtain access token from Meta (Instagram) OAuth Account.');
                    $this->entityManager->saveEntity($channel);
                }
                return $channel;
            }

            $httpCode = $this->instagramApiClient->healthCheck($accessToken, $instagramId);
            $currentStatus = $channel->get('status');

            if ($httpCode === 200) {
                if ($currentStatus !== 'ACTIVE') {
                    $channel->set('status', 'ACTIVE');
                    $channel->set('errorMessage', null);
                    $this->entityManager->saveEntity($channel);
                }
            } else {
                if ($currentStatus === 'ACTIVE') {
                    $channel->set('status', 'DISCONNECTED');
                    $channel->set('errorMessage', "Instagram Graph API returned HTTP {$httpCode}.");
                    $this->entityManager->saveEntity($channel);
                }
            }

        } catch (\Exception $e) {
            $this->log->warning("Failed to check channel status (Instagram): " . $e->getMessage());
        }

        return $channel;
    }

    /**
     * Create a native Chatwoot Instagram inbox.
     *
     * Uses flat channel attributes (NOT provider_config) since Channel::Instagram
     * has flat columns (access_token, instagram_id, expires_at) and no JSONB
     * provider_config hash.
     *
     * Note: the Chatwoot `_inbox.json.jbuilder` partial does NOT emit
     * `inbox_identifier` for Instagram (Channel::Instagram has no `identifier`
     * column). Callers must tolerate it being absent via the `?? null` pattern.
     *
     * @throws Error
     */
    private function createChatwootInstagramInbox(
        string $chatwootUrl,
        string $apiKey,
        int $accountId,
        string $inboxName,
        string $accessToken,
        string $instagramId,
        ?string $expiresAtIso
    ): array {
        $url = rtrim($chatwootUrl, '/') . "/api/v1/accounts/{$accountId}/inboxes";

        $channelPayload = [
            'type' => 'instagram',
            'instagram_id' => $instagramId,
            'access_token' => $accessToken,
        ];

        if ($expiresAtIso) {
            $channelPayload['expires_at'] = $expiresAtIso;
        }

        $payload = json_encode([
            'name' => $inboxName,
            'channel' => $channelPayload,
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
            $errorMessage = $error['message'] ?? $error['error'] ?? $result;
            throw new Error("Failed to create Chatwoot Instagram inbox: " . $errorMessage);
        }

        $this->log->info("ChatwootInboxIntegration: Created Chatwoot Instagram inbox '{$inboxName}' for account {$accountId}");

        return json_decode($result, true);
    }

    /**
     * Normalise a datetime-ish value to ISO-8601 (UTC) for Chatwoot's expires_at.
     */
    private function normaliseExpiresAt(mixed $expiresAt): ?string
    {
        if (!$expiresAt) {
            return null;
        }

        $ts = is_numeric($expiresAt) ? (int) $expiresAt : strtotime((string) $expiresAt);

        if (!$ts) {
            return null;
        }

        return gmdate('c', $ts);
    }
}
