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
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;

/**
 * Service for communicating with WAHA (WhatsApp HTTP API).
 */
class WahaApiClient
{
    private const DEFAULT_TIMEOUT = 30;
    private const CONNECT_TIMEOUT = 10;

    public function __construct(
        private Config $config,
        private Log $log
    ) {}

    /**
     * List all sessions from WAHA.
     *
     * @param string $platformUrl The base URL of the WAHA platform
     * @param string $apiKey The API key for authentication
     * @param bool $all Return all sessions including STOPPED
     * @return array<int, array<string, mixed>> List of sessions
     * @throws Error
     */
    public function listSessions(string $platformUrl, string $apiKey, bool $all = false): array
    {
        $url = rtrim($platformUrl, '/') . '/api/sessions';
        
        if ($all) {
            $url .= '?all=true';
        }

        $headers = $this->buildHeaders($apiKey);
        $response = $this->executeRequest($url, 'GET', null, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $this->handleError('listSessions', $response);
        }

        return $response['body'];
    }

    /**
     * Get session information.
     *
     * @param string $platformUrl The base URL of the WAHA platform
     * @param string $apiKey The API key for authentication
     * @param string $sessionName The session name
     * @return array<string, mixed> Session data
     * @throws Error
     */
    public function getSession(string $platformUrl, string $apiKey, string $sessionName): array
    {
        $url = rtrim($platformUrl, '/') . '/api/sessions/' . urlencode($sessionName);

        $headers = $this->buildHeaders($apiKey);
        $response = $this->executeRequest($url, 'GET', null, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $this->handleError('getSession', $response);
        }

        return $response['body'];
    }

    /**
     * Create a new session.
     *
     * @param string $platformUrl The base URL of the WAHA platform
     * @param string $apiKey The API key for authentication
     * @param array<string, mixed> $sessionData Session configuration
     * @return array<string, mixed> Created session data
     * @throws Error
     */
    public function createSession(string $platformUrl, string $apiKey, array $sessionData): array
    {
        $url = rtrim($platformUrl, '/') . '/api/sessions';

        $payload = json_encode($sessionData);
        
        if ($payload === false) {
            throw new Error('Failed to encode session data to JSON.');
        }

        $headers = $this->buildHeaders($apiKey, strlen($payload));
        $response = $this->executeRequest($url, 'POST', $payload, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $this->handleError('createSession', $response);
        }

        return $response['body'];
    }

    /**
     * Update an existing session.
     *
     * @param string $platformUrl The base URL of the WAHA platform
     * @param string $apiKey The API key for authentication
     * @param string $sessionName The session name
     * @param array<string, mixed> $sessionData Session configuration to update
     * @return array<string, mixed> Updated session data
     * @throws Error
     */
    public function updateSession(
        string $platformUrl,
        string $apiKey,
        string $sessionName,
        array $sessionData
    ): array {
        $url = rtrim($platformUrl, '/') . '/api/sessions/' . urlencode($sessionName);

        $payload = json_encode($sessionData);
        
        if ($payload === false) {
            throw new Error('Failed to encode session data to JSON.');
        }

        $headers = $this->buildHeaders($apiKey, strlen($payload));
        $response = $this->executeRequest($url, 'PUT', $payload, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $this->handleError('updateSession', $response);
        }

        return $response['body'];
    }

    /**
     * Delete/stop a session.
     *
     * @param string $platformUrl The base URL of the WAHA platform
     * @param string $apiKey The API key for authentication
     * @param string $sessionName The session name
     * @return void
     * @throws Error
     */
    public function deleteSession(string $platformUrl, string $apiKey, string $sessionName): void
    {
        $url = rtrim($platformUrl, '/') . '/api/sessions/' . urlencode($sessionName);

        $headers = $this->buildHeaders($apiKey);
        $response = $this->executeRequest($url, 'DELETE', null, $headers);

        // Accept 200, 204 as success
        if ($response['code'] !== 200 && $response['code'] !== 204) {
            $this->handleError('deleteSession', $response);
        }
    }

