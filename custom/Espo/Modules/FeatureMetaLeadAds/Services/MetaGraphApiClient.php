<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaLeadAds\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Log;

/**
 * HTTP client for Meta's Facebook Graph API — Lead Ads slice.
 *
 * Endpoints we use:
 *   GET  /me/accounts                  → list pages the user manages (with page tokens)
 *   GET  /{pageId}/leadgen_forms       → list lead forms on a page
 *   POST /{pageId}/subscribed_apps     → subscribe app to leadgen field
 *   GET  /{pageId}/subscribed_apps     → introspect subscription
 *   GET  /{leadgenId}                  → fetch a single lead (field_data)
 *
 * Authentication:
 *   - User-scoped operations (/me/accounts) use the OAuthAccount access_token.
 *   - Page-scoped operations use the page-specific access_token returned by
 *     /me/accounts. Each page has its own token.
 *
 * All methods throw `Error` with a human-readable message parsed from
 * Meta's `error.message`/`error.code` envelope. The caller is expected to
 * catch and surface or log.
 *
 * No state, no DI besides Log — safe to instantiate via InjectableFactory.
 */
class MetaGraphApiClient
{
    private const DEFAULT_API_VERSION = 'v21.0';
    private const GRAPH_API_BASE = 'https://graph.facebook.com';
    private const TIMEOUT_SECONDS = 15;

    /**
     * Webhook fields we subscribe to on each Page.
     * Currently only leadgen; expand later if we ingest other Page events.
     */
    public const SUBSCRIBED_FIELDS = ['leadgen'];

    public function __construct(
        private Log $log,
    ) {}

    /**
     * Fetch a single leadgen submission by id.
     *
     * Endpoint: GET /{leadgenId}?fields=id,created_time,ad_id,ad_name,
     *           adset_id,adset_name,campaign_id,campaign_name,form_id,
     *           field_data,is_organic,partner_name,platform
     *
     * @param string $pageAccessToken Page-scoped access token (long-lived).
     * @return array<string, mixed> Decoded JSON. Shape includes `field_data: [{name, values: [string]}]`.
     * @throws Error
     */
    public function fetchLeadgen(
        string $leadgenId,
        string $pageAccessToken,
        string $apiVersion = self::DEFAULT_API_VERSION,
    ): array {
        $fields = implode(',', [
            'id',
            'created_time',
            'ad_id',
            'ad_name',
            'adset_id',
            'adset_name',
            'campaign_id',
            'campaign_name',
            'form_id',
            'field_data',
            'is_organic',
            'partner_name',
            'platform',
        ]);

        $url = self::GRAPH_API_BASE . "/{$apiVersion}/{$leadgenId}"
            . '?fields=' . urlencode($fields);

        return $this->request($url, $pageAccessToken);
    }

    /**
     * List the Pages a user manages, with their per-page access tokens.
     *
     * Endpoint: GET /me/accounts?fields=id,name,access_token,tasks
     *
     * Important: this endpoint is the canonical way to obtain Page Access
     * Tokens. The `access_token` returned per item is long-lived IF the
     * user access token used here is long-lived.
     *
     * @param string $userAccessToken Long-lived user access token from OAuthAccount.
     * @return array<int, array{id: string, name: string, access_token: string, tasks?: array<int,string>}>
     * @throws Error
     */
    public function listPages(
        string $userAccessToken,
        string $apiVersion = self::DEFAULT_API_VERSION,
    ): array {
        $url = self::GRAPH_API_BASE . "/{$apiVersion}/me/accounts"
            . '?fields=' . urlencode('id,name,access_token,tasks')
            . '&limit=100';

        $response = $this->request($url, $userAccessToken);

        return $response['data'] ?? [];
    }

    /**
     * List lead forms registered on a Page.
     *
     * Endpoint: GET /{pageId}/leadgen_forms?fields=id,name,status,locale,created_time
     *
     * Requires `leads_retrieval` permission AND the page token, not the user token.
     *
     * @return array<int, array{id: string, name: string, status?: string, locale?: string, created_time?: string}>
     * @throws Error
     */
    public function listForms(
        string $pageId,
        string $pageAccessToken,
        string $apiVersion = self::DEFAULT_API_VERSION,
    ): array {
        $url = self::GRAPH_API_BASE . "/{$apiVersion}/{$pageId}/leadgen_forms"
            . '?fields=' . urlencode('id,name,status,locale,created_time')
            . '&limit=100';

        $response = $this->request($url, $pageAccessToken);

        return $response['data'] ?? [];
    }

