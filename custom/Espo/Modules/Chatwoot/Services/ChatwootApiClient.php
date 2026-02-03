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

        // Handle empty responses (common for DELETE requests with 204 No Content)
        if (empty($result) || trim($result) === '') {
            return [
                'code' => (int) $httpCode,
                'body' => []
            ];
        }

        $body = json_decode($result, true);
        
        if ($body === null && json_last_error() !== JSON_ERROR_NONE) {
            // Only log warning if it's not an empty response and not a successful status
            if ($httpCode < 200 || $httpCode >= 300) {
                $this->log->warning('Chatwoot API returned non-JSON response (HTTP ' . $httpCode . '): ' . $result);
            }
            $body = ['raw_response' => $result];
        }

        return [
            'code' => (int) $httpCode,
            'body' => $body ?? []
        ];
    }

    /**
     * Delete an account from Chatwoot via Platform API.
     *
     * @param string $platformUrl
     * @param string $accessToken
     * @param int $accountId
     * @return void
     * @throws Error
     */
    public function deleteAccount(string $platformUrl, string $accessToken, int $accountId): void
    {
        $url = rtrim($platformUrl, '/') . '/platform/api/v1/accounts/' . $accountId;
        
        $headers = [
            'api_access_token: ' . $accessToken,
            'Content-Type: application/json'
        ];

        $response = $this->executeRequest($url, 'DELETE', null, $headers);

        // Accept 204 No Content or 200 OK as success
        if ($response['code'] !== 200 && $response['code'] !== 204) {
            $errorMsg = 'Failed to delete account from Chatwoot: HTTP ' . $response['code'];
            
            if (isset($response['body']['message'])) {
                $errorMsg .= ' - ' . $response['body']['message'];
            } elseif (isset($response['body']['error'])) {
                $errorMsg .= ' - ' . $response['body']['error'];
            }
            
            $this->log->error('Chatwoot API Error (deleteAccount): ' . json_encode($response));
            throw new Error($errorMsg);
        }
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
     * Detach a user from an account on Chatwoot via Platform API.
     * According to Chatwoot API docs, this is a DELETE request with user_id in the body.
     *
     * @param string $platformUrl The base URL of the Chatwoot platform
     * @param string $accessToken The platform access token
     * @param int $accountId The Chatwoot account ID
     * @param int $userId The Chatwoot user ID
     * @return void
     * @throws Error
     */
    public function detachUserFromAccount(
        string $platformUrl,
        string $accessToken,
        int $accountId,
        int $userId
    ): void {
        $url = rtrim($platformUrl, '/') . '/platform/api/v1/accounts/' . $accountId . '/account_users';
        
        $payload = json_encode([
            'user_id' => $userId
        ]);
        
        if ($payload === false) {
            throw new Error('Failed to encode account user data to JSON.');
        }

        $headers = [
            'api_access_token: ' . $accessToken,
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ];

        $response = $this->executeRequest($url, 'DELETE', $payload, $headers);

        // Accept 204 No Content, 200 OK, or 404 (already detached) as success
        if ($response['code'] !== 200 && $response['code'] !== 204 && $response['code'] !== 404) {
            $errorMsg = 'Failed to detach user from account on Chatwoot: HTTP ' . $response['code'];
            
            if (isset($response['body']['message'])) {
                $errorMsg .= ' - ' . $response['body']['message'];
            } elseif (isset($response['body']['error'])) {
                $errorMsg .= ' - ' . $response['body']['error'];
            }
            
            $this->log->error('Chatwoot API Error (detachUserFromAccount): ' . json_encode($response));
            throw new Error($errorMsg);
        }
        
        // Log if user was already detached
        if ($response['code'] === 404) {
            $this->log->info("User $userId was already detached from account $accountId or doesn't exist");
        }
    }

    /**
     * Delete a user from Chatwoot via Platform API.
     *
     * @param string $platformUrl The base URL of the Chatwoot platform
     * @param string $accessToken The platform access token
     * @param int $userId The Chatwoot user ID
     * @return void
     * @throws Error
     */
    public function deleteUser(string $platformUrl, string $accessToken, int $userId): void
    {
        $url = rtrim($platformUrl, '/') . '/platform/api/v1/users/' . $userId;
        
        $headers = [
            'api_access_token: ' . $accessToken,
            'Content-Type: application/json'
        ];

        $response = $this->executeRequest($url, 'DELETE', null, $headers);

        // Accept 204 No Content or 200 OK as success
        if ($response['code'] !== 200 && $response['code'] !== 204) {
            $errorMsg = 'Failed to delete user from Chatwoot: HTTP ' . $response['code'];
            
            if (isset($response['body']['message'])) {
                $errorMsg .= ' - ' . $response['body']['message'];
            } elseif (isset($response['body']['error'])) {
                $errorMsg .= ' - ' . $response['body']['error'];
            }
            
            $this->log->error('Chatwoot API Error (deleteUser): ' . json_encode($response));
            throw new Error($errorMsg);
        }
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

    // ========================================================================
    // Contact API Methods (Account-level API)
    // ========================================================================

    /**
     * Create a contact on Chatwoot via Account API.
     *
     * @param string $platformUrl The base URL of the Chatwoot platform
     * @param string $accountApiKey The account-level API key
     * @param int $accountId The Chatwoot account ID
     * @param array<string, mixed> $contactData Contact data to send
     * @return array<string, mixed> Response data from Chatwoot API
     * @throws Error
     */
    public function createContact(
        string $platformUrl,
        string $accountApiKey,
        int $accountId,
        array $contactData
    ): array {
        // Validate required field
        if (!isset($contactData['inbox_id'])) {
            throw new Error('inbox_id is required to create a contact in Chatwoot.');
        }
        
        $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/contacts';
        
        $payload = json_encode($contactData);
        
        if ($payload === false) {
            throw new Error('Failed to encode contact data to JSON.');
        }

        $headers = [
            'api_access_token: ' . $accountApiKey,
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ];

        $response = $this->executeRequest($url, 'POST', $payload, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $errorMsg = 'Chatwoot API error: HTTP ' . $response['code'];
            
            if (isset($response['body']['message'])) {
                $errorMsg .= ' - ' . $response['body']['message'];
            } elseif (isset($response['body']['error'])) {
                $errorMsg .= ' - ' . $response['body']['error'];
            }
            
            $this->log->error('Chatwoot API Error (createContact): ' . json_encode($response));
            throw new Error($errorMsg);
        }

        return $response['body'];
    }

    /**
     * Update a contact on Chatwoot via Account API.
     *
     * @param string $platformUrl The base URL of the Chatwoot platform
     * @param string $accountApiKey The account-level API key
     * @param int $accountId The Chatwoot account ID
     * @param int $contactId The Chatwoot contact ID
     * @param array<string, mixed> $contactData Contact data to update
     * @return array<string, mixed> Response data from Chatwoot API
     * @throws Error
     */
    public function updateContact(
        string $platformUrl,
        string $accountApiKey,
        int $accountId,
        int $contactId,
        array $contactData
    ): array {
        $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/contacts/' . $contactId;
        
        $payload = json_encode($contactData);
        
        if ($payload === false) {
            throw new Error('Failed to encode contact data to JSON.');
        }

        $headers = [
            'api_access_token: ' . $accountApiKey,
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ];

        $response = $this->executeRequest($url, 'PATCH', $payload, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $errorMsg = 'Chatwoot API error: HTTP ' . $response['code'];
            
            if (isset($response['body']['message'])) {
                $errorMsg .= ' - ' . $response['body']['message'];
            } elseif (isset($response['body']['error'])) {
                $errorMsg .= ' - ' . $response['body']['error'];
            }
            
            $this->log->error('Chatwoot API Error (updateContact): ' . json_encode($response));
            throw new Error($errorMsg);
        }

        return $response['body'];
    }

    /**
     * Get contact details from Chatwoot via Account API.
     *
     * @param string $platformUrl The base URL of the Chatwoot platform
     * @param string $accountApiKey The account-level API key
     * @param int $accountId The Chatwoot account ID
     * @param int $contactId The Chatwoot contact ID
     * @return array<string, mixed> Contact data
     * @throws Error
     */
    public function getContact(
        string $platformUrl,
        string $accountApiKey,
        int $accountId,
        int $contactId
    ): array {
        $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/contacts/' . $contactId;
        
        $headers = [
            'api_access_token: ' . $accountApiKey,
            'Content-Type: application/json'
        ];

        $response = $this->executeRequest($url, 'GET', null, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $errorMsg = 'Failed to get contact from Chatwoot: HTTP ' . $response['code'];
            
            if (isset($response['body']['message'])) {
                $errorMsg .= ' - ' . $response['body']['message'];
            } elseif (isset($response['body']['error'])) {
                $errorMsg .= ' - ' . $response['body']['error'];
            }
            
            $this->log->error('Chatwoot API Error (getContact): ' . json_encode($response));
            throw new Error($errorMsg);
        }

        return $response['body'];
    }

    /**
     * Search for contacts on Chatwoot via Account API.
     *
     * @param string $platformUrl The base URL of the Chatwoot platform
     * @param string $accountApiKey The account-level API key
     * @param int $accountId The Chatwoot account ID
     * @param string $query Search query (email, phone, or name)
     * @return array<string, mixed> Search results with 'payload' containing contacts
     * @throws Error
     */
    public function searchContacts(
        string $platformUrl,
        string $accountApiKey,
        int $accountId,
        string $query
    ): array {
        $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/contacts/search';
        $url .= '?q=' . urlencode($query);
        
        $headers = [
            'api_access_token: ' . $accountApiKey,
            'Content-Type: application/json'
        ];

        $response = $this->executeRequest($url, 'GET', null, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $errorMsg = 'Failed to search contacts on Chatwoot: HTTP ' . $response['code'];
            
            if (isset($response['body']['message'])) {
                $errorMsg .= ' - ' . $response['body']['message'];
            } elseif (isset($response['body']['error'])) {
                $errorMsg .= ' - ' . $response['body']['error'];
            }
            
            $this->log->error('Chatwoot API Error (searchContacts): ' . json_encode($response));
            throw new Error($errorMsg);
        }

        return $response['body'];
    }

    /**
     * Search for a contact by phone number.
     *
     * @param string $platformUrl The base URL of the Chatwoot platform
     * @param string $accountApiKey The account-level API key
     * @param int $accountId The Chatwoot account ID
     * @param string $phoneNumber The phone number to search for
     * @return array|null Contact data if found, null otherwise
     * @throws Error
     */
    public function searchContactByPhone(
        string $platformUrl,
        string $accountApiKey,
        int $accountId,
        string $phoneNumber
    ): ?array {
        $results = $this->searchContacts($platformUrl, $accountApiKey, $accountId, $phoneNumber);
        
        // Check different response structures
        $contacts = [];
        if (isset($results['payload'])) {
            $contacts = $results['payload'];
        } elseif (isset($results['contacts'])) {
            $contacts = $results['contacts'];
        } elseif (is_array($results) && isset($results[0])) {
            $contacts = $results;
        }
        
        // Find exact phone number match
        foreach ($contacts as $contact) {
            if (isset($contact['phone_number']) && $contact['phone_number'] === $phoneNumber) {
                return $contact;
            }
        }
        
        // If no exact match, return first result if it exists
        return !empty($contacts) ? $contacts[0] : null;
    }

    /* -------------------------------------------------------------------------- */
    /*                    Team API Methods (Account-level API)                    */
    /* -------------------------------------------------------------------------- */

    /**
     * List all teams in a Chatwoot account.
     *
     * @param string $platformUrl The base URL of the Chatwoot platform
     * @param string $accountApiKey The account-level API key
     * @param int $accountId The Chatwoot account ID
     * @return array<int, array<string, mixed>> List of teams
     * @throws Error
     */
    public function listTeams(
        string $platformUrl,
        string $accountApiKey,
        int $accountId
    ): array {
        $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/teams';

        $headers = [
            'api_access_token: ' . $accountApiKey,
            'Content-Type: application/json'
        ];

        $response = $this->executeRequest($url, 'GET', null, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $errorMsg = 'Failed to list teams from Chatwoot: HTTP ' . $response['code'];

            if (isset($response['body']['message'])) {
                $errorMsg .= ' - ' . $response['body']['message'];
            } elseif (isset($response['body']['error'])) {
                $errorMsg .= ' - ' . $response['body']['error'];
            }

            $this->log->error('Chatwoot API Error (listTeams): ' . json_encode($response));
            throw new Error($errorMsg);
        }

        return $response['body'];
    }

    /**
     * Create a team in a Chatwoot account.
     *
     * @param string $platformUrl The base URL of the Chatwoot platform
     * @param string $accountApiKey The account-level API key
     * @param int $accountId The Chatwoot account ID
     * @param array<string, mixed> $teamData Team data (name, description, allow_auto_assign)
     * @return array<string, mixed> Response data from Chatwoot API
     * @throws Error
     */
    public function createTeam(
        string $platformUrl,
        string $accountApiKey,
        int $accountId,
        array $teamData
    ): array {
        $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/teams';
        
        $payload = json_encode($teamData);
        
        if ($payload === false) {
            throw new Error('Failed to encode team data to JSON.');
        }

        $headers = [
            'api_access_token: ' . $accountApiKey,
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ];

        $response = $this->executeRequest($url, 'POST', $payload, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $errorMsg = 'Chatwoot API error: HTTP ' . $response['code'];
            
            if (isset($response['body']['message'])) {
                $errorMsg .= ' - ' . $response['body']['message'];
            } elseif (isset($response['body']['error'])) {
                $errorMsg .= ' - ' . $response['body']['error'];
            }
            
            $this->log->error('Chatwoot API Error (createTeam): ' . json_encode($response));
            throw new Error($errorMsg);
        }

        return $response['body'];
    }

    /**
     * Get team details from Chatwoot.
     *
     * @param string $platformUrl The base URL of the Chatwoot platform
     * @param string $accountApiKey The account-level API key
     * @param int $accountId The Chatwoot account ID
     * @param int $teamId The Chatwoot team ID
     * @return array<string, mixed> Team data
     * @throws Error
     */
    public function getTeam(
        string $platformUrl,
        string $accountApiKey,
        int $accountId,
        int $teamId
    ): array {
        $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/teams/' . $teamId;
        
        $headers = [
            'api_access_token: ' . $accountApiKey,
            'Content-Type: application/json'
        ];

        $response = $this->executeRequest($url, 'GET', null, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $errorMsg = 'Failed to get team from Chatwoot: HTTP ' . $response['code'];
            
            if (isset($response['body']['message'])) {
                $errorMsg .= ' - ' . $response['body']['message'];
            } elseif (isset($response['body']['error'])) {
                $errorMsg .= ' - ' . $response['body']['error'];
            }
            
            $this->log->error('Chatwoot API Error (getTeam): ' . json_encode($response));
            throw new Error($errorMsg);
        }

        return $response['body'];
    }

    /**
     * Update a team in a Chatwoot account.
     *
     * @param string $platformUrl The base URL of the Chatwoot platform
     * @param string $accountApiKey The account-level API key
     * @param int $accountId The Chatwoot account ID
     * @param int $teamId The Chatwoot team ID
     * @param array<string, mixed> $teamData Team data to update
     * @return array<string, mixed> Response data from Chatwoot API
     * @throws Error
     */
    public function updateTeam(
        string $platformUrl,
        string $accountApiKey,
        int $accountId,
        int $teamId,
        array $teamData
    ): array {
        $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/teams/' . $teamId;
        
        $payload = json_encode($teamData);
        
        if ($payload === false) {
            throw new Error('Failed to encode team data to JSON.');
        }

        $headers = [
            'api_access_token: ' . $accountApiKey,
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ];

        $response = $this->executeRequest($url, 'PATCH', $payload, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $errorMsg = 'Chatwoot API error: HTTP ' . $response['code'];
            
            if (isset($response['body']['message'])) {
                $errorMsg .= ' - ' . $response['body']['message'];
            } elseif (isset($response['body']['error'])) {
                $errorMsg .= ' - ' . $response['body']['error'];
            }
            
            $this->log->error('Chatwoot API Error (updateTeam): ' . json_encode($response));
            throw new Error($errorMsg);
        }

        return $response['body'];
    }

    /**
     * Delete a team from a Chatwoot account.
     *
     * @param string $platformUrl The base URL of the Chatwoot platform
     * @param string $accountApiKey The account-level API key
     * @param int $accountId The Chatwoot account ID
     * @param int $teamId The Chatwoot team ID
     * @return void
     * @throws Error
     */
    public function deleteTeam(
        string $platformUrl,
        string $accountApiKey,
        int $accountId,
        int $teamId
    ): void {
        $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/teams/' . $teamId;
        
        $headers = [
            'api_access_token: ' . $accountApiKey,
            'Content-Type: application/json'
        ];

        $response = $this->executeRequest($url, 'DELETE', null, $headers);

        // Accept 204 No Content, 200 OK, or 404 (already deleted) as success
        if ($response['code'] !== 200 && $response['code'] !== 204 && $response['code'] !== 404) {
            $errorMsg = 'Failed to delete team from Chatwoot: HTTP ' . $response['code'];
            
            if (isset($response['body']['message'])) {
                $errorMsg .= ' - ' . $response['body']['message'];
            } elseif (isset($response['body']['error'])) {
                $errorMsg .= ' - ' . $response['body']['error'];
            }
            
            $this->log->error('Chatwoot API Error (deleteTeam): ' . json_encode($response));
            throw new Error($errorMsg);
        }
        
        // Log if team was already deleted
        if ($response['code'] === 404) {
            $this->log->info("Team $teamId was already deleted from account $accountId or doesn't exist");
        }
    }

    /**
     * List all members of a team.
     *
     * @param string $platformUrl The base URL of the Chatwoot platform
     * @param string $accountApiKey The account-level API key
     * @param int $accountId The Chatwoot account ID
     * @param int $teamId The Chatwoot team ID
     * @return array<int, array<string, mixed>> List of team members
     * @throws Error
     */
    public function listTeamMembers(
        string $platformUrl,
        string $accountApiKey,
        int $accountId,
        int $teamId
    ): array {
        $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/teams/' . $teamId . '/team_members';

        $headers = [
            'api_access_token: ' . $accountApiKey,
            'Content-Type: application/json'
        ];

        $response = $this->executeRequest($url, 'GET', null, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $errorMsg = 'Failed to list team members from Chatwoot: HTTP ' . $response['code'];

            if (isset($response['body']['message'])) {
                $errorMsg .= ' - ' . $response['body']['message'];
            } elseif (isset($response['body']['error'])) {
                $errorMsg .= ' - ' . $response['body']['error'];
            }

            $this->log->error('Chatwoot API Error (listTeamMembers): ' . json_encode($response));
            throw new Error($errorMsg);
        }

        return $response['body'];
    }

    /**
     * Add agents to a team.
     *
     * @param string $platformUrl The base URL of the Chatwoot platform
     * @param string $accountApiKey The account-level API key
     * @param int $accountId The Chatwoot account ID
     * @param int $teamId The Chatwoot team ID
     * @param array<int> $userIds Array of Chatwoot user IDs to add
     * @return array<int, array<string, mixed>> List of added team members
     * @throws Error
     */
    public function addTeamMembers(
        string $platformUrl,
        string $accountApiKey,
        int $accountId,
        int $teamId,
        array $userIds
    ): array {
        $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/teams/' . $teamId . '/team_members';

        $payload = json_encode(['user_ids' => $userIds]);

        if ($payload === false) {
            throw new Error('Failed to encode user IDs to JSON.');
        }

        $headers = [
            'api_access_token: ' . $accountApiKey,
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ];

        $response = $this->executeRequest($url, 'POST', $payload, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $errorMsg = 'Failed to add team members in Chatwoot: HTTP ' . $response['code'];

            if (isset($response['body']['message'])) {
                $errorMsg .= ' - ' . $response['body']['message'];
            } elseif (isset($response['body']['error'])) {
                $errorMsg .= ' - ' . $response['body']['error'];
            }

            $this->log->error('Chatwoot API Error (addTeamMembers): ' . json_encode($response));
            throw new Error($errorMsg);
        }

        return $response['body'];
    }

    /**
     * Remove agents from a team.
     *
     * @param string $platformUrl The base URL of the Chatwoot platform
     * @param string $accountApiKey The account-level API key
     * @param int $accountId The Chatwoot account ID
     * @param int $teamId The Chatwoot team ID
     * @param array<int> $userIds Array of Chatwoot user IDs to remove
     * @return void
     * @throws Error
     */
    public function removeTeamMembers(
        string $platformUrl,
        string $accountApiKey,
        int $accountId,
        int $teamId,
        array $userIds
    ): void {
        $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/teams/' . $teamId . '/team_members';

        $payload = json_encode(['user_ids' => $userIds]);

        if ($payload === false) {
            throw new Error('Failed to encode user IDs to JSON.');
        }

        $headers = [
            'api_access_token: ' . $accountApiKey,
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ];

        $response = $this->executeRequest($url, 'DELETE', $payload, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $errorMsg = 'Failed to remove team members from Chatwoot: HTTP ' . $response['code'];

            if (isset($response['body']['message'])) {
                $errorMsg .= ' - ' . $response['body']['message'];
            } elseif (isset($response['body']['error'])) {
                $errorMsg .= ' - ' . $response['body']['error'];
            }

            $this->log->error('Chatwoot API Error (removeTeamMembers): ' . json_encode($response));
            throw new Error($errorMsg);
        }
    }

    /* -------------------------------------------------------------------------- */
    /*                   Webhook API Methods (Account-level API)                  */
    /* -------------------------------------------------------------------------- */

    /**
     * List all webhooks in a Chatwoot account.
     *
     * @param string $platformUrl The base URL of the Chatwoot platform
     * @param string $accountApiKey The account-level API key
     * @param int $accountId The Chatwoot account ID
     * @return array<int, array<string, mixed>> List of webhooks
     * @throws Error
     */
    public function listWebhooks(
        string $platformUrl,
        string $accountApiKey,
        int $accountId
    ): array {
        $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/webhooks';
        
        $headers = [
            'api_access_token: ' . $accountApiKey,
            'Content-Type: application/json'
        ];

        $response = $this->executeRequest($url, 'GET', null, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $errorMsg = 'Failed to list webhooks from Chatwoot: HTTP ' . $response['code'];
            
            if (isset($response['body']['message'])) {
                $errorMsg .= ' - ' . $response['body']['message'];
            } elseif (isset($response['body']['error'])) {
                $errorMsg .= ' - ' . $response['body']['error'];
            }
            
            $this->log->error('Chatwoot API Error (listWebhooks): ' . json_encode($response));
            throw new Error($errorMsg);
        }

        return $response['body'];
    }

    /**
     * Create a webhook in a Chatwoot account.
     *
     * @param string $platformUrl The base URL of the Chatwoot platform
     * @param string $accountApiKey The account-level API key
     * @param int $accountId The Chatwoot account ID
     * @param array<string, mixed> $webhookData Webhook data (url, name, subscriptions)
     * @return array<string, mixed> Response data from Chatwoot API
     * @throws Error
     */
    public function createWebhook(
        string $platformUrl,
        string $accountApiKey,
        int $accountId,
        array $webhookData
    ): array {
        $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/webhooks';
        
        $payload = json_encode($webhookData);
        
        if ($payload === false) {
            throw new Error('Failed to encode webhook data to JSON.');
        }

        $headers = [
            'api_access_token: ' . $accountApiKey,
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ];

        $response = $this->executeRequest($url, 'POST', $payload, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $errorMsg = 'Chatwoot API error: HTTP ' . $response['code'];
            
            if (isset($response['body']['message'])) {
                $errorMsg .= ' - ' . $response['body']['message'];
            } elseif (isset($response['body']['error'])) {
                $errorMsg .= ' - ' . $response['body']['error'];
            }
            
            $this->log->error('Chatwoot API Error (createWebhook): ' . json_encode($response));
            throw new Error($errorMsg);
        }

        return $response['body'];
    }

    /**
     * Update a webhook in a Chatwoot account.
     *
     * @param string $platformUrl The base URL of the Chatwoot platform
     * @param string $accountApiKey The account-level API key
     * @param int $accountId The Chatwoot account ID
     * @param int $webhookId The Chatwoot webhook ID
     * @param array<string, mixed> $webhookData Webhook data to update
     * @return array<string, mixed> Response data from Chatwoot API
     * @throws Error
     */
    public function updateWebhook(
        string $platformUrl,
        string $accountApiKey,
        int $accountId,
        int $webhookId,
        array $webhookData
    ): array {
        $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/webhooks/' . $webhookId;
        
        $payload = json_encode($webhookData);
        
        if ($payload === false) {
            throw new Error('Failed to encode webhook data to JSON.');
        }

        $headers = [
            'api_access_token: ' . $accountApiKey,
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ];

        $response = $this->executeRequest($url, 'PATCH', $payload, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $errorMsg = 'Chatwoot API error: HTTP ' . $response['code'];
            
            if (isset($response['body']['message'])) {
                $errorMsg .= ' - ' . $response['body']['message'];
            } elseif (isset($response['body']['error'])) {
                $errorMsg .= ' - ' . $response['body']['error'];
            }
            
            $this->log->error('Chatwoot API Error (updateWebhook): ' . json_encode($response));
            throw new Error($errorMsg);
        }

        return $response['body'];
    }

    /**
     * Delete a webhook from a Chatwoot account.
     *
     * @param string $platformUrl The base URL of the Chatwoot platform
     * @param string $accountApiKey The account-level API key
     * @param int $accountId The Chatwoot account ID
     * @param int $webhookId The Chatwoot webhook ID
     * @return void
     * @throws Error
     */
    public function deleteWebhook(
        string $platformUrl,
        string $accountApiKey,
        int $accountId,
        int $webhookId
    ): void {
        $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/webhooks/' . $webhookId;
        
        $headers = [
            'api_access_token: ' . $accountApiKey,
            'Content-Type: application/json'
        ];

        $response = $this->executeRequest($url, 'DELETE', null, $headers);

        // Accept 204 No Content, 200 OK, or 404 (already deleted) as success
        if ($response['code'] !== 200 && $response['code'] !== 204 && $response['code'] !== 404) {
            $errorMsg = 'Failed to delete webhook from Chatwoot: HTTP ' . $response['code'];
            
            if (isset($response['body']['message'])) {
                $errorMsg .= ' - ' . $response['body']['message'];
            } elseif (isset($response['body']['error'])) {
                $errorMsg .= ' - ' . $response['body']['error'];
            }
            
            $this->log->error('Chatwoot API Error (deleteWebhook): ' . json_encode($response));
            throw new Error($errorMsg);
        }
        
        // Log if webhook was already deleted
        if ($response['code'] === 404) {
            $this->log->info("Webhook $webhookId was already deleted from account $accountId or doesn't exist");
        }
    }

    /**
 * List resolved contacts from a Chatwoot account with pagination.
 * Note: Page size is fixed at 15 by Chatwoot API.
 * Only returns contacts with identifier, email, or phone_number.
 *
 * @param string $platformUrl The base URL of the Chatwoot platform
 * @param string $accountApiKey The account-level API key
 * @param int $accountId The Chatwoot account ID
 * @param int $page Page number (1-based)
 * @param string $sort Sort field (name, email, phone_number, last_activity_at, or prefixed with - for desc)
 * @return array{meta: array{count: int, current_page: string}, payload: array<int, array<string, mixed>>}
 * @throws Error
 */
public function listContacts(
    string $platformUrl,
    string $accountApiKey,
    int $accountId,
    int $page = 1,
    string $sort = '-last_activity_at'
): array {
    $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/contacts';

    $queryParams = ['page' => $page];
    if ($sort) {
        $queryParams['sort'] = $sort;
    }
    $url .= '?' . http_build_query($queryParams);

    $headers = [
        'api_access_token: ' . $accountApiKey,
        'Content-Type: application/json'
    ];

    $response = $this->executeRequest($url, 'GET', null, $headers);

    if ($response['code'] < 200 || $response['code'] >= 300) {
        $errorMsg = 'Failed to list contacts from Chatwoot: HTTP ' . $response['code'];

        if (isset($response['body']['message'])) {
            $errorMsg .= ' - ' . $response['body']['message'];
        } elseif (isset($response['body']['error'])) {
            $errorMsg .= ' - ' . $response['body']['error'];
        }

        $this->log->error('Chatwoot API Error (listContacts): ' . json_encode($response));
        throw new Error($errorMsg);
    }

    return $response['body'];
}

/**
 * List conversations from a Chatwoot account.
 *
 * @param string $platformUrl The Chatwoot platform URL
 * @param string $accountApiKey The account API key
 * @param int $accountId The Chatwoot account ID
 * @param int $page Page number (default 1)
 * @param string $status Filter by status: all, open, resolved, pending, snoozed (default 'all')
 * @param string $assigneeType Filter by assignee: me, unassigned, all, assigned (default 'all')
 * @return array The API response with conversations
 */
public function listConversations(
    string $platformUrl,
    string $accountApiKey,
    int $accountId,
    int $page = 1,
    string $status = 'all',
    string $assigneeType = 'all'
): array {
    $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/conversations';

    $queryParams = [
        'page' => $page,
        'status' => $status,
        'assignee_type' => $assigneeType,
    ];
    $url .= '?' . http_build_query($queryParams);

    $headers = [
        'api_access_token: ' . $accountApiKey,
        'Content-Type: application/json'
    ];

    $response = $this->executeRequest($url, 'GET', null, $headers);

    if ($response['code'] < 200 || $response['code'] >= 300) {
        $errorMsg = 'Failed to list conversations from Chatwoot: HTTP ' . $response['code'];

        if (isset($response['body']['message'])) {
            $errorMsg .= ' - ' . $response['body']['message'];
        } elseif (isset($response['body']['error'])) {
            $errorMsg .= ' - ' . $response['body']['error'];
        }

        $this->log->error('Chatwoot API Error (listConversations): ' . json_encode($response));
        throw new Error($errorMsg);
    }

    return $response['body'];
}

/**
 * Filter contacts from a Chatwoot account using the filter API.
 * Supports cursor-based incremental sync using last_activity_at.
 *
 * @param string $platformUrl The Chatwoot platform URL
 * @param string $accountApiKey The account API key
 * @param int $accountId The Chatwoot account ID
 * @param int $page Page number (1-based)
 * @param array<array{attribute_key: string, filter_operator: string, values: array, query_operator: string|null}> $filters
 * @return array{meta: array{count: int, current_page: string}, payload: array}
 * @throws Error
 */
public function filterContacts(
    string $platformUrl,
    string $accountApiKey,
    int $accountId,
    int $page = 1,
    array $filters = []
): array {
    $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/contacts/filter';
    $url .= '?page=' . $page;

    $payload = json_encode(['payload' => $filters]);

    if ($payload === false) {
        throw new Error('Failed to encode filter payload to JSON.');
    }

    $headers = [
        'api_access_token: ' . $accountApiKey,
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload)
    ];

    $response = $this->executeRequest($url, 'POST', $payload, $headers);

    if ($response['code'] < 200 || $response['code'] >= 300) {
        $errorMsg = 'Failed to filter contacts from Chatwoot: HTTP ' . $response['code'];

        if (isset($response['body']['message'])) {
            $errorMsg .= ' - ' . $response['body']['message'];
        } elseif (isset($response['body']['error'])) {
            $errorMsg .= ' - ' . $response['body']['error'];
        }

        $this->log->error('Chatwoot API Error (filterContacts): ' . json_encode($response));
        throw new Error($errorMsg);
    }

    return $response['body'];
}

/**
 * Filter conversations from a Chatwoot account using the filter API.
 * Supports cursor-based incremental sync using last_activity_at.
 *
 * @param string $platformUrl The Chatwoot platform URL
 * @param string $accountApiKey The account API key
 * @param int $accountId The Chatwoot account ID
 * @param int $page Page number (1-based)
 * @param array<array{attribute_key: string, filter_operator: string, values: array, query_operator: string|null}> $filters
 * @return array{data: array{meta: array, payload: array}}
 * @throws Error
 */
public function filterConversations(
    string $platformUrl,
    string $accountApiKey,
    int $accountId,
    int $page = 1,
    array $filters = []
): array {
    $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/conversations/filter';
    $url .= '?page=' . $page;

    $payload = json_encode(['payload' => $filters]);

    if ($payload === false) {
        throw new Error('Failed to encode filter payload to JSON.');
    }

    $headers = [
        'api_access_token: ' . $accountApiKey,
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload)
    ];

    $response = $this->executeRequest($url, 'POST', $payload, $headers);

    if ($response['code'] < 200 || $response['code'] >= 300) {
        $errorMsg = 'Failed to filter conversations from Chatwoot: HTTP ' . $response['code'];

        if (isset($response['body']['message'])) {
            $errorMsg .= ' - ' . $response['body']['message'];
        } elseif (isset($response['body']['error'])) {
            $errorMsg .= ' - ' . $response['body']['error'];
        }

        $this->log->error('Chatwoot API Error (filterConversations): ' . json_encode($response));
        throw new Error($errorMsg);
    }

    return $response['body'];
}