    /**
     * Start a session.
     *
     * @param string $platformUrl The base URL of the WAHA platform
     * @param string $apiKey The API key for authentication
     * @param string $sessionName The session name
     * @return array<string, mixed> Session data
     * @throws Error
     */
    public function startSession(string $platformUrl, string $apiKey, string $sessionName): array
    {
        $url = rtrim($platformUrl, '/') . '/api/sessions/' . urlencode($sessionName) . '/start';

        $headers = $this->buildHeaders($apiKey);
        $response = $this->executeRequest($url, 'POST', null, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $this->handleError('startSession', $response);
        }

        return $response['body'];
    }

    /**
     * Stop a session.
     *
     * @param string $platformUrl The base URL of the WAHA platform
     * @param string $apiKey The API key for authentication
     * @param string $sessionName The session name
     * @return array<string, mixed> Session data
     * @throws Error
     */
    public function stopSession(string $platformUrl, string $apiKey, string $sessionName): array
    {
        $url = rtrim($platformUrl, '/') . '/api/sessions/' . urlencode($sessionName) . '/stop';

        $headers = $this->buildHeaders($apiKey);
        $response = $this->executeRequest($url, 'POST', null, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $this->handleError('stopSession', $response);
        }

        return $response['body'];
    }

    /**
     * Restart a session.
     *
     * @param string $platformUrl The base URL of the WAHA platform
     * @param string $apiKey The API key for authentication
     * @param string $sessionName The session name
     * @return array<string, mixed> Session data
     * @throws Error
     */
    public function restartSession(string $platformUrl, string $apiKey, string $sessionName): array
    {
        $url = rtrim($platformUrl, '/') . '/api/sessions/' . urlencode($sessionName) . '/restart';

        $headers = $this->buildHeaders($apiKey);
        $response = $this->executeRequest($url, 'POST', null, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $this->handleError('restartSession', $response);
        }

        return $response['body'];
    }

    /**
     * Logout from a session (disconnect WhatsApp account but keep session).
     *
     * @param string $platformUrl The base URL of the WAHA platform
     * @param string $apiKey The API key for authentication
     * @param string $sessionName The session name
     * @return array<string, mixed> Session data
     * @throws Error
     */
    public function logoutSession(string $platformUrl, string $apiKey, string $sessionName): array
    {
        $url = rtrim($platformUrl, '/') . '/api/sessions/' . urlencode($sessionName) . '/logout';

        $headers = $this->buildHeaders($apiKey);
        $response = $this->executeRequest($url, 'POST', null, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $this->handleError('logoutSession', $response);
        }

        return $response['body'];
    }

    /**
     * Get QR code for pairing WhatsApp.
     *
     * @param string $platformUrl The base URL of the WAHA platform
     * @param string $apiKey The API key for authentication
     * @param string $sessionName The session name
     * @param string $format Format: 'image' returns base64 PNG, 'raw' returns raw data
     * @return array{mimetype: string, data: string} QR code data
     * @throws Error
     */
    public function getQrCode(string $platformUrl, string $apiKey, string $sessionName, string $format = 'image'): array
    {
        $url = rtrim($platformUrl, '/') . '/api/' . urlencode($sessionName) . '/auth/qr?format=' . $format;

        $headers = [
            'X-Api-Key: ' . $apiKey,
            'Accept: application/json'
        ];

        $response = $this->executeRawRequest($url, 'GET', null, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $errorMsg = 'WAHA API error: HTTP ' . $response['code'];
            
            // Try to parse error from body
            $body = json_decode($response['raw_body'], true);
            if (isset($body['message'])) {
                $errorMsg .= ' - ' . $body['message'];
            } elseif (isset($body['error'])) {
                $errorMsg .= ' - ' . $body['error'];
            }
            
            $this->log->error("WAHA API Error (getQrCode): HTTP {$response['code']} - {$response['raw_body']}");
            throw new Error($errorMsg);
        }

        // Check content type
        $contentType = $response['content_type'] ?? '';

        if (strpos($contentType, 'image/png') !== false) {
            // Direct image response - encode to base64
            return [
                'mimetype' => 'image/png',
                'data' => base64_encode($response['raw_body'])
            ];
        }

        // JSON response with mimetype and data
        $body = json_decode($response['raw_body'], true);
        
        if ($body === null) {
            throw new Error('Failed to parse QR code response.');
        }

        return [
            'mimetype' => $body['mimetype'] ?? 'image/png',
            'data' => $body['data'] ?? ''
        ];
    }

