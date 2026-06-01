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

/**
 * HTTP client for Meta Graph API (WhatsApp Business).
 *
 * Wraps cURL calls to fetch WhatsApp Business Account data,
 * phone numbers, business discovery, webhook subscriptions, and
 * Coexistence (WhatsApp Business app onboarding) SMB data sync from
 * the Meta Graph API.
 */
class MetaGraphApiClient
{
    public const DEFAULT_API_VERSION = 'v22.0';
    private const GRAPH_API_BASE = 'https://graph.facebook.com';
    private const TIMEOUT_SECONDS = 10;

    /**
     * Webhook fields the CRM expects Meta to deliver per WABA. Listed here
     * so callers can request explicit subscription instead of relying on
     * Meta's defaults. Includes Coexistence-specific fields that ride on
     * top of the regular WhatsApp Business message events:
     *   - smb_message_echoes     — outbound messages sent from the
     *                              WhatsApp Business app (Coexistence)
     *   - smb_app_state_sync     — Coexistence state changes (e.g. customer
     *                              unlinks the WhatsApp Business app)
     *   - history                — chat history sync from the WhatsApp
     *                              Business app
     *   - account_update         — WABA-level updates (phone restore,
     *                              quality rating, etc.)
     *   - message_template_status_update — template approvals
     */
    public const SUBSCRIBED_FIELDS = [
        'messages',
        'smb_message_echoes',
        'smb_app_state_sync',
        'history',
        'account_update',
        'message_template_status_update',
    ];

    public function __construct(
        private Log $log,
    ) {}

    /**
     * Discover Meta Businesses accessible to the current token.
     *
     * @param string $accessToken Meta access token
     * @param string $apiVersion API version (e.g. v21.0)
     * @return array<int, array<string, mixed>> List of business objects
     * @throws Error
     */
    public function discoverBusinesses(
        string $accessToken,
        string $apiVersion = self::DEFAULT_API_VERSION,
    ): array {
        $url = self::GRAPH_API_BASE . "/{$apiVersion}/me/businesses"
            . '?fields=id,name';

        $response = $this->request($url, $accessToken);

        return $response['data'] ?? [];
    }

    /**
     * Discover WhatsApp Business Accounts owned by a Meta Business.
     *
     * @param string $accessToken Meta access token
     * @param string $businessId Meta Business ID
     * @param string $apiVersion API version (e.g. v21.0)
     * @return array<int, array<string, mixed>> List of WABA objects
     * @throws Error
     */
    public function discoverWabas(
        string $accessToken,
        string $businessId,
        string $apiVersion = self::DEFAULT_API_VERSION,
    ): array {
        $url = self::GRAPH_API_BASE . "/{$apiVersion}/{$businessId}/owned_whatsapp_business_accounts"
            . '?fields=id,name,timezone_id,message_template_namespace,currency';

        $response = $this->request($url, $accessToken);

        return $response['data'] ?? [];
    }

    /**
     * Fetch WhatsApp Business Account details.
     *
     * @param string $accessToken Meta access token
     * @param string $businessAccountId WABA ID
     * @param string $apiVersion API version (e.g. v21.0)
     * @return array<string, mixed> WABA data
     * @throws Error
     */
    public function getBusinessAccount(
        string $accessToken,
        string $businessAccountId,
        string $apiVersion = self::DEFAULT_API_VERSION,
    ): array {
        $url = self::GRAPH_API_BASE . "/{$apiVersion}/{$businessAccountId}"
            . '?fields=id,name,timezone_id,message_template_namespace,currency';

        return $this->request($url, $accessToken);
    }

    /**
     * Fetch phone numbers for a WhatsApp Business Account.
     *
     * @param string $accessToken Meta access token
     * @param string $businessAccountId WABA ID
     * @param string $apiVersion API version (e.g. v21.0)
     * @return array<int, array<string, mixed>> List of phone number objects
     * @throws Error
     */
    public function getPhoneNumbers(
        string $accessToken,
        string $businessAccountId,
        string $apiVersion = self::DEFAULT_API_VERSION,
    ): array {
        $url = self::GRAPH_API_BASE . "/{$apiVersion}/{$businessAccountId}/phone_numbers"
            . '?fields=id,display_phone_number,verified_name,quality_rating';

        $response = $this->request($url, $accessToken);

        return $response['data'] ?? [];
    }

    /**
     * Fetch message templates for a WhatsApp Business Account.
     *
     * @param string $accessToken Meta access token
     * @param string $businessAccountId WABA ID
     * @param string $apiVersion API version (e.g. v21.0)
     * @return array<int, array<string, mixed>> List of message template objects
     * @throws Error
     */
    public function getMessageTemplates(
        string $accessToken,
        string $businessAccountId,
        string $apiVersion = self::DEFAULT_API_VERSION,
    ): array {
        $url = self::GRAPH_API_BASE . "/{$apiVersion}/{$businessAccountId}/message_templates"
            . '?fields=id,name,language,status,category,components';

        $response = $this->request($url, $accessToken);

        return $response['data'] ?? [];
    }