/**
 * Merge two contacts in Chatwoot.
 * The mergee contact will be deleted and its data merged into the base contact.
 *
 * @param string $platformUrl The Chatwoot platform URL
 * @param string $accountApiKey The account API key
 * @param int $accountId The Chatwoot account ID
 * @param int $baseContactId The contact to keep (survives the merge)
 * @param int $mergeeContactId The contact to merge and delete
 * @return array The merged contact data
 * @throws Error
 */
public function mergeContacts(
    string $platformUrl,
    string $accountApiKey,
    int $accountId,
    int $baseContactId,
    int $mergeeContactId
): array {
    $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/actions/contact_merge';

    $payload = json_encode([
        'base_contact_id' => $baseContactId,
        'mergee_contact_id' => $mergeeContactId,
    ]);

    if ($payload === false) {
        throw new Error('Failed to encode merge payload to JSON.');
    }

    $headers = [
        'api_access_token: ' . $accountApiKey,
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload)
    ];

    $response = $this->executeRequest($url, 'POST', $payload, $headers);

    if ($response['code'] < 200 || $response['code'] >= 300) {
        $errorMsg = 'Failed to merge contacts in Chatwoot: HTTP ' . $response['code'];

        if (isset($response['body']['message'])) {
            $errorMsg .= ' - ' . $response['body']['message'];
        } elseif (isset($response['body']['error'])) {
            $errorMsg .= ' - ' . $response['body']['error'];
        }

        $this->log->error('Chatwoot API Error (mergeContacts): ' . json_encode($response));
        throw new Error($errorMsg);
    }

    $this->log->info("Chatwoot: Merged contact {$mergeeContactId} into {$baseContactId} for account {$accountId}");

    return $response['body'];
}