    /**
     * List apps for a session.
     *
     * @param string $platformUrl The base URL of the WAHA platform
     * @param string $apiKey The API key for authentication
     * @param string $sessionName The session name to list apps for
     * @return array<int, array<string, mixed>> List of apps
     * @throws Error
     */
    public function listApps(string $platformUrl, string $apiKey, string $sessionName): array
    {
        $url = rtrim($platformUrl, '/') . '/api/apps?session=' . urlencode($sessionName);

        $headers = $this->buildHeaders($apiKey);
        $response = $this->executeRequest($url, 'GET', null, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $this->handleError('listApps', $response);
        }

        return $response['body'];
    }

    /**
     * Get app by ID.
     *
     * @param string $platformUrl The base URL of the WAHA platform
     * @param string $apiKey The API key for authentication
     * @param string $appId The app ID
     * @return array<string, mixed> App data
     * @throws Error
     */
    public function getApp(string $platformUrl, string $apiKey, string $appId): array
    {
        $url = rtrim($platformUrl, '/') . '/api/apps/' . urlencode($appId);

        $headers = $this->buildHeaders($apiKey);
        $response = $this->executeRequest($url, 'GET', null, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $this->handleError('getApp', $response);
        }

        return $response['body'];
    }

    /**
     * Create a new app.
     *
     * @param string $platformUrl The base URL of the WAHA platform
     * @param string $apiKey The API key for authentication
     * @param array<string, mixed> $appData App configuration
     * @return array<string, mixed> Created app data
     * @throws Error
     */
    public function createApp(string $platformUrl, string $apiKey, array $appData): array
    {
        $url = rtrim($platformUrl, '/') . '/api/apps';

        $payload = json_encode($appData);

        if ($payload === false) {
            throw new Error('Failed to encode app data to JSON.');
        }

        $headers = $this->buildHeaders($apiKey, strlen($payload));
        $response = $this->executeRequest($url, 'POST', $payload, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $this->handleError('createApp', $response);
        }

        return $response['body'];
    }

    /**
     * Update an existing app.
     *
     * @param string $platformUrl The base URL of the WAHA platform
     * @param string $apiKey The API key for authentication
     * @param string $appId The app ID
     * @param array<string, mixed> $appData App configuration to update
     * @return array<string, mixed> Updated app data
     * @throws Error
     */
    public function updateApp(
        string $platformUrl,
        string $apiKey,
        string $appId,
        array $appData
    ): array {
        $url = rtrim($platformUrl, '/') . '/api/apps/' . urlencode($appId);

        $payload = json_encode($appData);

        if ($payload === false) {
            throw new Error('Failed to encode app data to JSON.');
        }

        $headers = $this->buildHeaders($apiKey, strlen($payload));
        $response = $this->executeRequest($url, 'PUT', $payload, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $this->handleError('updateApp', $response);
        }

        return $response['body'];
    }

    /**
     * Delete an app.
     *
     * @param string $platformUrl The base URL of the WAHA platform
     * @param string $apiKey The API key for authentication
     * @param string $appId The app ID
     * @return void
     * @throws Error
     */
    public function deleteApp(string $platformUrl, string $apiKey, string $appId): void
    {
        $url = rtrim($platformUrl, '/') . '/api/apps/' . urlencode($appId);

        $headers = $this->buildHeaders($apiKey);
        $response = $this->executeRequest($url, 'DELETE', null, $headers);

        // Accept 200, 204 as success
        if ($response['code'] !== 200 && $response['code'] !== 204) {
            $this->handleError('deleteApp', $response);
        }
    }

    /**
     * Request authentication code (for phone number login).
     *
     * @param string $platformUrl The base URL of the WAHA platform
     * @param string $apiKey The API key for authentication
     * @param string $sessionName The session name
     * @param string $phoneNumber Phone number to request code for
     * @param string|null $method Method to receive code (null for default)
     * @return array<string, mixed> Response data
     * @throws Error
     */
    public function requestAuthCode(
        string $platformUrl,
        string $apiKey,
        string $sessionName,
        string $phoneNumber,
        ?string $method = null
    ): array {
        $url = rtrim($platformUrl, '/') . '/api/' . urlencode($sessionName) . '/auth/request-code';

        $payload = json_encode([
            'phoneNumber' => $phoneNumber,
            'method' => $method
        ]);

        if ($payload === false) {
            throw new Error('Failed to encode request data to JSON.');
        }

        $headers = $this->buildHeaders($apiKey, strlen($payload));
        $response = $this->executeRequest($url, 'POST', $payload, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $this->handleError('requestAuthCode', $response);
        }

        return $response['body'];
    }

