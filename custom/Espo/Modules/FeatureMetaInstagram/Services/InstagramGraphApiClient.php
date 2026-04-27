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
use Espo\Core\Utils\Log;

/**
 * HTTP client for Meta's Instagram Graph API.
 *
 * Spans two domains:
 *  - https://api.instagram.com        — OAuth authorization (handled by EspoCRM's generic OAuth flow).
 *  - https://graph.instagram.com      — Token exchange and data fetching.
 *
 * Used for short-lived → long-lived token exchange and for discovering
 * the authenticated Instagram Business Account.
 */
class InstagramGraphApiClient
{
    private const DEFAULT_API_VERSION = 'v22.0';
    private const GRAPH_API_BASE = 'https://graph.instagram.com';
    private const TIMEOUT_SECONDS = 10;

    /**
     * Webhook fields the CRM subscribes to per IG Business Account.
     * Must match (or be a subset of) what Chatwoot's Channel::Instagram#subscribe
     * uses so the two calls are redundant-safe:
     *   messages, message_reactions, messaging_seen
     */
    public const SUBSCRIBED_FIELDS = ['messages', 'message_reactions', 'messaging_seen'];

    public function __construct(
        private Log $log,
    ) {}

    /**
     * Exchange a short-lived Instagram access token for a long-lived one (~60 days).
     *
     * Endpoint: GET https://graph.instagram.com/access_token
     *   ?grant_type=ig_exchange_token&client_secret={secret}&access_token={short_lived}
     *
     * @param string $shortLivedToken The short-lived access token from OAuth callback.
     * @param string $clientSecret Meta Instagram app client secret.
     * @return array{access_token: string, token_type: string, expires_in: int}
     * @throws Error
     */
    public function exchangeForLongLivedToken(string $shortLivedToken, string $clientSecret): array
    {
        $url = self::GRAPH_API_BASE . '/access_token'
            . '?grant_type=ig_exchange_token'
            . '&client_secret=' . urlencode($clientSecret)
            . '&access_token=' . urlencode($shortLivedToken);

        $response = $this->rawRequest($url);

        if (empty($response['access_token'])) {
            throw new Error('Instagram long-lived token exchange did not return an access_token.');
        }

        return $response;
    }

    /**
     * Fetch the authenticated user's Instagram Business Account via /me.
     *
     * Endpoint: GET https://graph.instagram.com/v22.0/me
     *   ?fields=id,username,user_id,name,profile_picture_url,account_type
     *
     * NOTE: `user_id` is the Instagram-scoped ID used in webhooks/messaging
     * (this is what gets stored as `instagram_id`). The `id` field is the
     * app-scoped user id, which is NOT used for webhooks.
     *
     * @param string $accessToken Long-lived access token.
     * @param string $apiVersion API version (e.g. v22.0)
     * @return array<string, mixed>
     * @throws Error
     */
    public function getMe(string $accessToken, string $apiVersion = self::DEFAULT_API_VERSION): array
    {
        $url = self::GRAPH_API_BASE . "/{$apiVersion}/me"
            . '?fields=id,username,user_id,name,profile_picture_url,account_type';

        return $this->request($url, $accessToken);
    }