/**
 * List all inboxes from a Chatwoot account.
 *
 * @param string $platformUrl The Chatwoot platform URL
 * @param string $accountApiKey The account API key
 * @param int $accountId The Chatwoot account ID
 * @return array The API response with inboxes
 */
public function listInboxes(
    string $platformUrl,
    string $accountApiKey,
    int $accountId
): array {
    $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/inboxes';

    $headers = [
        'api_access_token: ' . $accountApiKey,
        'Content-Type: application/json'
    ];

    $response = $this->executeRequest($url, 'GET', null, $headers);

    if ($response['code'] < 200 || $response['code'] >= 300) {
        $errorMsg = 'Failed to list inboxes from Chatwoot: HTTP ' . $response['code'];

        if (isset($response['body']['message'])) {
            $errorMsg .= ' - ' . $response['body']['message'];
        } elseif (isset($response['body']['error'])) {
            $errorMsg .= ' - ' . $response['body']['error'];
        }

        $this->log->error('Chatwoot API Error (listInboxes): ' . json_encode($response));
        throw new Error($errorMsg);
    }

    return $response['body'];
}

/**
 * Delete an inbox from a Chatwoot account.
 *
 * @param string $platformUrl The Chatwoot platform URL
 * @param string $accountApiKey The account API key
 * @param int $accountId The Chatwoot account ID
 * @param int $inboxId The Chatwoot inbox ID
 * @return void
 * @throws Error
 */