    /**
     * Fetch subscribed apps and webhook override configuration for a WABA.
     *
     * Uses GET /<WABA_ID>/subscribed_apps to retrieve the list of apps
     * subscribed to webhooks on the WABA, including any alternate
     * callback URL (override_callback_uri).
     *
     * @param string $accessToken Meta access token
     * @param string $businessAccountId WABA ID
     * @param string $apiVersion API version (e.g. v21.0)
     * @return array<int, array<string, mixed>> List of subscribed app objects
     * @throws Error
     */
    public function getSubscribedApps(
        string $accessToken,
        string $businessAccountId,
        string $apiVersion = self::DEFAULT_API_VERSION,
    ): array {
        $url = self::GRAPH_API_BASE . "/{$apiVersion}/{$businessAccountId}/subscribed_apps";

        $response = $this->request($url, $accessToken);

        return $response['data'] ?? [];
    }

    /**
     * Get a specific template by name from a WABA.
     * Filters results from getMessageTemplates() client-side.
     *
     * @param string $accessToken Meta access token
     * @param string $businessAccountId WABA ID
     * @param string $templateName Template name to find
     * @param string $apiVersion API version (e.g. v21.0)
     * @return array<string, mixed>|null Template data or null if not found
     * @throws Error
     */
    public function getTemplateByName(
        string $accessToken,
        string $businessAccountId,
        string $templateName,
        string $apiVersion = self::DEFAULT_API_VERSION,
    ): ?array {
        $templates = $this->getMessageTemplates($accessToken, $businessAccountId, $apiVersion);

        foreach ($templates as $template) {
            if (($template['name'] ?? null) === $templateName) {
                return $template;
            }
        }

        return null;
    }

    /**
     * Fetch a single phone number with Coexistence-aware fields.
     *
     * Endpoint: GET /{phone_number_id}
     *   ?fields=id,display_phone_number,verified_name,quality_rating,
     *           is_on_biz_app,platform_type,code_verification_status
     *
     * Used by ChatwootInboxIntegration::checkStatusWhatsappCoexistence() to
     * detect whether the customer has finished the Coexistence handshake
     * (`platform_type=CLOUD_API` AND `is_on_biz_app=true`). Until both
     * conditions are true, Meta will NOT dispatch `messages` or
     * `smb_message_echoes` webhooks for this number, regardless of
     * `subscribed_apps` settings.
     *
     * @param string $accessToken Meta access token
     * @param string $phoneNumberId Meta Phone Number ID
     * @param string $apiVersion API version (e.g. v22.0)
     * @return array<string, mixed> Phone number data
     * @throws Error
     */
    public function getPhoneNumber(
        string $accessToken,
        string $phoneNumberId,
        string $apiVersion = self::DEFAULT_API_VERSION,
    ): array {
        $url = self::GRAPH_API_BASE . "/{$apiVersion}/{$phoneNumberId}"
            . '?fields=id,display_phone_number,verified_name,quality_rating,'
            . 'is_on_biz_app,platform_type,code_verification_status';

        return $this->request($url, $accessToken);
    }

    /**
     * Subscribe the Meta App to webhook events for a given WABA.
     *
     * Endpoint: POST /{waba_id}/subscribed_apps
     *   (optionally with `override_callback_uri` + `verify_token` to
     *   pin a per-inbox webhook URL on the WABA level)
     *
     * Required for webhook delivery. Chatwoot's `Channel::Whatsapp` already
     * registers the subscription as part of inbox creation (via the
     * `whatsapp_cloud` provider), but for Coexistence we need to make sure
     * the subscribed_fields explicitly include the Coexistence-only fields
     * (`smb_message_echoes`, `smb_app_state_sync`, `history`).
     *
     * Idempotent — multiple calls with the same fields are safe.
     *
     * @param string $accessToken Meta access token
     * @param string $businessAccountId WABA ID
     * @param string[]|null $subscribedFields Fields to subscribe (defaults to SUBSCRIBED_FIELDS).
     * @param string|null $overrideCallbackUri Optional per-WABA webhook URL override.
     * @param string|null $verifyToken Required when $overrideCallbackUri is set.
     * @param string $apiVersion API version (e.g. v22.0)
     * @return array<string, mixed> Decoded response (expected `{success: true}` or `{data:[...]}`)
     * @throws Error
     */
    public function subscribeApp(
        string $accessToken,
        string $businessAccountId,
        ?array $subscribedFields = null,
        ?string $overrideCallbackUri = null,
        ?string $verifyToken = null,
        string $apiVersion = self::DEFAULT_API_VERSION,
    ): array {
        $fields = $subscribedFields ?? self::SUBSCRIBED_FIELDS;

        $body = [
            'subscribed_fields' => implode(',', $fields),
        ];

        if ($overrideCallbackUri) {
            $body['override_callback_uri'] = $overrideCallbackUri;

            if ($verifyToken) {
                $body['verify_token'] = $verifyToken;
            }
        }

        $url = self::GRAPH_API_BASE . "/{$apiVersion}/{$businessAccountId}/subscribed_apps";

        return $this->requestPost($url, $accessToken, $body);
    }

