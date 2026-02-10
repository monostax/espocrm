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
 * phone numbers, and business discovery from the Meta Graph API.
 */
class MetaGraphApiClient
{
    private const DEFAULT_API_VERSION = 'v21.0';
    private const GRAPH_API_BASE = 'https://graph.facebook.com';
    private const TIMEOUT_SECONDS = 10;

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

        if ($curlError) {
            $this->log->error("MetaGraphApiClient: cURL error: {$curlError}");

            throw new Error("Connection to Meta Graph API failed: {$curlError}");
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200) {
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