public function deleteInbox(
    string $platformUrl,
    string $accountApiKey,
    int $accountId,
    int $inboxId
): void {
    $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/inboxes/' . $inboxId;

    $headers = [
        'api_access_token: ' . $accountApiKey,
        'Content-Type: application/json'
    ];

    $response = $this->executeRequest($url, 'DELETE', null, $headers);

    // Accept 204 No Content, 200 OK, or 404 (already deleted) as success
    if ($response['code'] !== 200 && $response['code'] !== 204 && $response['code'] !== 404) {
        $errorMsg = 'Failed to delete inbox from Chatwoot: HTTP ' . $response['code'];

        if (isset($response['body']['message'])) {
            $errorMsg .= ' - ' . $response['body']['message'];
        } elseif (isset($response['body']['error'])) {
            $errorMsg .= ' - ' . $response['body']['error'];
        }

        $this->log->error('Chatwoot API Error (deleteInbox): ' . json_encode($response));
        throw new Error($errorMsg);
    }

    // Log if inbox was already deleted
    if ($response['code'] === 404) {
        $this->log->info("Inbox $inboxId was already deleted from account $accountId or doesn't exist");
    }
}

/**
 * Toggle the status of a conversation in Chatwoot.
 *
 * @param string $platformUrl The Chatwoot platform URL
 * @param string $accountApiKey The account API key
 * @param int $accountId The Chatwoot account ID
 * @param int $conversationId The Chatwoot conversation ID
 * @param string $status The new status (open, resolved, pending, snoozed)
 * @param int|null $snoozedUntil Unix timestamp for snooze (optional, only for snoozed status)
 * @return array The API response
 * @throws Error
 */