    /**
     * Trigger the WhatsApp Business app data sync (Coexistence flow).
     *
     * Endpoint: POST /{phone_number_id}/smb_app_data
     *
     * After the customer completes Coexistence onboarding (links the
     * WhatsApp Business app + paste verification code), the integration
     * MUST call this endpoint with `sync_type=smb_app_state_sync` to
     * persist the link, then optionally `sync_type=history` to pull chat
     * history from the WhatsApp Business app into the Cloud API inbox.
     *
     * **CRITICAL**: The sync has a HARD 24-hour deadline from the moment
     * onboarding completes. After that, the customer must offboard and
     * re-onboard to retry.
     *
     * @param string $accessToken Meta access token
     * @param string $phoneNumberId Meta Phone Number ID
     * @param string $syncType One of: `smb_app_state_sync`, `history`
     * @param string $apiVersion API version (e.g. v22.0)
     * @return array<string, mixed> Decoded response (`{success: true}` on success)
     * @throws Error
     */
    public function postSmbAppData(
        string $accessToken,
        string $phoneNumberId,
        string $syncType,
        string $apiVersion = self::DEFAULT_API_VERSION,
    ): array {
        $url = self::GRAPH_API_BASE . "/{$apiVersion}/{$phoneNumberId}/smb_app_data";

        return $this->requestPost($url, $accessToken, [
            'sync_type' => $syncType,
        ]);
    }

    /**
     * Perform an authenticated GET request to the Meta Graph API.
     *
     * @param string $url Full URL
     * @param string $accessToken Bearer token
     * @return array<string, mixed> Decoded JSON response
     * @throws Error
     */
    private function request(string $url, string $accessToken): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        return $this->handleResponse($response, $httpCode, $curlError);
    }

    /**
     * Perform an authenticated POST request to the Meta Graph API.
     *
     * Sends `application/x-www-form-urlencoded` body (Meta accepts that
     * for all subscribed_apps and smb_app_data endpoints).
     *
     * @param string $url Full URL
     * @param string $accessToken Bearer token
     * @param array<string, mixed> $body Form-encoded body params
     * @return array<string, mixed> Decoded JSON response
     * @throws Error
     */
    private function requestPost(string $url, string $accessToken, array $body): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/x-www-form-urlencoded',
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        return $this->handleResponse($response, $httpCode, $curlError);
    }

    /**
     * @param mixed $response
     * @return array<string, mixed>
     * @throws Error
     */
    private function handleResponse(mixed $response, int $httpCode, string $curlError): array
    {
        if ($curlError) {
            $this->log->error("MetaGraphApiClient: cURL error: {$curlError}");

            throw new Error("Connection to Meta Graph API failed: {$curlError}");
        }

        $data = json_decode((string) $response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $errorMessage = $this->parseApiError($data, $httpCode);

            $this->log->error("MetaGraphApiClient: API error: {$errorMessage}");

            throw new Error($errorMessage);
        }

        if (!is_array($data)) {
            throw new Error('Meta Graph API returned invalid JSON response.');
        }

        return $data;
    }

    /**
     * Parse a Meta API error response into a human-readable message.
     *
     * @param mixed $data Decoded response body
     * @param int $httpCode HTTP status code
     * @return string Error message
     */
    private function parseApiError(mixed $data, int $httpCode): string
    {
        $message = "Meta Graph API error (HTTP {$httpCode})";

        if (is_array($data) && !empty($data['error']['message'])) {
            $apiMessage = $data['error']['message'];
            $apiCode = $data['error']['code'] ?? null;

            $message = match ($apiCode) {
                190 => "Access token is invalid or expired. {$apiMessage}",
                200 => "Insufficient permissions. {$apiMessage}",
                803 => "Resource not found. {$apiMessage}",
                default => "Meta API: {$apiMessage} (code {$apiCode})",
            };
        }

        if ($httpCode === 429) {
            $message = "Meta Graph API rate limit exceeded. Please try again later.";
        }

        return $message;
    }
}
