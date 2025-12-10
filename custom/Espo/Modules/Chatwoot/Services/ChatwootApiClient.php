<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;

/**
 * Service for communicating with Chatwoot Platform API.
 */
class ChatwootApiClient
{
    private const DEFAULT_TIMEOUT = 30;
    private const CONNECT_TIMEOUT = 10;

    public function __construct(
        private Config $config,
        private Log $log
    ) {}

    /**
     * Create an account on Chatwoot via Platform API.
     *
     * @param string $platformUrl The base URL of the Chatwoot platform
     * @param string $accessToken The platform access token
     * @param array<string, mixed> $accountData Account data to send
     * @return array<string, mixed> Response data from Chatwoot API
     * @throws Error
     */
    public function createAccount(string $platformUrl, string $accessToken, array $accountData): array
    {
        $url = rtrim($platformUrl, '/') . '/platform/api/v1/accounts';
        
        $payload = json_encode($accountData);
        
        if ($payload === false) {
            throw new Error('Failed to encode account data to JSON.');
        }

        $headers = [
            'api_access_token: ' . $accessToken,
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ];

        $response = $this->executeRequest($url, 'POST', $payload, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $errorMsg = 'Chatwoot API error: HTTP ' . $response['code'];
            
            // Check for detailed error message in both 'message' and 'error' fields
            if (isset($response['body']['message'])) {
                $errorMsg .= ' - ' . $response['body']['message'];
            } elseif (isset($response['body']['error'])) {
                $errorMsg .= ' - ' . $response['body']['error'];
            }
            
            $this->log->error('Chatwoot API Error: ' . json_encode($response));
            throw new Error($errorMsg);
        }

        return $response['body'];
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

        $timeout = $this->config->get('chatwootApiTimeout', self::DEFAULT_TIMEOUT);
        $connectTimeout = $this->config->get('chatwootApiConnectTimeout', self::CONNECT_TIMEOUT);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($payload !== null && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        curl_close($ch);

        if ($result === false) {
            throw new Error("cURL Error: {$curlError}");
        }

        $body = json_decode($result, true);
        
        if ($body === null && json_last_error() !== JSON_ERROR_NONE) {
            $this->log->warning('Chatwoot API returned non-JSON response: ' . $result);
            $body = ['raw_response' => $result];
        }

        return [
            'code' => (int) $httpCode,
            'body' => $body ?? []
        ];
    }

    /**
     * Get account details from Chatwoot.
     *
     * @param string $platformUrl
     * @param string $accessToken
     * @param int $accountId
     * @return array<string, mixed>
     * @throws Error
     */
    public function getAccount(string $platformUrl, string $accessToken, int $accountId): array
    {
        $url = rtrim($platformUrl, '/') . '/platform/api/v1/accounts/' . $accountId;
        
        $headers = [
            'api_access_token: ' . $accessToken,
            'Content-Type: application/json'
        ];

        $response = $this->executeRequest($url, 'GET', null, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            throw new Error('Failed to get account from Chatwoot: HTTP ' . $response['code']);
        }

        return $response['body'];
    }

    /**
     * Update account on Chatwoot.
     *
     * @param string $platformUrl
     * @param string $accessToken
     * @param int $accountId
     * @param array<string, mixed> $accountData
     * @return array<string, mixed>
     * @throws Error
     */
    public function updateAccount(
        string $platformUrl,
        string $accessToken,
        int $accountId,
        array $accountData
    ): array {
        $url = rtrim($platformUrl, '/') . '/platform/api/v1/accounts/' . $accountId;
        
        $payload = json_encode($accountData);
        
        if ($payload === false) {
            throw new Error('Failed to encode account data to JSON.');
        }

        $headers = [
            'api_access_token: ' . $accessToken,
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ];

        $response = $this->executeRequest($url, 'PATCH', $payload, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            throw new Error('Failed to update account on Chatwoot: HTTP ' . $response['code']);
        }

        return $response['body'];
    }

    /**
     * Create a user on Chatwoot via Platform API.
     *
     * @param string $platformUrl The base URL of the Chatwoot platform
     * @param string $accessToken The platform access token
     * @param array<string, mixed> $userData User data to send
     * @return array<string, mixed> Response data from Chatwoot API
     * @throws Error
     */
    public function createUser(string $platformUrl, string $accessToken, array $userData): array
    {
        $url = rtrim($platformUrl, '/') . '/platform/api/v1/users';
        
        $payload = json_encode($userData);
        
        if ($payload === false) {
            throw new Error('Failed to encode user data to JSON.');
        }

        $headers = [
            'api_access_token: ' . $accessToken,
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ];

        $response = $this->executeRequest($url, 'POST', $payload, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $errorMsg = 'Chatwoot API error: HTTP ' . $response['code'];
            
            // Check for detailed error message in both 'message' and 'error' fields
            if (isset($response['body']['message'])) {
                $errorMsg .= ' - ' . $response['body']['message'];
            } elseif (isset($response['body']['error'])) {
                $errorMsg .= ' - ' . $response['body']['error'];
            }
            
            $this->log->error('Chatwoot API Error (createUser): ' . json_encode($response));
            throw new Error($errorMsg);
        }

        return $response['body'];
    }

    /**
     * Attach a user to an account on Chatwoot via Platform API.
     *
     * @param string $platformUrl The base URL of the Chatwoot platform
     * @param string $accessToken The platform access token
     * @param int $accountId The Chatwoot account ID
     * @param int $userId The Chatwoot user ID
     * @param string $role The role for the user in this account (e.g., 'agent', 'administrator')
     * @return array<string, mixed> Response data from Chatwoot API
     * @throws Error
     */
    public function attachUserToAccount(
        string $platformUrl,
        string $accessToken,
        int $accountId,
        int $userId,
        string $role = 'agent'
    ): array {
        $url = rtrim($platformUrl, '/') . '/platform/api/v1/accounts/' . $accountId . '/account_users';
        
        $payload = json_encode([
            'user_id' => $userId,
            'role' => $role
        ]);
        
        if ($payload === false) {
            throw new Error('Failed to encode account user data to JSON.');
        }

        $headers = [
            'api_access_token: ' . $accessToken,
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ];

        $response = $this->executeRequest($url, 'POST', $payload, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $errorMsg = 'Chatwoot API error: HTTP ' . $response['code'];
            
            // Check for detailed error message in both 'message' and 'error' fields
            if (isset($response['body']['message'])) {
                $errorMsg .= ' - ' . $response['body']['message'];
            } elseif (isset($response['body']['error'])) {
                $errorMsg .= ' - ' . $response['body']['error'];
            }
            
            $this->log->error('Chatwoot API Error (attachUserToAccount): ' . json_encode($response));
            throw new Error($errorMsg);
        }

        return $response['body'];
    }

    /**
     * Get user details from Chatwoot.
     *
     * @param string $platformUrl
     * @param string $accessToken
     * @param int $userId
     * @return array<string, mixed>
     * @throws Error
     */
    public function getUser(string $platformUrl, string $accessToken, int $userId): array
    {
        $url = rtrim($platformUrl, '/') . '/platform/api/v1/users/' . $userId;
        
        $headers = [
            'api_access_token: ' . $accessToken,
            'Content-Type: application/json'
        ];

        $response = $this->executeRequest($url, 'GET', null, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            throw new Error('Failed to get user from Chatwoot: HTTP ' . $response['code']);
        }

        return $response['body'];
    }

    /**
     * Get SSO login URL for a Chatwoot user.
     *
     * @param string $platformUrl
     * @param string $accessToken
     * @param int $userId
     * @return string The SSO login URL
     * @throws Error
     */
    public function getUserLoginUrl(string $platformUrl, string $accessToken, int $userId): string
    {
        $url = rtrim($platformUrl, '/') . '/platform/api/v1/users/' . $userId . '/login';
        
        $headers = [
            'api_access_token: ' . $accessToken,
            'Content-Type: application/json'
        ];

        $response = $this->executeRequest($url, 'GET', null, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $errorMsg = 'Failed to get login URL from Chatwoot: HTTP ' . $response['code'];
            
            // Check for detailed error message in both 'message' and 'error' fields
            if (isset($response['body']['message'])) {
                $errorMsg .= ' - ' . $response['body']['message'];
            } elseif (isset($response['body']['error'])) {
                $errorMsg .= ' - ' . $response['body']['error'];
            }
            
            $this->log->error('Chatwoot API Error (getUserLoginUrl): ' . json_encode($response));
            throw new Error($errorMsg);
        }

        if (!isset($response['body']['url'])) {
            throw new Error('Chatwoot API response missing login URL.');
        }

        return $response['body']['url'];
    }
}