public function toggleConversationStatus(
    string $platformUrl,
    string $accountApiKey,
    int $accountId,
    int $conversationId,
    string $status,
    ?int $snoozedUntil = null
): array {
    $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/conversations/' . $conversationId . '/toggle_status';

    $data = ['status' => $status];
    
    if ($status === 'snoozed' && $snoozedUntil !== null) {
        $data['snoozed_until'] = $snoozedUntil;
    }

    $payload = json_encode($data);

    if ($payload === false) {
        throw new Error('Failed to encode status data to JSON.');
    }

    $headers = [
        'api_access_token: ' . $accountApiKey,
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload)
    ];

    $response = $this->executeRequest($url, 'POST', $payload, $headers);

    if ($response['code'] < 200 || $response['code'] >= 300) {
        $errorMsg = 'Failed to toggle conversation status in Chatwoot: HTTP ' . $response['code'];

        if (isset($response['body']['message'])) {
            $errorMsg .= ' - ' . $response['body']['message'];
        } elseif (isset($response['body']['error'])) {
            $errorMsg .= ' - ' . $response['body']['error'];
        }

        $this->log->error('Chatwoot API Error (toggleConversationStatus): ' . json_encode($response));
        throw new Error($errorMsg);
    }

    $this->log->info("Chatwoot: Toggled conversation {$conversationId} status to '{$status}' for account {$accountId}");

    return $response['body'];
}