    /**
     * List all labels for a session.
     *
     * @param string $platformUrl The base URL of the WAHA platform
     * @param string $apiKey The API key for authentication
     * @param string $sessionName The session name
     * @return array<int, array<string, mixed>> List of labels
     * @throws Error
     */
    public function listLabels(string $platformUrl, string $apiKey, string $sessionName): array
    {
        $url = rtrim($platformUrl, '/') . '/api/' . urlencode($sessionName) . '/labels';

        $headers = $this->buildHeaders($apiKey);
        $response = $this->executeRequest($url, 'GET', null, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $this->handleError('listLabels', $response);
        }

        return $response['body'];
    }

    /**
     * Create a new label.
     *
     * @param string $platformUrl The base URL of the WAHA platform
     * @param string $apiKey The API key for authentication
     * @param string $sessionName The session name
     * @param array<string, mixed> $labelData Label data (name, color or colorHex)
     * @return array<string, mixed> Created label data
     * @throws Error
     */
    public function createLabel(
        string $platformUrl,
        string $apiKey,
        string $sessionName,
        array $labelData
    ): array {
        $url = rtrim($platformUrl, '/') . '/api/' . urlencode($sessionName) . '/labels';

        $payload = json_encode($labelData);

        if ($payload === false) {
            throw new Error('Failed to encode label data to JSON.');
        }

        $headers = $this->buildHeaders($apiKey, strlen($payload));
        $response = $this->executeRequest($url, 'POST', $payload, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $this->handleError('createLabel', $response);
        }

        return $response['body'];
    }

    /**
     * Update an existing label.
     *
     * @param string $platformUrl The base URL of the WAHA platform
     * @param string $apiKey The API key for authentication
     * @param string $sessionName The session name
     * @param string $labelId The label ID
     * @param array<string, mixed> $labelData Label data to update (name, color or colorHex)
     * @return array<string, mixed> Updated label data
     * @throws Error
     */
    public function updateLabel(
        string $platformUrl,
        string $apiKey,
        string $sessionName,
        string $labelId,
        array $labelData
    ): array {
        $url = rtrim($platformUrl, '/') . '/api/' . urlencode($sessionName) . '/labels/' . urlencode($labelId);

        $payload = json_encode($labelData);

        if ($payload === false) {
            throw new Error('Failed to encode label data to JSON.');
        }

        $headers = $this->buildHeaders($apiKey, strlen($payload));
        $response = $this->executeRequest($url, 'PUT', $payload, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $this->handleError('updateLabel', $response);
        }

        return $response['body'];
    }

    /**
     * Delete a label.
     *
     * @param string $platformUrl The base URL of the WAHA platform
     * @param string $apiKey The API key for authentication
     * @param string $sessionName The session name
     * @param string $labelId The label ID
     * @return void
     * @throws Error
     */
    public function deleteLabel(
        string $platformUrl,
        string $apiKey,
        string $sessionName,
        string $labelId
    ): void {
        $url = rtrim($platformUrl, '/') . '/api/' . urlencode($sessionName) . '/labels/' . urlencode($labelId);

        $headers = $this->buildHeaders($apiKey);
        $response = $this->executeRequest($url, 'DELETE', null, $headers);

        // Accept 200, 204 as success
        if ($response['code'] !== 200 && $response['code'] !== 204) {
            $this->handleError('deleteLabel', $response);
        }
    }