    /**
     * Register (or update) the app-level webhook subscription.
     *
     * Endpoint: POST /{appId}/subscriptions
     *
     * This tells Meta where to deliver webhook events for the app.
     * Uses App Access Token ({appId}|{appSecret}).
     *
     * @param string $appId Meta App ID (same as clientId).
     * @param string $appSecret Meta App Secret (plaintext, already decrypted).
     * @param string $callbackUrl Full URL Meta will POST events to.
     * @param string $verifyToken Shared secret for hub.verify_token handshake.
     * @param string[] $fields Webhook fields to subscribe (e.g. ['leadgen']).
     * @return array{success: bool}
     * @throws Error
     */
    public function registerAppWebhook(
        string $appId,
        string $appSecret,
        string $callbackUrl,
        string $verifyToken,
        array $fields = ['leadgen'],
        string $apiVersion = self::DEFAULT_API_VERSION,
    ): array {
        $appAccessToken = $appId . '|' . $appSecret;

        $url = self::GRAPH_API_BASE . "/{$apiVersion}/{$appId}/subscriptions";

        $postFields = http_build_query([
            'callback_url' => $callbackUrl,
            'verify_token' => $verifyToken,
            'object'       => 'page',
            'fields'       => implode(',', $fields),
        ]);

        return $this->formPostRequest($url, $appAccessToken, $postFields);
    }

    /**
     * List current app-level webhook subscriptions.
     *
     * Endpoint: GET /{appId}/subscriptions
     *
     * @return array<int, array{object: string, callback_url: string, fields: array, active: bool}>
     * @throws Error
     */
    public function getAppWebhookSubscriptions(
        string $appId,
        string $appSecret,
        string $apiVersion = self::DEFAULT_API_VERSION,
    ): array {
        $appAccessToken = $appId . '|' . $appSecret;

        $url = self::GRAPH_API_BASE . "/{$apiVersion}/{$appId}/subscriptions";

        $response = $this->request($url, $appAccessToken);

        return $response['data'] ?? [];
    }

    /**
     * Subscribe our Meta App to webhook events on a Page.
     *
     * Endpoint: POST /{pageId}/subscribed_apps?subscribed_fields=leadgen
     *
     * Idempotent — calling multiple times is safe (Meta returns `{success: true}`).
     * Without this, Meta will NOT deliver leadgen webhooks for this page even if
     * the app subscription on the app-level webhook config includes `leadgen`.
     *
     * @return array{success: bool}
     * @throws Error
     */
    public function subscribeAppForPage(
        string $pageId,
        string $pageAccessToken,
        string $apiVersion = self::DEFAULT_API_VERSION,
    ): array {
        $url = self::GRAPH_API_BASE . "/{$apiVersion}/{$pageId}/subscribed_apps"
            . '?subscribed_fields=' . urlencode(implode(',', self::SUBSCRIBED_FIELDS));

        return $this->postRequest($url, $pageAccessToken);
    }

    /**
     * Authenticated GET via Authorization header.
     *
     * @return array<string, mixed>
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
            'Accept: application/json',
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        return $this->handleResponse($response, $httpCode, $curlError);
    }

    /**
     * Authenticated POST with empty body via Authorization header.
     *
     * @return array<string, mixed>
     * @throws Error
     */
    private function postRequest(string $url, string $accessToken): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        return $this->handleResponse($response, $httpCode, $curlError);
    }

    /**
     * @param string|false|null $response
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

        if ($httpCode !== 200) {
            $errorMessage = $this->parseApiError($data, $httpCode);

            $this->log->error("MetaGraphApiClient: API error: {$errorMessage}", [
                'httpCode' => $httpCode,
                'body'     => is_string($response) ? substr($response, 0, 500) : null,
            ]);

            throw new Error($errorMessage);
        }

        if (!is_array($data)) {
            throw new Error('Meta Graph API returned invalid JSON response.');
        }

        return $data;
    }

    /**
     * Translate a Meta error envelope into a human-readable message.
     *
     * Common Lead Ads error codes:
     *   100 — invalid parameter / lead already deleted / form deleted
     *   190 — invalid OAuth token (revoked or expired)
     *   200 — missing permission (e.g. leads_retrieval)
     *   368 — temporary block on the page
     *
     * @param mixed $data Decoded JSON body (or null).
     */
    private function parseApiError(mixed $data, int $httpCode): string
    {
        $message = "Meta Graph API error (HTTP {$httpCode})";

        if (is_array($data) && !empty($data['error']['message'])) {
            $apiMessage = (string) $data['error']['message'];
            $apiCode    = $data['error']['code'] ?? null;
            $apiSubcode = $data['error']['error_subcode'] ?? null;

            $message = match ((int) $apiCode) {
                100 => "Meta API: bad request — {$apiMessage} (code 100, subcode {$apiSubcode}). "
                    . "Lead may have been deleted, or the user revoked Lead Ads permissions.",
                190 => "Meta access token is invalid, expired, or was revoked. "
                    . "Re-authorize the Meta (Lead Ads) OAuth Account. (Original: {$apiMessage})",
                200 => "Insufficient Meta permissions. Make sure the OAuth scopes include "
                    . "`leads_retrieval`, `pages_show_list`, `pages_manage_metadata`, "
                    . "`pages_manage_ads`, and `pages_read_engagement`. (Original: {$apiMessage})",
                368 => "Meta has temporarily blocked actions on this Page. (Original: {$apiMessage})",
                default => "Meta API: {$apiMessage} (code {$apiCode})",
            };
        }

        if ($httpCode === 429) {
            $message = "Meta Graph API rate limit exceeded. Please try again in a few minutes.";
        }

        return $message;
    }
}