    /**
     * Health check for an Instagram Business Account.
     *
     * Returns the HTTP status code of a GET against /{instagramId}?fields=id.
     * HTTP 200 means the token is valid and the account is reachable.
     *
     * @param string $accessToken Long-lived access token.
     * @param string $instagramId The `user_id` from /me.
     * @param string $apiVersion API version (e.g. v22.0)
     * @return int HTTP status code
     */
    public function healthCheck(
        string $accessToken,
        string $instagramId,
        string $apiVersion = self::DEFAULT_API_VERSION,
    ): int {
        $url = self::GRAPH_API_BASE . "/{$apiVersion}/{$instagramId}?fields=id";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
        ]);

        curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode;
    }

    /**
     * Subscribe the Meta App to webhook events for a given Instagram Business Account.
     *
     * Endpoint: POST https://graph.instagram.com/v22.0/{instagramId}/subscribed_apps
     *   ?subscribed_fields=messages,message_reactions,messaging_seen
     *   &access_token={token}
     *
     * This call is REQUIRED for webhook delivery. Chatwoot's
     * `Channel::Instagram#subscribe` makes the same call via `after_create_commit`,
     * but silently swallows any error via `rescue StandardError`. Calling it
     * explicitly from the CRM lets us fail activation fast with a real error
     * message (missing scope, revoked token, account not messaging-enabled, etc.).
     *
     * The underlying API is idempotent — multiple calls with the same fields
     * are safe (Meta returns `{success: true}`).
     *
     * @param string $accessToken Long-lived user access token with
     *                            `instagram_business_manage_messages` scope.
     * @param string $instagramId The Instagram business account id (`user_id` from /me).
     * @return array{success: bool} Decoded response (expected `{success: true}`).
     * @throws Error if Meta rejects the subscription.
     */
    public function subscribeApp(
        string $accessToken,
        string $instagramId,
        string $apiVersion = self::DEFAULT_API_VERSION,
    ): array {
        $url = self::GRAPH_API_BASE . "/{$apiVersion}/{$instagramId}/subscribed_apps"
            . '?subscribed_fields=' . urlencode(implode(',', self::SUBSCRIBED_FIELDS))
            . '&access_token=' . urlencode($accessToken);

        return $this->postRequest($url);
    }

    /**
     * List apps currently subscribed to webhook events for this IG account.
     *
     * Endpoint: GET https://graph.instagram.com/v22.0/{instagramId}/subscribed_apps
     *
     * Useful as a post-subscription sanity check to confirm our Meta App is
     * present in the `data` array with the expected `subscribed_fields`.
     *
     * @return array<string, mixed> Decoded JSON, shape: `{data: [{...}]}`.
     * @throws Error
     */
    public function getSubscribedApps(
        string $accessToken,
        string $instagramId,
        string $apiVersion = self::DEFAULT_API_VERSION,
    ): array {
        $url = self::GRAPH_API_BASE . "/{$apiVersion}/{$instagramId}/subscribed_apps"
            . '?access_token=' . urlencode($accessToken);

        return $this->rawRequest($url);
    }

    /**
     * Discover Instagram Business Accounts reachable via the given token.
     *
     * Instagram Login tokens expose exactly ONE Instagram Business Account
     * per token (via /me). This method returns a single-element array to
     * keep the contract list-shaped and consistent with WABA discovery.
     *
     * @param string $accessToken Long-lived access token.
     * @param string $apiVersion API version (e.g. v22.0)
     * @return array<int, array<string, mixed>> List of IG business account objects.
     * @throws Error
     */
    public function discoverBusinessAccounts(
        string $accessToken,
        string $apiVersion = self::DEFAULT_API_VERSION,
    ): array {
        $me = $this->getMe($accessToken, $apiVersion);

        // user_id is the instagram_id used for webhooks/messaging.
        if (empty($me['user_id'])) {
            return [];
        }

        return [$me];
    }

    /**
     * Perform an authenticated GET request to the Instagram Graph API.
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
     * Perform an unauthenticated GET request (for endpoints that take token as query param).
     *
     * @param string $url Full URL
     * @return array<string, mixed> Decoded JSON response
     * @throws Error
     */
    private function rawRequest(string $url): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::TIMEOUT_SECONDS);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        return $this->handleResponse($response, $httpCode, $curlError);
    }

    /**
     * Perform an unauthenticated POST request (for endpoints that take token as query param).
     *
     * @param string $url Full URL (query params carry the access token).
     * @return array<string, mixed> Decoded JSON response
     * @throws Error
     */
    private function postRequest(string $url): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::TIMEOUT_SECONDS);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        return $this->handleResponse($response, $httpCode, $curlError);
    }

    /**
     * @param string|false $response
     * @return array<string, mixed>
     * @throws Error
     */
    private function handleResponse(mixed $response, int $httpCode, string $curlError): array
    {
        if ($curlError) {
            $this->log->error("InstagramGraphApiClient: cURL error: {$curlError}");

            throw new Error("Connection to Instagram Graph API failed: {$curlError}");
        }

        $data = json_decode((string) $response, true);

        if ($httpCode !== 200) {
            $errorMessage = $this->parseApiError($data, $httpCode);

            $this->log->error("InstagramGraphApiClient: API error: {$errorMessage}");

            throw new Error($errorMessage);
        }

        if (!is_array($data)) {
            throw new Error('Instagram Graph API returned invalid JSON response.');
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
        $message = "Instagram Graph API error (HTTP {$httpCode})";

        if (is_array($data) && !empty($data['error']['message'])) {
            $apiMessage = $data['error']['message'];
            $apiCode = $data['error']['code'] ?? null;
            $apiType = $data['error']['type'] ?? null;
            $lowerMessage = strtolower((string) $apiMessage);

            // Meta returns `IGApiException code=100 "Unsupported request – method type: get"`
            // for EVERY endpoint on graph.instagram.com (including the token exchange and
            // refresh endpoints) when the IG account behind the access token is not
            // eligible for the Instagram Login API — almost always because the account
            // is still set to Personal rather than Professional (Business/Creator), or
            // the Meta App is in Development Mode and the IG user has not been added
            // as a tester. A structurally-valid IGAA… token is still issued by the
            // OAuth dialog, but no API call against it will ever succeed until the
            // underlying account config is fixed.
            if (
                (int) $apiCode === 100 &&
                $apiType === 'IGApiException' &&
                str_contains($lowerMessage, 'unsupported request')
            ) {
                return "Instagram rejected this OAuth token (Unsupported request, code 100). "
                    . "This almost always means the Instagram account is not set to a "
                    . "Professional account type. In the Instagram mobile app, open "
                    . "Settings → Account type and tools → Switch to Professional account "
                    . "(choose Business or Creator), then remove Monostax from "
                    . "Settings → Apps and Websites → Active and re-authorize. "
                    . "(If the Meta App is in Development Mode, also add this Instagram "
                    . "user as a tester in the Meta App dashboard.)";
            }

            $message = match ($apiCode) {
                190 => "Instagram access token is invalid, expired, or was revoked. "
                    . "Re-authorize the Meta (Instagram) OAuth Account. "
                    . "(Original: {$apiMessage})",
                200 => "Insufficient Instagram permissions. Make sure the OAuth scopes "
                    . "include `instagram_business_basic` and "
                    . "`instagram_business_manage_messages`. (Original: {$apiMessage})",
                803 => "Instagram resource not found. (Original: {$apiMessage})",
                default => "Instagram API: {$apiMessage} (code {$apiCode})",
            };
        }

        if ($httpCode === 429) {
            $message = "Instagram Graph API rate limit exceeded. Please try again in a few minutes.";
        }

        return $message;
    }
}