/* -------------------------------------------------------------------------- */
/*                    Agent API Methods (Account-level API)                   */
/* -------------------------------------------------------------------------- */

/**
 * List all agents in a Chatwoot account.
 *
 * @param string $platformUrl The Chatwoot platform URL
 * @param string $accountApiKey The account API key
 * @param int $accountId The Chatwoot account ID
 * @return array<int, array<string, mixed>> List of agents
 * @throws Error
 */
public function listAgents(
    string $platformUrl,
    string $accountApiKey,
    int $accountId
): array {
    $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/agents';

    $headers = [
        'api_access_token: ' . $accountApiKey,
        'Content-Type: application/json'
    ];

    $response = $this->executeRequest($url, 'GET', null, $headers);

    if ($response['code'] < 200 || $response['code'] >= 300) {
        $errorMsg = 'Failed to list agents from Chatwoot: HTTP ' . $response['code'];

        if (isset($response['body']['message'])) {
            $errorMsg .= ' - ' . $response['body']['message'];
        } elseif (isset($response['body']['error'])) {
            $errorMsg .= ' - ' . $response['body']['error'];
        }

        $this->log->error('Chatwoot API Error (listAgents): ' . json_encode($response));
        throw new Error($errorMsg);
    }

    return $response['body'];
}

/**
 * Create an agent in a Chatwoot account.
 *
 * @param string $platformUrl The Chatwoot platform URL
 * @param string $accountApiKey The account API key
 * @param int $accountId The Chatwoot account ID
 * @param array<string, mixed> $agentData Agent data (name, email, role, availability_status, auto_offline)
 * @return array<string, mixed> Response data from Chatwoot API
 * @throws Error
 */
public function createAgent(
    string $platformUrl,
    string $accountApiKey,
    int $accountId,
    array $agentData
): array {
    $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/agents';

    $payload = json_encode($agentData);

    if ($payload === false) {
        throw new Error('Failed to encode agent data to JSON.');
    }

    $headers = [
        'api_access_token: ' . $accountApiKey,
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload)
    ];

    $response = $this->executeRequest($url, 'POST', $payload, $headers);

    if ($response['code'] < 200 || $response['code'] >= 300) {
        $errorMsg = 'Chatwoot API error: HTTP ' . $response['code'];

        if (isset($response['body']['message'])) {
            $errorMsg .= ' - ' . $response['body']['message'];
        } elseif (isset($response['body']['error'])) {
            $errorMsg .= ' - ' . $response['body']['error'];
        }

        $this->log->error('Chatwoot API Error (createAgent): ' . json_encode($response));
        throw new Error($errorMsg);
    }

    $this->log->info("Chatwoot: Created agent '{$agentData['email']}' in account {$accountId}");

    return $response['body'];
}

/**
 * Update an agent in a Chatwoot account.
 *
 * @param string $platformUrl The Chatwoot platform URL
 * @param string $accountApiKey The account API key
 * @param int $accountId The Chatwoot account ID
 * @param int $agentId The Chatwoot agent ID
 * @param array<string, mixed> $agentData Agent data to update (role, availability_status, auto_offline)
 * @return array<string, mixed> Response data from Chatwoot API
 * @throws Error
 */
public function updateAgent(
    string $platformUrl,
    string $accountApiKey,
    int $accountId,
    int $agentId,
    array $agentData
): array {
    $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/agents/' . $agentId;

    $payload = json_encode($agentData);

    if ($payload === false) {
        throw new Error('Failed to encode agent data to JSON.');
    }

    $headers = [
        'api_access_token: ' . $accountApiKey,
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload)
    ];

    $response = $this->executeRequest($url, 'PATCH', $payload, $headers);

    if ($response['code'] < 200 || $response['code'] >= 300) {
        $errorMsg = 'Chatwoot API error: HTTP ' . $response['code'];

        if (isset($response['body']['message'])) {
            $errorMsg .= ' - ' . $response['body']['message'];
        } elseif (isset($response['body']['error'])) {
            $errorMsg .= ' - ' . $response['body']['error'];
        }

        $this->log->error('Chatwoot API Error (updateAgent): ' . json_encode($response));
        throw new Error($errorMsg);
    }

    $this->log->info("Chatwoot: Updated agent {$agentId} in account {$accountId}");

    return $response['body'];
}

