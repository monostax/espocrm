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
use Espo\Core\Utils\Config;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateDeadline;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateFailure;

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
    public const TEMPLATE_API_VERSION = 'v23.0';
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
        private ?Config $config = null,
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
     * Discover WABAs assigned to the token (Tech Provider / Embedded Signup /
     * System User path).
     *
     * Human user tokens resolve WABAs via GET /me/businesses →
     * owned_whatsapp_business_accounts. Embedded Signup and System User
     * tokens often have empty /me/businesses but expose the shared WABAs
     * on GET /me/assigned_whatsapp_business_accounts.
     *
     * @param string $accessToken Meta access token
     * @param string $apiVersion API version (e.g. v22.0)
     * @return array<int, array<string, mixed>> List of WABA objects
     * @throws Error
     */
    public function discoverAssignedWabas(
        string $accessToken,
        string $apiVersion = self::DEFAULT_API_VERSION,
    ): array {
        $url = self::GRAPH_API_BASE . "/{$apiVersion}/me/assigned_whatsapp_business_accounts"
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
        ?string $apiVersion = null,
        ?TemplateDeadline $deadline = null,
    ): array {
        $deadline ??= new TemplateDeadline();
        $cursor = null;
        $seen = [];
        $templates = [];
        for ($page = 0; $page < 100; $page++) {
            $result = $this->getMessageTemplatePage($accessToken, $businessAccountId, $cursor, $deadline, $apiVersion);
            foreach ($result['list'] as $template) {
                if (!isset($template['id'])) {
                    throw new TemplateFailure('dependency', 'Meta returned a template without an ID.');
                }
                $templates[(string) $template['id']] = $template;
            }
            if (!$result['hasMore']) {
                return array_values($templates);
            }
            $cursor = $result['nextCursor'];
            if (isset($seen[$cursor])) {
                throw new TemplateFailure('pagination', 'Meta repeated a template cursor; catalog is incomplete.');
            }
            $seen[$cursor] = true;
        }
        throw new TemplateFailure('pagination', 'Template catalog exceeds the 100-page traversal limit.');
    }

    public function getMessageTemplatePage(
        string $token,
        string $wabaId,
        ?string $cursor = null,
        ?TemplateDeadline $deadline = null,
        ?string $version = null,
    ): array {
        $this->templateId($wabaId);
        $query = [
            'fields' => 'id,name,language,status,category,parameter_format,components',
            'limit' => 100,
        ];
        if ($cursor !== null && $cursor !== '') {
            if (strlen($cursor) > 4096) {
                throw new \Espo\Core\Exceptions\BadRequest('Invalid template cursor.');
            }
            $query['after'] = $cursor;
        }
        $data = $this->templateRequest('GET', $wabaId . '/message_templates', $token, $query, null,
            $deadline ?? new TemplateDeadline(), false, 10, $version);
        if (!isset($data['data']) || !is_array($data['data']) || !array_is_list($data['data'])) {
            throw new TemplateFailure('dependency', 'Meta returned an invalid template page.');
        }
        $more = !empty($data['paging']['next']);
        $next = $more ? ($data['paging']['cursors']['after'] ?? null) : null;
        if ($more && (!is_string($next) || $next === '' || $next === $cursor)) {
            throw new TemplateFailure('pagination', 'Meta returned an invalid or repeated cursor.');
        }
        // Only the opaque cursor is returned. Never follow paging.next URLs.
        return ['list' => $data['data'], 'nextCursor' => $next, 'hasMore' => $more];
    }

    public function getExactTemplate(
        string $token, string $wabaId, string $name, string $language, ?TemplateDeadline $deadline = null,
    ): ?array {
        foreach ($this->getMessageTemplates($token, $wabaId, null, $deadline) as $template) {
            if (($template['name'] ?? null) === $name && ($template['language'] ?? null) === $language) {
                return $template;
            }
        }
        return null;
    }

    public function createTemplate(string $token, string $wabaId, array $definition, TemplateDeadline $deadline): array
    {
        $this->templateId($wabaId);
        $result = $this->templateRequest('POST', $wabaId . '/message_templates', $token, [], $definition, $deadline, true);
        if (empty($result['id'])) {
            throw new TemplateFailure('uncertain', 'Meta response did not confirm creation. Refresh before retrying.', 504);
        }
        return $result;
    }

    public function updateTemplate(string $token, string $id, array $definition, TemplateDeadline $deadline): array
    {
        $this->templateId($id);
        return $this->confirmedMutation($this->templateRequest('POST', $id, $token, [], $definition, $deadline, true));
    }

    public function deleteTemplate(string $token, string $wabaId, string $id, string $name, TemplateDeadline $deadline): array
    {
        $this->templateId($wabaId);
        $this->templateId($id);
        return $this->confirmedMutation($this->templateRequest('DELETE', $wabaId . '/message_templates', $token,
            ['hsm_id' => $id, 'name' => $name], null, $deadline, true));
    }

    private function confirmedMutation(array $response): array
    {
        if (($response['success'] ?? false) !== true) {
            throw new TemplateFailure('uncertain', 'Meta did not confirm the operation. Refresh before retrying.', 504);
        }
        return $response;
    }

    public function uploadTemplateSample(string $token, string $appId, array $sample, TemplateDeadline $deadline): string
    {
        if (!preg_match('/^[0-9]+$/D', $appId)) {
            throw new TemplateFailure('upload', 'The linked OAuth provider needs a Meta app ID for sample uploads.', 422);
        }
        $extension = match ($sample['mime']) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'video/mp4' => 'mp4', default => 'pdf',
        };
        $session = $this->templateRequest('POST', $appId . '/uploads', $token, [
            'file_name' => 'sample.' . $extension,
            'file_length' => $sample['size'],
            'file_type' => $sample['mime'],
        ], null, $deadline);
        $id = $session['id'] ?? '';
        if (!is_string($id) || !str_starts_with($id, 'upload:')) {
            throw new TemplateFailure('upload', 'Meta could not start a sample upload. Check the linked app/token permissions.');
        }
        $bytes = file_get_contents($sample['path']);
        if ($bytes === false) {
            throw new TemplateFailure('upload', 'Could not read the temporary sample.');
        }
        // Some Meta sessions include a signed query. Preserve only that query on the fixed host.
        [$sessionId, $signature] = array_pad(explode('?', $id, 2), 2, '');
        parse_str($signature, $sessionQuery);
        if (array_diff(array_keys($sessionQuery), ['sig']) ||
            (isset($sessionQuery['sig']) && !is_string($sessionQuery['sig']))) {
            throw new TemplateFailure('upload', 'Meta returned an invalid upload session.');
        }
        $result = $this->templateRequest('POST', 'upload:' . rawurlencode(substr($sessionId, 7)),
            $token, $sessionQuery, $bytes, $deadline, false, 60);
        if (!is_string($result['h'] ?? null) || $result['h'] === '') {
            throw new TemplateFailure('upload', 'Meta did not return a sample handle. Check the linked app/token permissions.');
        }
        return $result['h'];
    }

    private function templateId(string $id): void
    {
        if (!preg_match('/^[0-9]+$/D', $id)) {
            throw new \Espo\Core\Exceptions\BadRequest('Invalid Meta resource ID.');
        }
    }

    /** Single attempt; a timed-out mutation may already have taken effect at Meta. */
    protected function templateRequest(
        string $method, string $path, string $token, array $query, array|string|null $body,
        TemplateDeadline $deadline, bool $mutation = false, int $callLimit = 10, ?string $version = null,
    ): array {
        $version ??= $this->config?->get('whatsAppTemplateGraphApiVersion') ?? self::TEMPLATE_API_VERSION;
        if (!preg_match('/^v[0-9]+\.[0-9]+$/D', $version)) {
            throw new TemplateFailure('configuration', 'Invalid template Graph API version configuration.');
        }
        $timeout = $deadline->milliseconds($callLimit);
        $url = self::GRAPH_API_BASE . '/' . $version . '/' . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }
        $binary = is_string($body);
        $headers = ['Authorization: ' . ($binary ? 'OAuth ' : 'Bearer ') . $token];
        if ($binary) {
            $headers[] = 'file_offset: 0';
            $headers[] = 'Content-Type: application/octet-stream';
        } elseif ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        [$raw, $status] = $this->templateHttpRequest($url, $method, $headers,
            $body === null ? null : ($binary ? $body : json_encode($body, JSON_THROW_ON_ERROR)), $timeout);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if ($raw === false || !is_array($data) || $status >= 500) {
            throw new TemplateFailure($mutation ? 'uncertain' : 'dependency', $mutation
                ? 'Meta outcome is unknown. Refresh the catalog before retrying.'
                : 'Meta could not complete the request. Check the connection and try again.', $mutation ? 504 : 502);
        }
        if ($status < 200 || $status >= 300 || isset($data['error'])) {
            $code = isset($data['error']['code']) ? (int) $data['error']['code'] : null;
            $subcode = isset($data['error']['error_subcode']) ? (int) $data['error']['error_subcode'] : null;
            [$kind, $http, $message] = match (true) {
                $status === 429, in_array($code, [4, 17, 32, 613, 80007], true) =>
                    ['rateLimit', 429, 'Meta rate limit reached. Try again later.'],
                in_array($code, [10, 190, 200], true), $status === 403 =>
                    ['access', 403, 'Meta refused access. Check the linked app/token and permissions.'],
                $status === 404, $code === 803 => ['missing', 404, 'Meta resource not found. Refresh the catalog.'],
                $status === 409, $subcode === 2388024 => ['conflict', 409, 'Template already exists. Refresh the catalog.'],
                default => ['validation', 422, 'Meta refused the template operation. Check the definition, status and edit restrictions in Meta Manager.'],
            };
            throw new TemplateFailure($kind, $message, $http, $code, $subcode);
        }
        return $data;
    }

    /** Transport seam for deterministic Meta integration tests. No retries or redirects. */
    protected function templateHttpRequest(string $url, string $method, array $headers, ?string $body, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT_MS => $timeout,
            CURLOPT_CONNECTTIMEOUT_MS => min(10000, $timeout),
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$raw, $status];
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
        ?string $apiVersion = null,
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

        // Meta requires JSON here (form-urlencoded returns code 100 "Failed to
        // parse the request body"). `messaging_product` is a required field.
        return $this->requestPostJson($url, $accessToken, [
            'messaging_product' => 'whatsapp',
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
     * Authenticated JSON POST (used by smb_app_data which rejects form bodies).
     *
     * @param string $url Full URL
     * @param string $accessToken Bearer token
     * @param array<string, mixed> $body JSON body
     * @return array<string, mixed> Decoded JSON response
     * @throws Error
     */
    private function requestPostJson(string $url, string $accessToken, array $body): array
    {
        $payload = json_encode($body);

        if ($payload === false) {
            throw new Error('Failed to encode Meta Graph API JSON body.');
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
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
