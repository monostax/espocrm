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
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\MetaGraphApiClient;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\WhatsAppCoexistenceSyncService;
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
        private ChatwootInboxIdResolver $chatwootInboxIdResolver,
        private CredentialResolver $credentialResolver,
        private TokensProvider $tokensProvider,
        private InstagramGraphApiClient $instagramApiClient,
        private MetaGraphApiClient $metaGraphApiClient,
        private WhatsAppCoexistenceSyncService $whatsAppCoexistenceSyncService,
        private Crypt $crypt,
        private Log $log,
        private Acl $acl,
        private Config $config,
        private EmailChannelBridge $emailChannelBridge,
        private SyncEmailOAuthCredentials $syncEmailOAuthCredentials,
        private ChatwootIntegrationUserAccess $integrationUserAccess
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

        // DRAFT/FAILED is first-time activation (or retry). CONNECTING and
        // PENDING_QR are the "Repair" path: an operator self-repairs a channel
        // whose WAHA session/app got into a broken/stuck state (e.g. the session
        // logged out or the Chatwoot app was deleted) without delete + recreate.
        // Re-activation is idempotent w.r.t. the Chatwoot inbox (see
        // resolveChatwootInboxForQr), so existing conversations are preserved.
        // DISCONNECTED is intentionally excluded here: it is handled by reconnect()
        // (a lighter session restart that falls back to activate() if no session).
        $status = $channel->get('status');
        if (!in_array($status, ['DRAFT', 'FAILED', 'CONNECTING', 'PENDING_QR'])) {
            throw new BadRequest(
                "Channel can only be activated from DRAFT, FAILED, CONNECTING, or PENDING_QR status."
            );
        }

        // Update status to CREATING
        $channel->set('status', 'CREATING');
        $channel->set('errorMessage', null);
        $this->entityManager->saveEntity($channel);

        $channelType = $channel->get('channelType');

        if ($channelType === 'whatsappCloudApi') {
            return $this->activateWhatsappCloudApi($channel);
        }

        if ($channelType === 'whatsappCoexistence') {
            return $this->activateWhatsappCoexistence($channel);
        }

        if ($channelType === 'instagram') {
            return $this->activateInstagram($channel);
        }

        if ($channelType === 'email') {
            return $this->activateEmail($channel);
        }

        return $this->activateWhatsappQrcode($channel);
    }

    /**
     * Activate a Channel::Email inbox from a CRM InboundEmail or EmailAccount.
     * Chatwoot owns IMAP thereafter (CRM useImap=false) to avoid dual-fetch.
     */
    private function activateEmail(Entity $channel): Entity
    {
        $channelId = $channel->getId();

        try {
            if ($channel->get('inboundEmailId') && $channel->get('emailAccountId')) {
                throw new BadRequest('Select either an Inbound Email or Email Account, not both.');
            }

            if (!$channel->get('inboundEmailId') && !$channel->get('emailAccountId')) {
                throw new BadRequest(
                    'Select an Inbound Email (group) or Email Account (personal) before activating.'
                );
            }

            if (!$channel->get('chatwootAccountId')) {
                throw new BadRequest('Chatwoot Account is required.');
            }

            $this->emailChannelBridge->pushFromIntegration($channel);

            $channel = $this->entityManager->getEntityById(self::ENTITY_TYPE, $channelId);

            if (!$channel) {
                throw new Error("Channel {$channelId} disappeared during email activation.");
            }

            $this->log->info(
                "ChatwootInboxIntegration: Email channel {$channelId} activated successfully."
            );

            return $channel;
        } catch (\Throwable $e) {
            $channel = $this->entityManager->getEntityById(self::ENTITY_TYPE, $channelId);

            if ($channel) {
                $channel->set('status', 'FAILED');
                $channel->set('errorMessage', $e->getMessage());
                $this->entityManager->saveEntity($channel);
            }

            $this->log->error(
                "ChatwootInboxIntegration: Email activation failed for {$channelId}: " .
                $e->getMessage()
            );

            throw $e instanceof Error || $e instanceof BadRequest || $e instanceof Forbidden
                ? $e
                : new Error($e->getMessage(), 0, $e);
        }
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
            $chatwootAccount = $this->loadChatwootAccount($channel);

            if (!$chatwootAccount) {
                throw new Error("Chatwoot Account not set.");
            }

            // Auto-select default WahaPlatform if not set
            $wahaPlatform = $this->loadWahaPlatform($channel);
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
            $chatwootPlatform = $this->loadChatwootPlatform($chatwootAccount);

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
            
            // Idempotent: reuse the channel's existing Chatwoot inbox when it still
            // exists (only update its webhook_url to the regenerated WAHA app URL).
            // Re-activation (DRAFT|FAILED|DISCONNECTED -> activate) must NOT create a
            // duplicate inbox and orphan the historical conversations.
            $inboxName = 'WhatsApp - ' . $channel->get('name');
            $inboxResult = $this->resolveChatwootInboxForQr(
                $channel,
                $chatwootUrl,
                $chatwootAccountApiKey,
                (int) $chatwootAccountId,
                $inboxName,
                $wahaWebhookUrl
            );

            $channel->set('chatwootInboxId', $inboxResult['id']);
            $channel->set('chatwootInboxIdentifier', $inboxResult['inbox_identifier'] ?? null);
            $channel->set('chatwootInboxRecordId', $this->upsertLocalChatwootInbox($channel, $inboxResult));

            // WAHA authenticates with the account integration user's token. The
            // user must be a direct member under scoped global-admin access.
            $this->integrationUserAccess->ensureInboxAccess(
                $chatwootAccount,
                (int) $inboxResult['id']
            );

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
            $chatwootAccount = $this->loadChatwootAccount($channel);

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
            $chatwootPlatform = $this->loadChatwootPlatform($chatwootAccount);

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

        $wahaPlatform = $this->loadWahaPlatform($channel);

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
            $wahaPlatform = $this->loadWahaPlatform($channel);
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

        // Coexistence: tear down the WAHA send companion (if linked). The Cloud
        // inbox stays intact (Cloud API inbound/outbound keeps working), but we
        // stop the companion session and remove `linked_waha` so Chatwoot stops
        // routing free-form replies through a now-stopped transport.
        if ($channelType === 'whatsappCoexistence') {
            $wahaPlatform = $this->loadWahaPlatform($channel);
            $sessionName = $channel->get('wahaSessionName');

            if ($wahaPlatform && $sessionName) {
                try {
                    $this->syncCoexistenceWahaLink($channel, null);
                } catch (\Exception $e) {
                    $this->log->warning("Failed to remove linked_waha during coexistence disconnect: " . $e->getMessage());
                }

                try {
                    $wahaUrl = $wahaPlatform->get('backendUrl');
                    $wahaApiKey = $wahaPlatform->get('apiKey');
                    $this->wahaApiClient->stopSession($wahaUrl, $wahaApiKey, $sessionName);
                } catch (\Exception $e) {
                    $this->log->warning("Failed to stop WAHA companion session during coexistence disconnect: " . $e->getMessage());
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

        if ($channelType === 'whatsappCoexistence') {
            return $this->reconnectWhatsappCoexistence($channel);
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
        $wahaPlatform = $this->loadWahaPlatform($channel);
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
            $chatwootAccount = $this->loadChatwootAccount($channel);

            if (!$chatwootAccount) {
                throw new Error("Chatwoot Account not set.");
            }

            $chatwootPlatform = $this->loadChatwootPlatform($chatwootAccount);

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

        $wahaPlatform = $this->loadWahaPlatform($channel);
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

    // ---------------------------------------------------------------------
    // WhatsApp Coexistence — WAHA send companion (dual transport)
    // ---------------------------------------------------------------------
    //
    // A coexistence number lives on Meta Cloud API for INBOUND + template/
    // in-window OUTBOUND. The hard Meta limitation is that it CANNOT send
    // free-form (non-template) messages once the 24h customer-service window
    // is closed. To cover that gap we link a WAHA "send-only" session bound to
    // the SAME WhatsApp number (a linked device, paired via QR).
    //
    // Send-only means: we create + start a WAHA session and pair it, but we do
    // NOT create a WAHA Chatwoot "App". Apps are what forward INBOUND WAHA
    // events into a Chatwoot inbox; omitting the App keeps inbound flowing
    // exclusively through Cloud API (single conversation thread) while WAHA is
    // used purely as an outbound HTTP transport (POST /api/sendText), called
    // directly by Chatwoot's Messages::WahaSendCoexistenceMessageJob.
    //
    // Flow:
    //   1. linkWahaCompanion(): create/start the send-only session, persist
    //      wahaSessionName/wahaAppId/wahaWebhookSecret + wahaChatwootInboxId,
    //      set status=PENDING_WAHA_LINK, surface QR via getQrCode().
    //   2. Customer scans QR with the same number -> WAHA goes WORKING.
    //   3. checkStatus() detects WORKING, writes provider_config.linked_waha
    //      into the Cloud inbox (shape { inbox_id, session, base_url }) so
    //      Chatwoot's Channel::Whatsapp#waha_outbound_enabled? flips on, then
    //      returns status to ACTIVE.

    /**
     * Provision (or re-provision) the WAHA send-only companion for a
     * coexistence channel and put it into the QR-link state.
     *
     * @throws Error
     */
    public function linkWahaCompanion(string $channelId): Entity
    {
        $channel = $this->entityManager->getEntityById(self::ENTITY_TYPE, $channelId);

        if (!$channel) {
            throw new NotFound("ChatwootInboxIntegration not found.");
        }

        if ($channel->get('channelType') !== 'whatsappCoexistence') {
            throw new Error("WAHA companion can only be linked to a WhatsApp Coexistence channel.");
        }

        try {
            $this->provisionWahaSendOnlySession($channel);

            $channel->set('status', 'PENDING_WAHA_LINK');
            $channel->set('errorMessage', null);
            $this->entityManager->saveEntity($channel);

            $this->log->info("ChatwootInboxIntegration: WAHA send companion provisioned for coexistence channel {$channelId} (awaiting QR scan).");

            return $channel;
        } catch (\Exception $e) {
            $this->log->error("ChatwootInboxIntegration: Failed to link WAHA companion for {$channelId}: " . $e->getMessage());
            $channel->set('errorMessage', 'Failed to link WhatsApp companion: ' . $e->getMessage());
            $this->entityManager->saveEntity($channel);
            throw new Error("Failed to link WhatsApp companion: " . $e->getMessage());
        }
    }

    /**
     * Create + start a send-only WAHA session for the coexistence number.
     *
     * Reuses the same WAHA session lifecycle as activateWhatsappQrcode but
     * deliberately does NOT create a Chatwoot "App" (inbound stays on Cloud
     * API). Persists session identifiers on the channel; does not change
     * status (caller decides).
     *
     * @throws Error
     */
    private function provisionWahaSendOnlySession(Entity $channel): void
    {
        $channelId = $channel->getId();

        // Auto-select default WahaPlatform if not set (mirrors activateWhatsappQrcode).
        $wahaPlatform = $this->loadWahaPlatform($channel);
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

        // Distinct session name so a coexistence companion never collides with
        // a stand-alone QR channel that happens to share the channel id space.
        $sessionName = 'coexistence_' . $channelId;

        // Clean slate: delete any pre-existing session for this name.
        try {
            $existingSession = null;
            try {
                $existingSession = $this->wahaApiClient->getSession($wahaUrl, $wahaApiKey, $sessionName);
            } catch (\Exception $e) {
                // Not found — fine.
            }

            if ($existingSession) {
                $this->log->info("ChatwootInboxIntegration: Send companion session {$sessionName} exists, recreating for clean link.");
                try {
                    $this->wahaApiClient->stopSession($wahaUrl, $wahaApiKey, $sessionName);
                    sleep(1);
                    $this->wahaApiClient->deleteSession($wahaUrl, $wahaApiKey, $sessionName);
                    sleep(2);
                } catch (\Exception $e) {
                    $this->log->warning("ChatwootInboxIntegration: Failed to delete existing companion session {$sessionName}: " . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            // Ignore pre-check errors.
        }

        // Create the session.
        try {
            $this->wahaApiClient->createSession($wahaUrl, $wahaApiKey, [
                'name' => $sessionName,
            ]);
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            if (strpos($msg, 'already exists') !== false) {
                $this->log->warning("ChatwootInboxIntegration: Companion session {$sessionName} already exists, attempting to reuse.");
                try {
                    $this->wahaApiClient->stopSession($wahaUrl, $wahaApiKey, $sessionName);
                    sleep(1);
                    $this->wahaApiClient->startSession($wahaUrl, $wahaApiKey, $sessionName);
                } catch (\Exception $ex) {
                    // Ignore.
                }
            } else {
                throw $e;
            }
        }

        $channel->set('wahaSessionName', $sessionName);

        // We don't create a Chatwoot App, but we keep an appId/webhookSecret so
        // the label webhook (used by both transports' UX) can still be wired and
        // reconnect/cleanup logic has a stable handle.
        $appId = $channel->get('wahaAppId') ?: ('app_' . bin2hex(random_bytes(16)));
        $channel->set('wahaAppId', $appId);

        $webhookSecret = $channel->get('wahaWebhookSecret') ?: bin2hex(random_bytes(32));
        $channel->set('wahaWebhookSecret', $webhookSecret);

        // Record which Chatwoot inbox this companion sends on (the Cloud inbox).
        $channel->set('wahaChatwootInboxId', $this->getNumericChatwootInboxId($channel));

        // Configure ignore rules + label webhook (no Chatwoot inbound App).
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
        } else {
            $this->wahaApiClient->updateSession($wahaUrl, $wahaApiKey, $sessionName, [
                'config' => ['ignore' => $ignoreConfig],
            ]);
        }

        // Start the session so it advances to SCAN_QR_CODE for the QR step.
        $this->wahaApiClient->startSession($wahaUrl, $wahaApiKey, $sessionName);
    }

    /**
     * Resolve the numeric Chatwoot inbox id for REST calls.
     *
     * Delegates to {@see ChatwootInboxIdResolver}, which documents why the
     * integration's own `chatwootInboxId` attribute cannot be used directly.
     */
    private function getNumericChatwootInboxId(Entity $channel): ?int
    {
        return $this->chatwootInboxIdResolver->resolve($channel);
    }

    /**
     * Patch the coexistence Cloud inbox's provider_config to add (or remove)
     * the `linked_waha` block consumed by Chatwoot's
     * Channel::Whatsapp#waha_outbound_enabled?.
     *
     * Chatwoot replaces provider_config wholesale on update, so we GET the
     * current config first and merge.
     *
     * @param array<string, mixed>|null $link The linked_waha block, or null to unlink.
     * @throws Error
     */
    private function syncCoexistenceWahaLink(Entity $channel, ?array $link): void
    {
        $chatwootInboxId = $this->getNumericChatwootInboxId($channel);
        if (!$chatwootInboxId) {
            throw new Error("Cannot sync WAHA link: coexistence channel has no Chatwoot inbox.");
        }

        $chatwootAccount = $this->loadChatwootAccount($channel);
        if (!$chatwootAccount) {
            throw new Error("Chatwoot Account not set.");
        }

        $chatwootPlatform = $this->loadChatwootPlatform($chatwootAccount);
        if (!$chatwootPlatform) {
            throw new Error("Chatwoot Platform not found for account.");
        }

        $chatwootUrl = $chatwootPlatform->get('backendUrl');
        $chatwootAccountId = (int) $chatwootAccount->get('chatwootAccountId');
        $chatwootAccountApiKey = $chatwootAccount->get('apiKey');

        // Read current provider_config so we can merge rather than clobber.
        $inbox = $this->chatwootApiClient->getInbox(
            $chatwootUrl,
            $chatwootAccountApiKey,
            $chatwootAccountId,
            $chatwootInboxId
        );

        if ($inbox === null) {
            throw new Error("Coexistence Chatwoot inbox {$chatwootInboxId} not found.");
        }

        $providerConfig = $inbox['provider_config'] ?? [];
        if (!is_array($providerConfig)) {
            $providerConfig = [];
        }

        if ($link === null) {
            unset($providerConfig['linked_waha']);
        } else {
            $providerConfig['linked_waha'] = $link;
        }

        $this->chatwootApiClient->updateInbox(
            $chatwootUrl,
            $chatwootAccountApiKey,
            $chatwootAccountId,
            $chatwootInboxId,
            ['channel' => ['provider_config' => $providerConfig]]
        );
    }

    /**
     * Re-sync the current Meta access token from the channel's OAuthAccount into
     * the native Chatwoot WhatsApp Cloud inbox's `provider_config.api_key`.
     *
     * Why this exists
     * ---------------
     * At onboarding the integration copies a *snapshot* of the OAuthAccount
     * (system-user) token into Chatwoot's `provider_config.api_key`
     * (see createChatwootWhatsappCloudInbox). After that, nothing re-pushes the
     * token. When the system-user token is rotated/regenerated in Meta — or was
     * only transiently invalid at onboarding — the OAuthAccount keeps the fresh
     * token (so CRM health/delivery stays green) while Chatwoot keeps the stale
     * snapshot. Chatwoot's media download then 401s; after two failures
     * Channel::Whatsapp#authorization_error! trips the sticky
     * `reauthorization_required` flag (red badge, inbound media degraded) and it
     * never recovers on its own.
     *
     * This makes the integration the single source of truth for the token:
     * it patches the live OAuthAccount token into provider_config.api_key. The
     * Chatwoot inbox PATCH endpoint (InboxesController#reauthorize_and_update_channel)
     * calls `channel.reauthorized!` *before* applying the update, which clears
     * both the Redis authorization-error counter and the reauthorization flag —
     * so a single PATCH both refreshes the token and clears the stuck red badge.
     *
     * Mirrors syncCoexistenceWahaLink: Chatwoot replaces provider_config
     * wholesale on update, so we GET the current config first and merge.
     *
     * No-ops quietly (returns false) when prerequisites are missing or the token
     * is already in sync; throws only on hard API failures.
     *
     * @return bool True if a PATCH was issued (token re-synced), false otherwise.
     */
    private function patchInboxAccessToken(Entity $channel): bool
    {
        // Reloaded integrations carry the linked inbox's CRM id without hydrating
        // the relation. Use the shared resolver to obtain its numeric Chatwoot id.
        $chatwootInboxId = $this->getNumericChatwootInboxId($channel);
        if (!$chatwootInboxId) {
            return false;
        }

        $oAuthAccountId = $channel->get('oAuthAccountId');
        if (!$oAuthAccountId) {
            // Legacy credential-based channels manage their own token; nothing to sync.
            return false;
        }

        $chatwootAccount = $this->loadChatwootAccount($channel);
        if (!$chatwootAccount) {
            return false;
        }

        $chatwootPlatform = $this->loadChatwootPlatform($chatwootAccount);
        if (!$chatwootPlatform) {
            return false;
        }

        $chatwootUrl = $chatwootPlatform->get('backendUrl');
        $chatwootAccountId = (int) $chatwootAccount->get('chatwootAccountId');
        $chatwootAccountApiKey = $chatwootAccount->get('apiKey');

        if (!$chatwootUrl || !$chatwootAccountId || !$chatwootAccountApiKey) {
            return false;
        }

        try {
            $tokens = $this->tokensProvider->get($oAuthAccountId);
            $accessToken = $tokens->getAccessToken();
        } catch (\Exception $e) {
            $this->log->warning(
                "ChatwootInboxIntegration: patchInboxAccessToken could not resolve token for OAuthAccount " .
                "{$oAuthAccountId} (channel {$channel->getId()}): " . $e->getMessage()
            );
            return false;
        }

        if (!$accessToken) {
            return false;
        }

        // Read current provider_config so we can merge rather than clobber
        // (e.g. preserve linked_waha, voice config, phone_number_id, etc.).
        $inbox = $this->chatwootApiClient->getInbox(
            $chatwootUrl,
            $chatwootAccountApiKey,
            $chatwootAccountId,
            (int) $chatwootInboxId
        );

        if ($inbox === null) {
            $this->log->warning(
                "ChatwootInboxIntegration: patchInboxAccessToken — Chatwoot inbox {$chatwootInboxId} not found " .
                "for channel {$channel->getId()}."
            );
            return false;
        }

        $providerConfig = $inbox['provider_config'] ?? [];
        if (!is_array($providerConfig)) {
            $providerConfig = [];
        }

        // Guard against clobbering. Chatwoot only serializes provider_config for
        // administrator API users; if it came back without the WhatsApp Cloud
        // identifiers, the account API key is non-admin (or the inbox isn't a
        // cloud inbox) and PATCHing would wipe phone_number_id/business_account_id.
        // Bail rather than corrupt the inbox.
        if (empty($providerConfig['phone_number_id'])) {
            $this->log->warning(
                "ChatwootInboxIntegration: patchInboxAccessToken — inbox {$chatwootInboxId} returned no " .
                "phone_number_id in provider_config (non-admin API key or non-cloud inbox); skipping token re-sync " .
                "for channel {$channel->getId()}."
            );
            return false;
        }

        $currentApiKey = $providerConfig['api_key'] ?? null;

        // Token already current AND no sticky reauthorization to clear → skip the
        // PATCH. Chatwoot redacts some secrets in responses but api_key is
        // returned for whatsapp_cloud, so this comparison is reliable; if the
        // inbox is flagged we still patch to force reauthorized!.
        $reauthRequired = (bool) ($inbox['reauthorization_required'] ?? false);

        if ($currentApiKey === $accessToken && !$reauthRequired) {
            return false;
        }

        $providerConfig['api_key'] = $accessToken;

        // The PATCH endpoint calls channel.reauthorized! before update!, so this
        // single call both refreshes the token and clears the stuck reauth flag.
        $this->chatwootApiClient->updateInbox(
            $chatwootUrl,
            $chatwootAccountApiKey,
            $chatwootAccountId,
            (int) $chatwootInboxId,
            ['channel' => ['provider_config' => $providerConfig]]
        );

        $this->log->info(
            "ChatwootInboxIntegration: re-synced provider_config.api_key into Chatwoot inbox " .
            "{$chatwootInboxId} from OAuthAccount {$oAuthAccountId} (channel {$channel->getId()})" .
            ($reauthRequired ? ' and cleared reauthorization_required.' : '.')
        );

        return true;
    }

    public function findLinkedChatwootInboxRecordId(string $channelId): ?string
    {
        $inbox = $this->entityManager
            ->getRDBRepository('ChatwootInbox')
            ->where(['chatwootInboxIntegrationId' => $channelId])
            ->findOne();

        return $inbox ? $inbox->getId() : null;
    }

    /**
     * Roll back a partially-provisioned channel (used when activation fails).
     *
     * This is a top-level delete, not a cascade child, so it must NOT pass
     * `cascadeParent` — that flag makes CleanupOnRemove skip external teardown
     * and leaves the WAHA session running and authenticated to a real WhatsApp
     * number. External cleanup (WAHA session/app + remote Chatwoot inbox) is
     * delegated entirely to the CleanupOnRemove hook, which covers every
     * WAHA-backed channel type.
     *
     * @param string $channelId
     */
    public function removeIntegration(string $channelId): void
    {
        try {
            $inboxList = $this->entityManager
                ->getRDBRepository('ChatwootInbox')
                ->where(['chatwootInboxIntegrationId' => $channelId])
                ->find();

            foreach ($inboxList as $inbox) {
                $this->syncEmailOAuthCredentials->revokeForInbox($inbox);
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
            $this->entityManager->removeEntity($channel);
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

        if ($channelType === 'whatsappCoexistence') {
            return $this->checkStatusWhatsappCoexistence($channel);
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
        $wahaPlatform = $this->loadWahaPlatform($channel);
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

                // The OAuthAccount token just proved valid against Meta. Push it
                // into Chatwoot's provider_config.api_key so Chatwoot's own media
                // download uses the live token instead of the onboarding snapshot.
                // This also clears any sticky reauthorization_required flag tripped
                // by a previously rotated/stale token. No-ops when already in sync.
                try {
                    $this->patchInboxAccessToken($channel);
                } catch (\Exception $e) {
                    $this->log->warning(
                        "ChatwootInboxIntegration: failed to re-sync Chatwoot token for channel " .
                        "{$channel->getId()} (Cloud API): " . $e->getMessage()
                    );
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
     * Resolve the Chatwoot inbox for a QR channel idempotently.
     *
     * Re-activation (activate() is reachable from DRAFT|FAILED|DISCONNECTED, and is
     * also used to repair a broken WAHA session/app) must NOT spawn a duplicate
     * Chatwoot inbox and orphan the historical conversations. When the channel
     * already references an inbox that still exists in Chatwoot, we REUSE it and
     * only repoint its webhook_url at the (re)generated WAHA app URL. We create a
     * new inbox only when none is referenced, or the referenced one is gone.
     *
     * The returned array is the raw Chatwoot inbox payload (so upsertLocalChatwootInbox
     * can keep populating its columns).
     *
     * @param Entity $channel
     * @param string $chatwootUrl
     * @param string $chatwootAccountApiKey
     * @param int $chatwootAccountId
     * @param string $inboxName
     * @param string $wahaWebhookUrl
     * @return array<string, mixed> Chatwoot inbox payload (includes at least 'id', 'inbox_identifier')
     * @throws Error
     */
    private function resolveChatwootInboxForQr(
        Entity $channel,
        string $chatwootUrl,
        string $chatwootAccountApiKey,
        int $chatwootAccountId,
        string $inboxName,
        string $wahaWebhookUrl
    ): array {
        // The integration's `chatwootInboxId` attribute resolves to the LOCAL
        // ChatwootInbox entity id (a string hash), NOT the numeric Chatwoot inbox
        // id the REST API expects. Resolve the numeric id via the link entity —
        // same pattern documented in patchInboxAccessToken().
        $localInboxId = $channel->get('chatwootInboxId');
        $numericInboxId = null;

        if ($localInboxId) {
            $localInbox = $this->entityManager->getEntityById('ChatwootInbox', $localInboxId);
            if ($localInbox) {
                $numericInboxId = (int) $localInbox->get('chatwootInboxId');
            }
        }

        if ($numericInboxId) {
            $existingInbox = null;
            try {
                $existingInbox = $this->chatwootApiClient->getInbox(
                    $chatwootUrl,
                    $chatwootAccountApiKey,
                    $chatwootAccountId,
                    $numericInboxId
                );
            } catch (\Exception $e) {
                $existingInbox = null;
            }

            if ($existingInbox !== null && (($existingInbox['id'] ?? null) == $numericInboxId)) {
                // Reuse: repoint the existing inbox's webhook at the new WAHA app URL.
                $this->chatwootApiClient->updateInbox(
                    $chatwootUrl,
                    $chatwootAccountApiKey,
                    $chatwootAccountId,
                    $numericInboxId,
                    ['channel' => ['webhook_url' => $wahaWebhookUrl]]
                );

                $this->log->info(
                    "ChatwootInboxIntegration: Reusing existing Chatwoot inbox {$numericInboxId} " .
                    "for channel {$channel->getId()} (webhook_url repointed to new WAHA app)."
                );

                // Ensure the consumer always has these keys (getInbox may omit identifier).
                $existingInbox['id'] = $numericInboxId;
                if (empty($existingInbox['inbox_identifier'])) {
                    $existingInbox['inbox_identifier'] = $channel->get('chatwootInboxIdentifier');
                }

                return $existingInbox;
            }

            $this->log->warning(
                "ChatwootInboxIntegration: Channel {$channel->getId()} references Chatwoot inbox " .
                "{$numericInboxId} which no longer exists; creating a new inbox."
            );
        }

        return $this->createChatwootInbox(
            $chatwootUrl,
            $chatwootAccountApiKey,
            $chatwootAccountId,
            $inboxName,
            $wahaWebhookUrl
        );
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

        $chatwootAccount = $this->loadChatwootAccount($channel);

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

        $this->linkProvisionedInboxAccess($channel, $inbox);

        return $inbox->getId();
    }

    /**
     * Grant department/AI/creator access to a freshly provisioned inbox.
     *
     * - Links the integration's ChatwootTeams to the local ChatwootInbox. The
     *   SyncInboxTeams hook pushes the list to Chatwoot (inbox_teams), where
     *   members of linked teams are materialized into inbox members
     *   (department-scoped inbox privacy).
     * - Links every AI account-user membership of the account to the inbox.
     *   The SyncInboxMembership hook pushes the member list to Chatwoot so AI
     *   agents can read/reply on the inbox regardless of team configuration.
     * - Links the creating user's account membership so they can see the
     *   inbox in Chatwoot immediately (otherwise empty due to inbox privacy).
     *
     * Best-effort: failures are logged and never abort provisioning; the
     * links can be fixed manually on the ChatwootInbox record afterwards.
     */
    private function linkProvisionedInboxAccess(Entity $channel, Entity $inbox): void
    {
        try {
            $repository = $this->entityManager->getRDBRepository('ChatwootInbox');

            $teamsRelation = $repository->getRelation($inbox, 'chatwootTeams');

            foreach ($channel->getLinkMultipleIdList('chatwootTeams') as $teamId) {
                if (!$teamsRelation->isRelatedById($teamId)) {
                    $teamsRelation->relateById($teamId);
                }
            }

            $membershipsRelation = $repository->getRelation($inbox, 'accountUserMemberships');

            $aiMemberships = $this->entityManager
                ->getRDBRepository('ChatwootAccountUserMembership')
                ->where([
                    'chatwootAccountId' => $inbox->get('chatwootAccountId'),
                    'isAI' => true,
                ])
                ->find();

            foreach ($aiMemberships as $membership) {
                if (!$membershipsRelation->isRelatedById($membership->getId())) {
                    $membershipsRelation->relateById($membership->getId());
                }
            }

            $this->linkCreatorMembershipToInbox($channel, $inbox, $membershipsRelation);
        } catch (\Throwable $e) {
            $this->log->warning(
                'ChatwootInboxIntegration: failed to link provisioned inbox access for inbox ' .
                $inbox->getId() . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * Attach the provisioner's ChatwootAccountUserMembership to the inbox.
     * SyncInboxMembership pushes the full member list to Chatwoot.
     */
    private function linkCreatorMembershipToInbox(
        Entity $channel,
        Entity $inbox,
        mixed $membershipsRelation
    ): void {
        $creatorUserId = $channel->get('createdById');

        if (!$creatorUserId || $creatorUserId === 'system') {
            return;
        }

        $accountId = $inbox->get('chatwootAccountId');

        if (!$accountId) {
            return;
        }

        $membership = $this->entityManager
            ->getRDBRepository('ChatwootAccountUserMembership')
            ->leftJoin('chatwootUser')
            ->where([
                'chatwootAccountId' => $accountId,
                'chatwootUser.assignedUserId' => $creatorUserId,
            ])
            ->findOne();

        if (!$membership) {
            $this->log->warning(
                'ChatwootInboxIntegration: creator user ' . $creatorUserId .
                ' has no ChatwootAccountUserMembership on account ' . $accountId .
                '; skipping inbox agent link for inbox ' . $inbox->getId()
            );

            return;
        }

        if ($membershipsRelation->isRelatedById($membership->getId())) {
            return;
        }

        $membershipsRelation->relateById($membership->getId());

        $this->log->info(
            'ChatwootInboxIntegration: linked creator membership ' . $membership->getId() .
            ' to inbox ' . $inbox->getId()
        );
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
        string $businessAccountId,
        bool $isCoexistence = false,
    ): array {
        $url = rtrim($chatwootUrl, '/') . "/api/v1/accounts/{$accountId}/inboxes";

        $providerConfig = [
            'api_key' => $accessToken,
            'phone_number_id' => $phoneNumberId,
            'business_account_id' => $businessAccountId,
            'is_coexistence' => $isCoexistence,
        ];

        // embedded_signup skips Chatwoot's create-time webhook auto-setup
        // (Channel::Whatsapp#should_auto_setup_webhooks?). Coexistence paths
        // top up subscribed_apps via MetaGraphApiClient themselves; cloud API
        // keeps auto-setup by omitting source.
        if ($isCoexistence) {
            $providerConfig['source'] = 'embedded_signup';
        }

        $payload = json_encode([
            'name' => $inboxName,
            'lock_to_single_conversation' => true,
            'channel' => [
                'type' => 'whatsapp',
                'phone_number' => $phoneNumber,
                'provider' => 'whatsapp_cloud',
                'provider_config' => $providerConfig,
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
            $chatwootAccount = $this->loadChatwootAccount($channel);

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

            // "Already exchanged" must reflect the CURRENT stored token, not a
            // stale marker. The bug it fixes: after a fresh re-authorization the
            // OAuthAccount holds a NEW short-lived token, but a leftover
            // `metaIgLongLivedExchangedAt` from a previous session made the old
            // logic believe the token was already long-lived — so it SKIPPED the
            // ig_exchange_token step and pushed an un-exchanged (short-lived /
            // non-refreshable) token to Chatwoot. That token then returns
            // OAuthException code 452 on ig_refresh_token and dies early.
            //
            // A token is only genuinely long-lived if its expiry is far in the
            // future (a short-lived IG token lasts ~1h). We therefore require a
            // present `expiresAt` that is > 2 days out. The exchange marker alone
            // is NOT sufficient. This makes a re-auth always re-exchange unless
            // the stored token is provably already long-lived.
            $expiresTs = $expiresAt ? strtotime((string) $expiresAt) : false;
            $alreadyExchanged = $expiresTs !== false
                && $expiresTs > time() + 2 * 24 * 60 * 60;

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
            $chatwootPlatform = $this->loadChatwootPlatform($chatwootAccount);

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

        // The NUMERIC Chatwoot inbox id (what the Chatwoot REST API expects)
        // lives on the linked ChatwootInbox entity, NOT on this integration:
        // the integration's own `chatwootInboxId` attribute has no backing
        // column and resolves to the link's entity-id string. Resolve the real
        // numeric id via the link.
        $chatwootInbox = $channel->get('chatwootInbox');
        $numericInboxId = $chatwootInbox ? $chatwootInbox->get('chatwootInboxId') : null;

        if (!$numericInboxId) {
            // Never provisioned (or inbox link missing) → full activation.
            return $this->activate($channelId);
        }

        try {
            $tokenExpiresAt = $channel->get('tokenExpiresAt');

            if ($tokenExpiresAt && strtotime($tokenExpiresAt) < time()) {
                throw new Error(
                    "Instagram long-lived token has expired. Please re-authorize the Meta (Instagram) OAuth Account."
                );
            }

            $chatwootAccount = $this->loadChatwootAccount($channel);

            if (!$chatwootAccount) {
                throw new Error("Chatwoot Account not set.");
            }

            $chatwootPlatform = $this->loadChatwootPlatform($chatwootAccount);

            if (!$chatwootPlatform) {
                throw new Error("Chatwoot Platform not found for account.");
            }

            $chatwootUrl = $chatwootPlatform->get('backendUrl');
            $chatwootAccountApiKey = $chatwootAccount->get('apiKey');
            $chatwootAccountId = $chatwootAccount->get('chatwootAccountId');

            if (!$chatwootAccountApiKey || !$chatwootAccountId) {
                throw new Error("ChatwootAccount is missing API key or account id.");
            }

            // Confirm the Chatwoot inbox still exists; if it was deleted there,
            // re-activate from scratch.
            $inboxes = $this->chatwootApiClient->listInboxes(
                $chatwootUrl,
                $chatwootAccountApiKey,
                (int) $chatwootAccountId
            );

            $inboxExists = false;
            $inboxList = $inboxes['payload'] ?? $inboxes;
            foreach ($inboxList as $inbox) {
                if ((int) ($inbox['id'] ?? 0) === (int) $numericInboxId) {
                    $inboxExists = true;
                    break;
                }
            }

            if (!$inboxExists) {
                $this->log->info("ChatwootInboxIntegration: Chatwoot inbox {$numericInboxId} no longer exists, re-activating Instagram channel.");
                $channel->set('chatwootInboxId', null);
                $channel->set('chatwootInboxIdentifier', null);
                $this->entityManager->saveEntity($channel);
                return $this->activate($channelId);
            }

            // PUSH the current CRM token to Chatwoot so the two systems do not
            // drift. Reconnect previously only re-validated and flipped status
            // to ACTIVE, leaving Chatwoot holding a stale (often dead) token —
            // which silently broke inbound DMs after every CRM-side token
            // update. Now we PATCH access_token + expires_at onto the inbox.
            $oAuthAccountId = $channel->get('oAuthAccountId');

            if (!$oAuthAccountId) {
                throw new Error("Meta Account (OAuth) not set on this integration.");
            }

            $tokens = $this->tokensProvider->get($oAuthAccountId);
            $accessToken = $tokens->getAccessToken();

            if (!$accessToken) {
                throw new Error("Unable to obtain access token from the Meta (Instagram) OAuth Account.");
            }

            // Chatwoot's Channel::Instagram REQUIRES a non-null expires_at (its
            // access_token getter returns nil when blank). Prefer the OAuthAccount
            // expiry, fall back to the integration mirror, then a ~60-day default.
            $oAuthAccount = $this->entityManager->getEntityById('OAuthAccount', $oAuthAccountId);
            $expiresAtRaw = ($oAuthAccount ? $oAuthAccount->get('expiresAt') : null)
                ?: $tokenExpiresAt
                ?: gmdate('Y-m-d H:i:s', time() + 60 * 24 * 60 * 60);
            $expiresAtIso = $this->normaliseExpiresAt($expiresAtRaw);

            $channelPayload = ['access_token' => $accessToken];
            if ($expiresAtIso) {
                $channelPayload['expires_at'] = $expiresAtIso;
            }

            $this->chatwootApiClient->updateInbox(
                $chatwootUrl,
                $chatwootAccountApiKey,
                (int) $chatwootAccountId,
                (int) $numericInboxId,
                ['channel' => $channelPayload]
            );

            $this->log->info("ChatwootInboxIntegration: pushed refreshed token to Chatwoot inbox {$numericInboxId} during reconnect.");

            $channel->set('tokenExpiresAt', $expiresAtRaw ?: null);
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

    // ---------------------------------------------------------------------
    // WhatsApp Coexistence (WhatsApp Business app onboarding)
    // ---------------------------------------------------------------------
    //
    // Coexistence vs Cloud-API-only:
    //   - Both share the same Chatwoot inbox shape (Channel::Whatsapp +
    //     provider=whatsapp_cloud). The Chatwoot inbox creation payload
    //     is identical, which means we can reuse createChatwootWhatsappCloudInbox().
    //   - What's different is the Meta-side state machine. For Coexistence,
    //     Meta will NOT deliver `messages` / `smb_message_echoes` webhooks
    //     until the customer pastes a 6-digit verification code in the
    //     WhatsApp Business app and Meta flips:
    //       GET /{phone_number_id}.platform_type     → CLOUD_API
    //       GET /{phone_number_id}.is_on_biz_app     → true
    //   - In addition, we have a HARD 24h deadline to POST
    //     /{phone_number_id}/smb_app_data with sync_type=smb_app_state_sync
    //     (handled by WhatsAppCoexistenceSyncService, queued during the
    //     Embedded Signup finish step).
    //
    // So `activateWhatsappCoexistence`:
    //   1. Resolves token + waba + phone_number_id from the OAuthAccount
    //      (set there by WhatsAppEmbeddedSignup::finish from session_info).
    //   2. Creates the Chatwoot WhatsApp Cloud inbox (same as Cloud API).
    //   3. Explicitly subscribes the Meta App to the Coexistence webhook
    //      fields on the WABA (idempotent — Chatwoot's own subscribe call
    //      uses a narrower field list, so we top it up).
    //   4. Probes platform_type. If `CLOUD_API + is_on_biz_app`, marks ACTIVE.
    //      Otherwise marks PENDING_COEXISTENCE_CONFIRMATION and leaves the
    //      WhatsAppCoexistenceSyncService job to do the rest async.

    private function activateWhatsappCoexistence(Entity $channel): Entity
    {
        $channelId = $channel->getId();

        try {
            $chatwootAccount = $this->loadChatwootAccount($channel);

            if (!$chatwootAccount) {
                throw new Error("Chatwoot Account not set.");
            }

            $oAuthAccountId = $channel->get('oAuthAccountId');

            if (!$oAuthAccountId) {
                throw new Error("Meta Account (OAuth) not set. Please complete the Embedded Signup flow on a meta-whatsapp-coexistence OAuth Account before creating this integration.");
            }

            $oAuthAccount = $this->entityManager->getEntityById('OAuthAccount', $oAuthAccountId);

            if (!$oAuthAccount) {
                throw new Error("OAuthAccount not found.");
            }

            $tokens = $this->tokensProvider->get($oAuthAccountId);
            $accessToken = $tokens->getAccessToken();

            if (!$accessToken) {
                throw new Error("Unable to obtain access token from the Meta (WhatsApp Coexistence) OAuth Account.");
            }

            // Resolve businessAccountId / phoneNumberId / phoneNumber.
            //
            // Prefer values captured by the Embedded Signup flow into the
            // OAuthAccount; fall back to user-selected fields on the
            // integration entity (in case the admin overrides via UI).
            $businessAccountId = $channel->get('businessAccountId')
                ?: (string) ($oAuthAccount->get('whatsappBusinessAccountId') ?? '');
            $phoneNumberId = $channel->get('phoneNumberId')
                ?: (string) ($oAuthAccount->get('whatsappPhoneNumberId') ?? '');

            if (!$businessAccountId) {
                throw new Error("WhatsApp Business Account ID not set. Re-run Embedded Signup if missing.");
            }

            if (!$phoneNumberId) {
                throw new Error("WhatsApp Phone Number ID not set. Re-run Embedded Signup if missing.");
            }

            // Resolve display phone number for Chatwoot from Meta if absent.
            $phoneNumber = $channel->get('phoneNumber');

            if (!$phoneNumber) {
                $phoneData = $this->metaGraphApiClient->getPhoneNumber($accessToken, $phoneNumberId);
                $phoneNumber = $phoneData['display_phone_number'] ?? null;

                if (!$phoneNumber) {
                    throw new Error("Could not resolve display_phone_number from Meta for phone_number_id={$phoneNumberId}.");
                }

                $channel->set('phoneNumber', $phoneNumber);
            }

            $normalizedPhoneNumber = '+' . preg_replace('/[^0-9]/', '', $phoneNumber);

            // Chatwoot connection details.
            $chatwootPlatform = $this->loadChatwootPlatform($chatwootAccount);

            if (!$chatwootPlatform) {
                throw new Error("Chatwoot Platform not found for account.");
            }

            $chatwootUrl = $chatwootPlatform->get('backendUrl');
            $chatwootAccountId = $chatwootAccount->get('chatwootAccountId');
            $chatwootAccountApiKey = $chatwootAccount->get('apiKey');

            if (!$chatwootAccountApiKey) {
                throw new Error("ChatwootAccount is missing API key. Please generate a User Access Token in Chatwoot (Settings > Account Settings > API Access Tokens) and add it to the ChatwootAccount.");
            }

            // Create the Chatwoot WhatsApp Cloud inbox with is_coexistence so
            // Chatwoot enables coexistence composer / WAHA outbound paths.
            $inboxName = 'WhatsApp - ' . $channel->get('name');
            $inboxResult = $this->createChatwootWhatsappCloudInbox(
                $chatwootUrl,
                $chatwootAccountApiKey,
                (int) $chatwootAccountId,
                $inboxName,
                $normalizedPhoneNumber,
                $accessToken,
                $phoneNumberId,
                $businessAccountId,
                true,
            );

            $channel->set('chatwootInboxId', $inboxResult['id']);
            $channel->set('chatwootInboxIdentifier', $inboxResult['inbox_identifier'] ?? null);
            $channel->set('chatwootInboxRecordId', $this->upsertLocalChatwootInbox($channel, $inboxResult));

            // Subscribe the Meta App to Coexistence webhook fields on the WABA.
            // Chatwoot's `Channel::Whatsapp` already calls subscribed_apps with
            // a narrower set; this is a redundant-safe top-up.
            try {
                $this->metaGraphApiClient->subscribeApp(
                    $accessToken,
                    $businessAccountId,
                );
            } catch (\Exception $e) {
                // Non-fatal: Chatwoot's own subscription will still work for
                // the base events. Log + continue.
                $this->log->warning(
                    "ChatwootInboxIntegration: Coexistence webhook top-up subscription failed for WABA {$businessAccountId}: " .
                    $e->getMessage()
                );
            }

            // Persist Coexistence-specific metadata on the integration.
            $channel->set('businessAccountId', $businessAccountId);
            $channel->set('phoneNumberId', $phoneNumberId);

            // Probe phone state. Webhooks only flow when platform_type=CLOUD_API
            // AND is_on_biz_app=true.
            $coexistenceReady = false;

            try {
                $phoneData = $this->metaGraphApiClient->getPhoneNumber($accessToken, $phoneNumberId);
                $platformType = $phoneData['platform_type'] ?? null;
                $isOnBizApp = (bool) ($phoneData['is_on_biz_app'] ?? false);

                if ($platformType === 'CLOUD_API' && $isOnBizApp) {
                    $coexistenceReady = true;
                    $channel->set('status', 'ACTIVE');
                    $channel->set('connectedAt', date('Y-m-d H:i:s'));
                    $channel->set('errorMessage', null);
                } else {
                    $channel->set('status', 'PENDING_COEXISTENCE_CONFIRMATION');
                    $channel->set(
                        'errorMessage',
                        "Waiting for Coexistence handshake. The customer must paste the verification code in the WhatsApp Business app " .
                        "(Settings → Account → Business Platform). Meta says: platform_type={$platformType}, is_on_biz_app=" .
                        ($isOnBizApp ? 'true' : 'false') . "."
                    );
                }
            } catch (\Exception $e) {
                // Probe failed — assume PENDING so checkStatus() can retry.
                $channel->set('status', 'PENDING_COEXISTENCE_CONFIRMATION');
                $channel->set(
                    'errorMessage',
                    'Could not probe Meta phone state during activation: ' . $e->getMessage()
                );
            }

            $this->entityManager->saveEntity($channel);

            // When the number is already coexistence-ready, run SMB history/contacts
            // sync here too (finish/hydrate may have been skipped or timed out).
            // Still inside Meta's 24h window after in-app confirmation.
            if ($coexistenceReady && !$oAuthAccount->get('whatsappCoexistenceSyncedAt')) {
                try {
                    $syncResult = $this->whatsAppCoexistenceSyncService->sync($oAuthAccountId);
                    $this->log->info(
                        "ChatwootInboxIntegration: triggered Coexistence sync from activation for {$oAuthAccountId}: " .
                        json_encode($syncResult)
                    );
                } catch (\Exception $e) {
                    $this->log->warning(
                        "ChatwootInboxIntegration: Coexistence sync from activation failed for {$oAuthAccountId}: " .
                        $e->getMessage()
                    );
                }
            }

            $this->log->info("ChatwootInboxIntegration: WhatsApp Coexistence channel {$channelId} activated (status=" . $channel->get('status') . ").");

            return $channel;

        } catch (\Exception $e) {
            $this->log->error("ChatwootInboxIntegration activation failed (Coexistence): " . $e->getMessage());
            $channel->set('status', 'FAILED');
            $channel->set('errorMessage', $e->getMessage());
            $this->entityManager->saveEntity($channel);
            throw new Error("Activation failed: " . $e->getMessage());
        }
    }

    /**
     * Reconnect a WhatsApp Coexistence channel.
     *
     * If the Chatwoot inbox is missing, re-activate from scratch.
     * Otherwise re-run the Meta probe and the Coexistence sync; the channel
     * goes back to ACTIVE only when platform_type=CLOUD_API + is_on_biz_app.
     *
     * @throws Error
     */
    private function reconnectWhatsappCoexistence(Entity $channel): Entity
    {
        $channelId = $channel->getId();
        $chatwootInboxId = $channel->get('chatwootInboxId');

        if (!$chatwootInboxId) {
            return $this->activate($channelId);
        }

        try {
            // Verify the Chatwoot inbox still exists; if not, re-activate.
            $chatwootAccount = $this->loadChatwootAccount($channel);

            if (!$chatwootAccount) {
                throw new Error("Chatwoot Account not set.");
            }

            $chatwootPlatform = $this->loadChatwootPlatform($chatwootAccount);

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
                    $this->log->info(
                        "ChatwootInboxIntegration: Coexistence — Chatwoot inbox {$chatwootInboxId} no longer exists, re-activating."
                    );
                    $channel->set('chatwootInboxId', null);
                    $channel->set('chatwootInboxIdentifier', null);
                    $this->entityManager->saveEntity($channel);
                    return $this->activate($channelId);
                }
            }

            // If a WAHA send companion was previously provisioned, restart it
            // and re-enter the link flow so free-form routing is restored. The
            // session may need a fresh QR scan if the link was lost.
            $wahaPlatform = $this->loadWahaPlatform($channel);
            $sessionName = $channel->get('wahaSessionName');

            if ($wahaPlatform && $sessionName) {
                try {
                    $wahaUrl = $wahaPlatform->get('backendUrl');
                    $wahaApiKey = $wahaPlatform->get('apiKey');
                    $this->wahaApiClient->startSession($wahaUrl, $wahaApiKey, $sessionName);
                    $channel->set('status', 'PENDING_WAHA_LINK');
                    $channel->set('errorMessage', null);
                    $this->entityManager->saveEntity($channel);
                } catch (\Exception $e) {
                    $this->log->warning("ChatwootInboxIntegration: Failed to restart WAHA companion during coexistence reconnect: " . $e->getMessage());
                }
            }

            // Re-run the same Meta probe path as checkStatusWhatsappCoexistence
            // (also resolves PENDING_WAHA_LINK if the companion is already WORKING).
            return $this->checkStatusWhatsappCoexistence($channel);

        } catch (\Exception $e) {
            $this->log->error("Reconnect failed (Coexistence): " . $e->getMessage());
            $channel->set('status', 'FAILED');
            $channel->set('errorMessage', $e->getMessage());
            $this->entityManager->saveEntity($channel);
            throw new Error("Reconnect failed: " . $e->getMessage());
        }
    }

    /**
     * Poll the WAHA send companion session. When it reaches WORKING, persist
     * the linked_waha block into the Cloud inbox provider_config so Chatwoot
     * can route free-form replies through it. Returns true once linked.
     */
    private function resolveWahaCompanionLink(Entity $channel): bool
    {
        $wahaPlatform = $this->loadWahaPlatform($channel);
        $sessionName = $channel->get('wahaSessionName');

        if (!$wahaPlatform || !$sessionName) {
            return false;
        }

        $wahaUrl = $wahaPlatform->get('backendUrl');
        $wahaApiKey = $wahaPlatform->get('apiKey');

        try {
            $sessionInfo = $this->wahaApiClient->getSession($wahaUrl, $wahaApiKey, $sessionName);
        } catch (\Exception $e) {
            $this->log->warning("ChatwootInboxIntegration: Failed to poll WAHA companion {$sessionName}: " . $e->getMessage());
            return false;
        }

        $wahaStatus = $sessionInfo['status'] ?? 'UNKNOWN';

        if ($wahaStatus !== 'WORKING') {
            return false;
        }

        // Capture the paired WhatsApp identity for display/audit.
        if (isset($sessionInfo['me'])) {
            $channel->set('whatsappId', $sessionInfo['me']['id'] ?? null);
            $channel->set('whatsappName', $sessionInfo['me']['pushName'] ?? null);
        }

        // Write linked_waha into the Cloud inbox provider_config.
        // Shape consumed by Chatwoot Channel::Whatsapp#coexistence_waha_link:
        //   { inbox_id, session, base_url }
        $this->syncCoexistenceWahaLink($channel, [
            'inbox_id' => $this->getNumericChatwootInboxId($channel),
            'session' => $sessionName,
            'base_url' => rtrim($wahaUrl, '/'),
        ]);

        $this->log->info("ChatwootInboxIntegration: WAHA send companion linked for coexistence channel {$channel->getId()} (session {$sessionName}).");

        return true;
    }

    /**
     * Status check for WhatsApp Coexistence.
     *
     * Probes /{phone_number_id} and runs the deferred SMB-data sync when
     * the customer has just finished the in-app step.
     */
    private function checkStatusWhatsappCoexistence(Entity $channel): Entity
    {
        // If we're waiting on the WAHA send companion QR link, resolve that
        // first. Once the companion session reaches WORKING we write the
        // linked_waha block into the Cloud inbox and then fall through to the
        // normal Meta probe to settle on ACTIVE / PENDING_COEXISTENCE_CONFIRMATION.
        if ($channel->get('status') === 'PENDING_WAHA_LINK') {
            $linked = $this->resolveWahaCompanionLink($channel);

            if (!$linked) {
                // Still not paired (or session unhealthy) — stay in the QR state.
                return $channel;
            }
            // Paired: continue to the Meta probe below to determine final status.
        }

        $phoneNumberId = $channel->get('phoneNumberId');
        $oAuthAccountId = $channel->get('oAuthAccountId');

        if (!$phoneNumberId || !$oAuthAccountId) {
            return $channel;
        }

        try {
            $tokens = $this->tokensProvider->get($oAuthAccountId);
            $accessToken = $tokens->getAccessToken();

            if (!$accessToken) {
                if ($channel->get('status') === 'ACTIVE') {
                    $channel->set('status', 'DISCONNECTED');
                    $channel->set('errorMessage', 'Unable to obtain access token from Meta (WhatsApp Coexistence) OAuth Account.');
                    $this->entityManager->saveEntity($channel);
                }

                return $channel;
            }

            $phoneData = $this->metaGraphApiClient->getPhoneNumber($accessToken, $phoneNumberId);
            $platformType = $phoneData['platform_type'] ?? null;
            $isOnBizApp = (bool) ($phoneData['is_on_biz_app'] ?? false);

            $currentStatus = $channel->get('status');

            if ($platformType === 'CLOUD_API' && $isOnBizApp) {
                // Coexistence-ready. If we haven't synced yet on the OAuthAccount,
                // run it now (still within the 24h window).
                $oAuthAccount = $this->entityManager->getEntityById('OAuthAccount', $oAuthAccountId);

                if ($oAuthAccount && !$oAuthAccount->get('whatsappCoexistenceSyncedAt')) {
                    try {
                        $syncResult = $this->whatsAppCoexistenceSyncService->sync($oAuthAccountId);
                        $this->log->info(
                            "ChatwootInboxIntegration: triggered Coexistence sync from checkStatus for {$oAuthAccountId}: " .
                            json_encode($syncResult)
                        );
                    } catch (\Exception $e) {
                        $this->log->warning(
                            "ChatwootInboxIntegration: Coexistence sync from checkStatus failed for {$oAuthAccountId}: " .
                            $e->getMessage()
                        );
                    }
                }

                if ($currentStatus !== 'ACTIVE') {
                    $channel->set('status', 'ACTIVE');
                    $channel->set('connectedAt', $channel->get('connectedAt') ?: date('Y-m-d H:i:s'));
                    $channel->set('errorMessage', null);
                    $this->entityManager->saveEntity($channel);
                }

                // Coexistence inboxes are native whatsapp_cloud Chatwoot inboxes
                // carrying the same snapshotted provider_config.api_key, so they
                // share the stale-token failure mode. The token just proved valid
                // against Meta (getPhoneNumber above), so re-sync it into Chatwoot
                // and clear any sticky reauthorization_required. No-ops when
                // already in sync.
                try {
                    $this->patchInboxAccessToken($channel);
                } catch (\Exception $e) {
                    $this->log->warning(
                        "ChatwootInboxIntegration: failed to re-sync Chatwoot token for channel " .
                        "{$channel->getId()} (Coexistence): " . $e->getMessage()
                    );
                }
            } else {
                // Not yet Coexistence-ready.
                $expectedStatus = 'PENDING_COEXISTENCE_CONFIRMATION';
                $message = "Waiting for Coexistence handshake. Meta says: platform_type={$platformType}, is_on_biz_app=" .
                    ($isOnBizApp ? 'true' : 'false') . '.';

                if ($currentStatus !== $expectedStatus) {
                    $channel->set('status', $expectedStatus);
                }

                $channel->set('errorMessage', $message);
                $this->entityManager->saveEntity($channel);
            }
        } catch (\Exception $e) {
            $this->log->warning("Failed to check channel status (Coexistence): " . $e->getMessage());
        }

        return $channel;
    }

    /**
     * Load a belongs-to link by "{link}Id". Espo Entity::get(linkName) does not
     * hydrate relations after getEntityById — only the *Id attribute is present.
     */
    private function loadLinkedEntity(Entity $entity, string $link, string $foreignEntityType): ?Entity
    {
        $id = $entity->get($link . 'Id');

        if (!$id) {
            return null;
        }

        return $this->entityManager->getEntityById($foreignEntityType, (string) $id);
    }

    private function loadChatwootAccount(Entity $channel): ?Entity
    {
        return $this->loadLinkedEntity($channel, 'chatwootAccount', 'ChatwootAccount');
    }

    private function loadChatwootPlatform(Entity $chatwootAccount): ?Entity
    {
        return $this->loadLinkedEntity($chatwootAccount, 'platform', 'ChatwootPlatform');
    }

    private function loadWahaPlatform(Entity $channel): ?Entity
    {
        return $this->loadLinkedEntity($channel, 'wahaPlatform', 'WahaPlatform');
    }
}