/**
 * Delete an agent from a Chatwoot account.
 *
 * @param string $platformUrl The Chatwoot platform URL
 * @param string $accountApiKey The account API key
 * @param int $accountId The Chatwoot account ID
 * @param int $agentId The Chatwoot agent ID
 * @return void
 * @throws Error
 */
public function deleteAgent(
    string $platformUrl,
    string $accountApiKey,
    int $accountId,
    int $agentId
): void {
    $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/agents/' . $agentId;

    $headers = [
        'api_access_token: ' . $accountApiKey,
        'Content-Type: application/json'
    ];

    $response = $this->executeRequest($url, 'DELETE', null, $headers);

    // Accept 204 No Content, 200 OK, or 404 (already deleted) as success
    if ($response['code'] !== 200 && $response['code'] !== 204 && $response['code'] !== 404) {
        $errorMsg = 'Failed to delete agent from Chatwoot: HTTP ' . $response['code'];

        if (isset($response['body']['message'])) {
            $errorMsg .= ' - ' . $response['body']['message'];
        } elseif (isset($response['body']['error'])) {
            $errorMsg .= ' - ' . $response['body']['error'];
        }

        $this->log->error('Chatwoot API Error (deleteAgent): ' . json_encode($response));
        throw new Error($errorMsg);
    }

    // Log if agent was already deleted
    if ($response['code'] === 404) {
        $this->log->info("Agent {$agentId} was already deleted from account {$accountId} or doesn't exist");
    } else {
        $this->log->info("Chatwoot: Deleted agent {$agentId} from account {$accountId}");
    }
}

/**
 * List all agents (members) in an inbox.
 *
 * @param string $platformUrl The Chatwoot platform URL
 * @param string $accountApiKey The account API key
 * @param int $accountId The Chatwoot account ID
 * @param int $inboxId The Chatwoot inbox ID
 * @return array<int, array<string, mixed>> List of agents in the inbox
 * @throws Error
 */
public function listInboxMembers(
    string $platformUrl,
    string $accountApiKey,
    int $accountId,
    int $inboxId
): array {
    $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/inbox_members/' . $inboxId;

    $headers = [
        'api_access_token: ' . $accountApiKey,
        'Content-Type: application/json'
    ];

    $response = $this->executeRequest($url, 'GET', null, $headers);

    if ($response['code'] < 200 || $response['code'] >= 300) {
        $errorMsg = 'Failed to list inbox members from Chatwoot: HTTP ' . $response['code'];

        if (isset($response['body']['message'])) {
            $errorMsg .= ' - ' . $response['body']['message'];
        } elseif (isset($response['body']['error'])) {
            $errorMsg .= ' - ' . $response['body']['error'];
        }

        $this->log->error('Chatwoot API Error (listInboxMembers): ' . json_encode($response));
        throw new Error($errorMsg);
    }

    // Response format: { "payload": [agents...] }
    return $response['body']['payload'] ?? $response['body'];
}

/**
 * Get a conversation from Chatwoot.
 *
 * @param string $platformUrl The Chatwoot platform URL
 * @param string $accountApiKey The account API key
 * @param int $accountId The Chatwoot account ID
 * @param int $conversationId The Chatwoot conversation ID
 * @return array<string, mixed>|null The conversation data, or null if not found (404)
 * @throws Error
 */
public function getConversation(
    string $platformUrl,
    string $accountApiKey,
    int $accountId,
    int $conversationId
): ?array {
    $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/conversations/' . $conversationId;

    $headers = [
        'api_access_token: ' . $accountApiKey,
        'Content-Type: application/json'
    ];

    $response = $this->executeRequest($url, 'GET', null, $headers);

    // Return null for 404 (conversation not found)
    if ($response['code'] === 404) {
        $this->log->info("Chatwoot: Conversation {$conversationId} not found in account {$accountId} (404)");
        return null;
    }

    if ($response['code'] < 200 || $response['code'] >= 300) {
        $errorMsg = 'Failed to get conversation from Chatwoot: HTTP ' . $response['code'];

        if (isset($response['body']['message'])) {
            $errorMsg .= ' - ' . $response['body']['message'];
        } elseif (isset($response['body']['error'])) {
            $errorMsg .= ' - ' . $response['body']['error'];
        }

        $this->log->error('Chatwoot API Error (getConversation): ' . json_encode($response));
        throw new Error($errorMsg);
    }

    return $response['body'];
}

/**
 * Assign a conversation to an agent or team in Chatwoot.
 *
 * @param string $platformUrl The Chatwoot platform URL
 * @param string $accountApiKey The account API key
 * @param int $accountId The Chatwoot account ID
 * @param int $conversationId The Chatwoot conversation ID
 * @param int|null $assigneeId The agent ID to assign (null to unassign)
 * @param int|null $teamId The team ID to assign (ignored if assigneeId is present)
 * @return array<string, mixed> The assigned agent data
 * @throws Error
 */
public function assignConversation(
    string $platformUrl,
    string $accountApiKey,
    int $accountId,
    int $conversationId,
    ?int $assigneeId = null,
    ?int $teamId = null
): array {
    $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/conversations/' . $conversationId . '/assignments';

    // Always include assignee_id in the payload - set to 0 to unassign
    // Chatwoot API requires explicit assignee_id: 0 to remove an assignment
    $data = [
        'assignee_id' => $assigneeId ?? 0
    ];
    
    // Add team_id if specified (for team assignment without specific agent)
    if ($teamId !== null) {
        $data['team_id'] = $teamId;
    }

    $payload = json_encode($data);

    if ($payload === false) {
        throw new Error('Failed to encode assignment data to JSON.');
    }

    $headers = [
        'api_access_token: ' . $accountApiKey,
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload)
    ];

    $response = $this->executeRequest($url, 'POST', $payload, $headers);

    if ($response['code'] < 200 || $response['code'] >= 300) {
        $errorMsg = 'Failed to assign conversation in Chatwoot: HTTP ' . $response['code'];

        if (isset($response['body']['message'])) {
            $errorMsg .= ' - ' . $response['body']['message'];
        } elseif (isset($response['body']['error'])) {
            $errorMsg .= ' - ' . $response['body']['error'];
        }

        $this->log->error('Chatwoot API Error (assignConversation): ' . json_encode($response));
        throw new Error($errorMsg);
    }

    $assigneeInfo = $assigneeId !== null ? "agent {$assigneeId}" : ($teamId !== null ? "team {$teamId}" : "unassigned");
    $this->log->info("Chatwoot: Assigned conversation {$conversationId} to {$assigneeInfo} in account {$accountId}");

    return $response['body'];
}

/**
 * Delete a conversation from Chatwoot.
 * Note: Only administrators can delete conversations.
 *
 * @param string $platformUrl The Chatwoot platform URL
 * @param string $accountApiKey The account API key (must be from an administrator)
 * @param int $accountId The Chatwoot account ID
 * @param int $conversationId The Chatwoot conversation ID (display_id)
 * @return bool True if deleted successfully
 * @throws Error
 */