    /**
     * Update labels for a chat.
     * Note: This sets the full list of labels for the chat. All other labels will be removed.
     *
     * @param string $platformUrl The base URL of the WAHA platform
     * @param string $apiKey The API key for authentication
     * @param string $sessionName The session name
     * @param string $chatId The chat ID (e.g., "5511999999999@c.us")
     * @param array<int, array{id: string}> $labels Array of label objects with 'id' key
     * @return array<string, mixed> Response data
     * @throws Error
     */
    public function updateChatLabels(
        string $platformUrl,
        string $apiKey,
        string $sessionName,
        string $chatId,
        array $labels
    ): array {
        $url = rtrim($platformUrl, '/') . '/api/' . urlencode($sessionName) . '/labels/chats/' . urlencode($chatId) . '/';

        $payload = json_encode(['labels' => $labels]);

        if ($payload === false) {
            throw new Error('Failed to encode labels data to JSON.');
        }

        $headers = $this->buildHeaders($apiKey, strlen($payload));
        $response = $this->executeRequest($url, 'PUT', $payload, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $this->handleError('updateChatLabels', $response);
        }

        return $response['body'];
    }

    /**
     * Build request headers.
     *
     * @param string $apiKey
     * @param int|null $contentLength
     * @return array<string>
     */
    private function buildHeaders(string $apiKey, ?int $contentLength = null): array
    {
        $headers = [
            'X-Api-Key: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        if ($contentLength !== null) {
            $headers[] = 'Content-Length: ' . $contentLength;
        }

        return $headers;
    }

    /**
     * Handle API error response.
     *
     * @param string $method
     * @param array{code: int, body: array<string, mixed>} $response
     * @throws Error
     */
    private function handleError(string $method, array $response): void
    {
        $errorMsg = 'WAHA API error: HTTP ' . $response['code'];

        if (isset($response['body']['message'])) {
            $errorMsg .= ' - ' . $response['body']['message'];
        } elseif (isset($response['body']['error'])) {
            $errorMsg .= ' - ' . $response['body']['error'];
        }

        $this->log->error("WAHA API Error ($method): " . json_encode($response));
        throw new Error($errorMsg);
    }

    /**
     * Execute a cURL request.
     *
     * @param string $url
     * @param string $method
     * @param string|null $payload
     * @param array<string> $headers
     * @return array{code: int, body: array<string, mixed>}
     * @throws Error
     */
    private function executeRequest(
        string $url,
        string $method,
        ?string $payload = null,
        array $headers = []
    ): array {
        $ch = curl_init($url);

        if ($ch === false) {
            throw new Error("Could not initialize cURL for URL: {$url}");
        }

        $timeout = $this->config->get('wahaApiTimeout', self::DEFAULT_TIMEOUT);
        $connectTimeout = $this->config->get('wahaApiConnectTimeout', self::CONNECT_TIMEOUT);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($payload !== null && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        curl_close($ch);

        if ($result === false) {
            throw new Error("cURL Error: {$curlError}");
        }

        // Handle empty responses
        if (empty($result) || trim($result) === '') {
            return [
                'code' => (int) $httpCode,
                'body' => []
            ];
        }

        $body = json_decode($result, true);

        if ($body === null && json_last_error() !== JSON_ERROR_NONE) {
            if ($httpCode < 200 || $httpCode >= 300) {
                $this->log->warning('WAHA API returned non-JSON response (HTTP ' . $httpCode . '): ' . $result);
            }
            $body = ['raw_response' => $result];
        }

        return [
            'code' => (int) $httpCode,
            'body' => $body ?? []
        ];
    }

    /**
     * Execute a cURL request and return raw response (for binary data like images).
     *
     * @param string $url
     * @param string $method
     * @param string|null $payload
     * @param array<string> $headers
     * @return array{code: int, raw_body: string, content_type: string}
     * @throws Error
     */
    private function executeRawRequest(
        string $url,
        string $method,
        ?string $payload = null,
        array $headers = []
    ): array {
        $ch = curl_init($url);

        if ($ch === false) {
            throw new Error("Could not initialize cURL for URL: {$url}");
        }

        $timeout = $this->config->get('wahaApiTimeout', self::DEFAULT_TIMEOUT);
        $connectTimeout = $this->config->get('wahaApiConnectTimeout', self::CONNECT_TIMEOUT);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($payload !== null && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $curlError = curl_error($ch);

        curl_close($ch);

        if ($result === false) {
            throw new Error("cURL Error: {$curlError}");
        }

        return [
            'code' => (int) $httpCode,
            'raw_body' => $result,
            'content_type' => $contentType ?: ''
        ];
    }
}