public function deleteConversation(
    string $platformUrl,
    string $accountApiKey,
    int $accountId,
    int $conversationId
): bool {
    $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/conversations/' . $conversationId;

    $headers = [
        'api_access_token: ' . $accountApiKey,
        'Content-Type: application/json'
    ];

    $response = $this->executeRequest($url, 'DELETE', null, $headers);

    // 404 means already deleted - treat as success
    if ($response['code'] === 404) {
        $this->log->info("Chatwoot: Conversation {$conversationId} not found in account {$accountId} (already deleted)");
        return true;
    }

    // 401/403 means unauthorized - agent API key used instead of administrator
    if ($response['code'] === 401 || $response['code'] === 403) {
        $errorMsg = 'Unauthorized to delete conversation. Only administrators can delete conversations.';
        if (isset($response['body']['error'])) {
            $errorMsg .= ' - ' . $response['body']['error'];
        }
        $this->log->error('Chatwoot API Error (deleteConversation): ' . $errorMsg);
        throw new Error($errorMsg);
    }

    if ($response['code'] < 200 || $response['code'] >= 300) {
        $errorMsg = 'Failed to delete conversation from Chatwoot: HTTP ' . $response['code'];

        if (isset($response['body']['message'])) {
            $errorMsg .= ' - ' . $response['body']['message'];
        } elseif (isset($response['body']['error'])) {
            $errorMsg .= ' - ' . $response['body']['error'];
        }

        $this->log->error('Chatwoot API Error (deleteConversation): ' . json_encode($response));
        throw new Error($errorMsg);
    }

    $this->log->info("Chatwoot: Deleted conversation {$conversationId} from account {$accountId}");
    return true;
}

    /* -------------------------------------------------------------------------- */
    /*                    Label API Methods (Account-level API)                   */
    /* -------------------------------------------------------------------------- */

    /**
     * List all labels in a Chatwoot account.
     *
     * @param string $platformUrl The base URL of the Chatwoot platform
     * @param string $accountApiKey The account-level API key
     * @param int $accountId The Chatwoot account ID
     * @return array<int, array<string, mixed>> List of labels
     * @throws Error
     */
    public function listLabels(
        string $platformUrl,
        string $accountApiKey,
        int $accountId
    ): array {
        $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/labels';

        $headers = [
            'api_access_token: ' . $accountApiKey,
            'Content-Type: application/json'
        ];

        $response = $this->executeRequest($url, 'GET', null, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $errorMsg = 'Failed to list labels from Chatwoot: HTTP ' . $response['code'];

            if (isset($response['body']['message'])) {
                $errorMsg .= ' - ' . $response['body']['message'];
            } elseif (isset($response['body']['error'])) {
                $errorMsg .= ' - ' . $response['body']['error'];
            }

            $this->log->error('Chatwoot API Error (listLabels): ' . json_encode($response));
            throw new Error($errorMsg);
        }

        // Response may have 'payload' key or be a direct array
        return $response['body']['payload'] ?? $response['body'] ?? [];
    }

    /**
     * Create a label in a Chatwoot account.
     *
     * @param string $platformUrl The base URL of the Chatwoot platform
     * @param string $accountApiKey The account-level API key
     * @param int $accountId The Chatwoot account ID
     * @param array<string, mixed> $labelData Label data (title, description, color, show_on_sidebar)
     * @return array<string, mixed> Response data from Chatwoot API
     * @throws Error
     */
    public function createLabel(
        string $platformUrl,
        string $accountApiKey,
        int $accountId,
        array $labelData
    ): array {
        $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/labels';

        $payload = json_encode($labelData);

        if ($payload === false) {
            throw new Error('Failed to encode label data to JSON.');
        }

        $headers = [
            'api_access_token: ' . $accountApiKey,
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ];

        $response = $this->executeRequest($url, 'POST', $payload, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $errorMsg = 'Chatwoot API error: HTTP ' . $response['code'];

            if (isset($response['body']['message'])) {
                $errorMsg .= ' - ' . $response['body']['message'];
            } elseif (isset($response['body']['error'])) {
                $errorMsg .= ' - ' . $response['body']['error'];
            }

            $this->log->error('Chatwoot API Error (createLabel): ' . json_encode($response));
            throw new Error($errorMsg);
        }

        return $response['body'];
    }

    /**
     * Update a label in a Chatwoot account.
     *
     * @param string $platformUrl The base URL of the Chatwoot platform
     * @param string $accountApiKey The account-level API key
     * @param int $accountId The Chatwoot account ID
     * @param int $labelId The Chatwoot label ID
     * @param array<string, mixed> $labelData Label data to update
     * @return array<string, mixed> Response data from Chatwoot API
     * @throws Error
     */
    public function updateLabel(
        string $platformUrl,
        string $accountApiKey,
        int $accountId,
        int $labelId,
        array $labelData
    ): array {
        $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/labels/' . $labelId;

        $payload = json_encode($labelData);

        if ($payload === false) {
            throw new Error('Failed to encode label data to JSON.');
        }

        $headers = [
            'api_access_token: ' . $accountApiKey,
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ];

        $response = $this->executeRequest($url, 'PATCH', $payload, $headers);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $errorMsg = 'Chatwoot API error: HTTP ' . $response['code'];

            if (isset($response['body']['message'])) {
                $errorMsg .= ' - ' . $response['body']['message'];
            } elseif (isset($response['body']['error'])) {
                $errorMsg .= ' - ' . $response['body']['error'];
            }

            $this->log->error('Chatwoot API Error (updateLabel): ' . json_encode($response));
            throw new Error($errorMsg);
        }

        return $response['body'];
    }

    /**
     * Delete a label from a Chatwoot account.
     *
     * @param string $platformUrl The base URL of the Chatwoot platform
     * @param string $accountApiKey The account-level API key
     * @param int $accountId The Chatwoot account ID
     * @param int $labelId The Chatwoot label ID
     * @return void
     * @throws Error
     */
    public function deleteLabel(
        string $platformUrl,
        string $accountApiKey,
        int $accountId,
        int $labelId
    ): void {
        $url = rtrim($platformUrl, '/') . '/api/v1/accounts/' . $accountId . '/labels/' . $labelId;

        $headers = [
            'api_access_token: ' . $accountApiKey,
            'Content-Type: application/json'
        ];

        $response = $this->executeRequest($url, 'DELETE', null, $headers);

        // Accept 204 No Content, 200 OK, or 404 (already deleted) as success
        if ($response['code'] !== 200 && $response['code'] !== 204 && $response['code'] !== 404) {
            $errorMsg = 'Failed to delete label from Chatwoot: HTTP ' . $response['code'];

            if (isset($response['body']['message'])) {
                $errorMsg .= ' - ' . $response['body']['message'];
            } elseif (isset($response['body']['error'])) {
                $errorMsg .= ' - ' . $response['body']['error'];
            }

            $this->log->error('Chatwoot API Error (deleteLabel): ' . json_encode($response));
            throw new Error($errorMsg);
        }

        if ($response['code'] === 404) {
            $this->log->info("Chatwoot: Label {$labelId} not found in account {$accountId} (already deleted)");
        }
    }
}

