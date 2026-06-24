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

declare(strict_types=1);

namespace Espo\Modules\FeatureOAuthEnhanced\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Field\DateTime;
use Espo\Core\Utils\Crypt;
use Espo\Core\Utils\Log;
use Espo\Entities\OAuthAccount;
use Espo\Entities\OAuthProvider;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * Stores a manually-supplied Meta (Facebook) System User access token on an
 * OAuthAccount.
 *
 * Meta System Users represent servers/software that call the Graph API on
 * behalf of assets managed by a Business portfolio. Their tokens are NOT
 * obtained through the standard browser authorization-code popup the rest of
 * EspoCRM's OAuth subsystem assumes — an admin generates them either in the
 * Business Manager UI or via the System Users API and pastes the resulting
 * token here.
 *
 * Responsibilities:
 *   1. ACL-gate the OAuthAccount (edit access required).
 *   2. Require the linked provider to be the seeded `meta-system-user`
 *      provider and active (so app id/secret are available for validation).
 *   3. Validate the pasted token against Meta's Graph API:
 *        - GET /debug_token (using `{appId}|{appSecret}` as the app token)
 *          to confirm the token is valid, belongs to this app, and to read
 *          its expiry + scopes + the System User's app-scoped id.
 *   4. Encrypt and persist the token on OAuthAccount.accessToken using the
 *      SAME {@see Crypt} contract that {@see \Espo\Tools\OAuth\TokenSetter}
 *      and {@see \Espo\Tools\OAuth\TokensProvider} rely on, so all existing
 *      Graph consumers keep working unchanged.
 *   5. Store the expiry (if any) on expiresAt. Non-expiring System User
 *      tokens leave expiresAt null; refreshToken is always null because the
 *      generic `refresh_token` grant does NOT apply to System Users (they
 *      refresh via `oauth/access_token?grant_type=fb_exchange_token`).
 *
 * Result of {@see self::set()} is a small diagnostics array suitable for
 * surfacing in the UI.
 */
class MetaSystemUserTokenService
{
    private const PROVIDER_DISCRIMINATOR = 'meta-system-user';
    private const GRAPH_API_BASE = 'https://graph.facebook.com';
    private const DEFAULT_API_VERSION = 'v22.0';
    private const TIMEOUT_SECONDS = 15;

    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
        private Crypt $crypt,
        private Log $log,
    ) {}

    /**
     * Validate and store a System User access token on an OAuthAccount.
     *
     * @param string $oAuthAccountId
     * @param string $token Raw Meta System User access token (plaintext).
     * @param ?string $businessId Optional Business Manager id to persist for
     *                            diagnostics / asset-scoped calls.
     * @return array<string, mixed> Diagnostics: {appId, systemUserId, scopes, expiresAt, isValid}.
     * @throws NotFound
     * @throws Forbidden
     * @throws BadRequest
     * @throws Error
     */
    public function set(string $oAuthAccountId, string $token, ?string $businessId = null): array
    {
        $token = trim($token);

        if ($token === '') {
            throw new BadRequest('Token is empty.');
        }

        $account = $this->entityManager
            ->getRDBRepositoryByClass(OAuthAccount::class)
            ->getById($oAuthAccountId);

        if (!$account) {
            throw new NotFound('OAuthAccount not found.');
        }

        if (!$this->acl->check($account, 'edit')) {
            throw new Forbidden("You don't have edit access to this OAuth Account.");
        }

        $provider = $this->getProvider($account);

        $appId = $provider->get('clientId');
        $appSecret = $this->decryptSecret($provider);

        if (!is_string($appId) || $appId === '' || $appSecret === null || $appSecret === '') {
            throw new BadRequest(
                'The Meta (System User) provider must have a Client ID (App ID) and Client Secret configured before a token can be set.'
            );
        }

        $debug = $this->debugToken($token, $appId, $appSecret);

        // Persist token encrypted using the same contract as core OAuth.
        $account->set('accessToken', $this->crypt->encrypt($token));

        // System User tokens are not refreshable via the generic refresh_token
        // grant, so we never store a refresh token. TokensProvider::get() will
        // therefore skip its auto-refresh branch.
        $account->set('refreshToken', null);

        $expiresAt = $this->resolveExpiry($debug);
        $account->set('expiresAt', $expiresAt?->toString());

        $systemUserId = $debug['user_id'] ?? null;

        if (is_string($systemUserId) && $systemUserId !== '') {
            $account->set('metaSystemUserId', $systemUserId);
        }

        if ($businessId !== null && trim($businessId) !== '') {
            $account->set('metaBusinessId', trim($businessId));
        }

        $tokenInfo = $this->buildTokenInfo($debug, $expiresAt);
        $account->set('metaTokenInfo', $tokenInfo);

        $this->entityManager->saveEntity($account);

        $scopes = is_array($debug['scopes'] ?? null) ? $debug['scopes'] : [];
        $warnings = $this->buildScopeWarnings($scopes);

        $this->log->info(
            "MetaSystemUserTokenService: stored System User token on OAuthAccount {$oAuthAccountId} " .
            "(appId={$appId}, systemUserId=" . ($systemUserId ?? 'n/a') . ", " .
            "expiresAt=" . ($expiresAt?->toString() ?? 'never') . ", " .
            "scopes=" . implode(',', $scopes) . ")."
        );

        if ($warnings) {
            $this->log->warning(
                "MetaSystemUserTokenService: OAuthAccount {$oAuthAccountId} token is missing scopes: " .
                implode(', ', $warnings) . "."
            );
        }

        return [
            'ok' => true,
            'appId' => $appId,
            'systemUserId' => $systemUserId,
            'scopes' => $scopes,
            'missingScopes' => $warnings,
            'expiresAt' => $expiresAt?->toString(),
            'isValid' => true,
        ];
    }

    /**
     * Generate a System User access token programmatically and store it.
     *
     * Implements Meta's System Users token-generation flow:
     *   1. Install the app for the system user:
     *        POST /{system-user-id}/applications  (business_app, access_token)
     *   2. Generate the token:
     *        POST /{system-user-id}/access_tokens
     *          (business_app, scope, appsecret_proof, access_token,
     *           [set_token_expires_in_60_days])
     *   3. Validate + store the minted token via {@see self::set()} so it goes
     *      through the same /debug_token validation and encrypted storage.
     *
     * The CALLER token (used to authorize steps 1 & 2) must belong to an admin
     * user or admin system user of the same Business and have
     * `business_management`. When $callerToken is null we reuse the token
     * currently stored on the account.
     *
     * @param string $oAuthAccountId
     * @param string[] $scopes Scopes to request for the new token.
     * @param ?string $callerToken Admin/admin-system-user token. Null = reuse stored token.
     * @param bool $expiring When true, request a 60-day expiring token.
     * @return array<string, mixed>
     * @throws NotFound
     * @throws Forbidden
     * @throws BadRequest
     * @throws Error
     */
    public function generate(
        string $oAuthAccountId,
        array $scopes,
        ?string $callerToken = null,
        bool $expiring = false,
    ): array {
        $account = $this->entityManager
            ->getRDBRepositoryByClass(OAuthAccount::class)
            ->getById($oAuthAccountId);

        if (!$account) {
            throw new NotFound('OAuthAccount not found.');
        }

        if (!$this->acl->check($account, 'edit')) {
            throw new Forbidden("You don't have edit access to this OAuth Account.");
        }

        $provider = $this->getProvider($account);

        $appId = $provider->get('clientId');
        $appSecret = $this->decryptSecret($provider);

        if (!is_string($appId) || $appId === '' || $appSecret === null || $appSecret === '') {
            throw new BadRequest(
                'The Meta (System User) provider must have a Client ID (App ID) and Client Secret configured.'
            );
        }

        $systemUserId = $account->get('metaSystemUserId');

        if (!is_string($systemUserId) || trim($systemUserId) === '') {
            throw new BadRequest(
                'This OAuth Account has no Meta System User ID. Set an initial token first ' .
                '(so the System User ID is captured) or enter it manually.'
            );
        }

        $systemUserId = trim($systemUserId);

        // Resolve the caller token used to authorize the install + generate.
        $callerToken = $callerToken !== null ? trim($callerToken) : '';

        if ($callerToken === '') {
            $stored = $account->getAccessToken();

            if (!$stored) {
                throw new BadRequest(
                    'No caller token available. Provide an admin (or admin system user) token, ' .
                    'or set an initial token on this account first.'
                );
            }

            $callerToken = $this->crypt->decrypt($stored);
        }

        $scopes = array_values(array_filter(array_map('trim', $scopes), fn($s) => $s !== ''));

        if (!$scopes) {
            throw new BadRequest('At least one scope is required.');
        }

        // Step 1: install the app for the system user. Idempotent; Meta returns
        // success=true even if already installed.
        $this->installAppForSystemUser($systemUserId, $appId, $callerToken, $appSecret);

        // Step 2: generate the token.
        $newToken = $this->generateAccessToken(
            $systemUserId,
            $appId,
            $appSecret,
            $callerToken,
            $scopes,
            $expiring,
        );

        // Step 3: validate + store via the existing path (re-uses /debug_token,
        // encryption, scope warnings, and field persistence).
        $result = $this->set($oAuthAccountId, $newToken, $account->get('metaBusinessId'));
        $result['generated'] = true;

        return $result;
    }

    /**
     * POST /{system-user-id}/applications
     *
     * @throws Error
     */
    private function installAppForSystemUser(
        string $systemUserId,
        string $appId,
        string $callerToken,
        string $appSecret,
    ): void {
        $url = self::GRAPH_API_BASE . '/' . self::DEFAULT_API_VERSION . "/{$systemUserId}/applications";

        $params = [
            'business_app' => $appId,
            'access_token' => $callerToken,
            'appsecret_proof' => $this->appSecretProof($callerToken, $appSecret),
        ];

        $response = $this->httpPost($url, $params);

        if (($response['success'] ?? null) !== true && !isset($response['id'])) {
            $this->log->warning(
                'MetaSystemUserTokenService: install app for system user returned: ' . json_encode($response)
            );
        }
    }

    /**
     * POST /{system-user-id}/access_tokens
     *
     * @param string[] $scopes
     * @return string The minted access token.
     * @throws Error
     * @throws BadRequest
     */
    private function generateAccessToken(
        string $systemUserId,
        string $appId,
        string $appSecret,
        string $callerToken,
        array $scopes,
        bool $expiring,
    ): string {
        $url = self::GRAPH_API_BASE . '/' . self::DEFAULT_API_VERSION . "/{$systemUserId}/access_tokens";

        $params = [
            'business_app' => $appId,
            'scope' => implode(',', $scopes),
            'appsecret_proof' => $this->appSecretProof($callerToken, $appSecret),
            'access_token' => $callerToken,
        ];

        if ($expiring) {
            $params['set_token_expires_in_60_days'] = 'true';
        }

        $response = $this->httpPost($url, $params);

        $token = $response['access_token'] ?? null;

        if (!is_string($token) || $token === '') {
            throw new BadRequest(
                'Meta did not return an access token. Response: ' . json_encode($response)
            );
        }

        return $token;
    }

    /**
     * Compute appsecret_proof = HMAC-SHA256(access_token, app_secret).
     */
    private function appSecretProof(string $accessToken, string $appSecret): string
    {
        return hash_hmac('sha256', $accessToken, $appSecret);
    }

    /**
     * @throws BadRequest
     */
    private function getProvider(OAuthAccount $account): OAuthProvider
    {
        $providerId = $account->get('providerId');

        if (!$providerId) {
            throw new BadRequest('OAuthAccount has no provider assigned.');
        }

        $provider = $this->entityManager
            ->getRDBRepositoryByClass(OAuthProvider::class)
            ->getById((string) $providerId);

        if (!$provider) {
            throw new BadRequest('OAuthProvider not found.');
        }

        if ($provider->get('provider') !== self::PROVIDER_DISCRIMINATOR) {
            throw new BadRequest(
                'This action only applies to OAuth Accounts whose provider is "Meta (System User)".'
            );
        }

        if (!$provider->get('isActive')) {
            throw new BadRequest('The Meta (System User) provider is not active.');
        }

        return $provider;
    }

    private function decryptSecret(OAuthProvider $provider): ?string
    {
        $secret = $provider->get('clientSecret');

        if (!is_string($secret) || $secret === '') {
            return null;
        }

        try {
            return $this->crypt->decrypt($secret);
        } catch (\Throwable $e) {
            $this->log->error(
                'MetaSystemUserTokenService: failed to decrypt provider client secret: ' . $e->getMessage()
            );

            return null;
        }
    }

    /**
     * Call GET /debug_token to validate the token and read its metadata.
     *
     * @return array<string, mixed> The `data` object from the debug_token response.
     * @throws BadRequest When the token is invalid.
     * @throws Error On transport errors.
     */
    private function debugToken(string $token, string $appId, string $appSecret): array
    {
        $appAccessToken = $appId . '|' . $appSecret;

        $url = self::GRAPH_API_BASE . '/' . self::DEFAULT_API_VERSION . '/debug_token'
            . '?input_token=' . urlencode($token)
            . '&access_token=' . urlencode($appAccessToken);

        $response = $this->httpGet($url);

        $data = $response['data'] ?? null;

        if (!is_array($data)) {
            throw new BadRequest('Unexpected response from Meta /debug_token.');
        }

        if (($data['is_valid'] ?? false) !== true) {
            $reason = $data['error']['message'] ?? 'Token is not valid for this app.';

            throw new BadRequest('Meta rejected the token: ' . $reason);
        }

        // Guard against pasting a token issued for a different app.
        if (isset($data['app_id']) && (string) $data['app_id'] !== $appId) {
            throw new BadRequest(
                'The token was issued for a different Meta app (app_id=' . $data['app_id'] . ') ' .
                'than the one configured on this provider (app_id=' . $appId . ').'
            );
        }

        return $data;
    }

    /**
     * Resolve the expiry timestamp from a debug_token payload.
     *
     * Meta uses `expires_at = 0` (and `data_access_expires_at`) to signal a
     * non-expiring token. We treat 0 / missing as "never expires" → null.
     */
    private function resolveExpiry(array $debug): ?DateTime
    {
        $expiresAt = $debug['expires_at'] ?? null;

        if (!is_int($expiresAt) && !is_numeric($expiresAt)) {
            return null;
        }

        $ts = (int) $expiresAt;

        if ($ts <= 0) {
            // 0 means the token never expires (typical for System Users).
            return null;
        }

        return DateTime::fromTimestamp($ts);
    }

    /**
     * @return stdClass
     */
    private function buildTokenInfo(array $debug, ?DateTime $expiresAt): stdClass
    {
        $info = new stdClass();
        $info->appId = $debug['app_id'] ?? null;
        $info->application = $debug['application'] ?? null;
        $info->type = $debug['type'] ?? null;
        $info->userId = $debug['user_id'] ?? null;
        $info->scopes = $debug['scopes'] ?? [];
        $info->expiresAt = $expiresAt?->toString();
        $info->validatedAt = DateTime::createNow()->toString();

        return $info;
    }

    /**
     * Flag commonly-required Meta scopes that are absent from the token.
     *
     * This is advisory only — a System User may legitimately be used for a
     * subset of products (e.g. Ads only). But missing WhatsApp scopes are the
     * usual cause of `(#200) You do not have permission to access this field`
     * when discovering owned_whatsapp_business_accounts, so we surface them.
     *
     * @param string[] $scopes
     * @return string[] Missing scope names.
     */
    private function buildScopeWarnings(array $scopes): array
    {
        // Scopes that downstream Meta integrations in this app commonly need.
        $recommended = [
            'whatsapp_business_management',
            'whatsapp_business_messaging',
            'business_management',
        ];

        $missing = [];

        foreach ($recommended as $scope) {
            if (!in_array($scope, $scopes, true)) {
                $missing[] = $scope;
            }
        }

        return $missing;
    }

    /**
     * @return array<string, mixed>
     * @throws Error
     */
    private function httpGet(string $url): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->log->error("MetaSystemUserTokenService: cURL error: {$curlError}");

            throw new Error('Could not reach Meta Graph API.');
        }

        if (!is_string($response)) {
            throw new Error('Empty response from Meta Graph API.');
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            throw new Error('Could not decode Meta Graph API response.');
        }

        if ($httpCode >= 400) {
            $message = $decoded['error']['message'] ?? ('HTTP ' . $httpCode);

            // A 190 / invalid-token style error is a client problem, but
            // /debug_token returns is_valid=false rather than 4xx for that.
            throw new Error('Meta Graph API error: ' . $message);
        }

        return $decoded;
    }

    /**
     * Form-urlencoded POST to the Graph API.
     *
     * @param array<string, string> $params
     * @return array<string, mixed>
     * @throws Error
     */
    private function httpPost(string $url, array $params): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->log->error("MetaSystemUserTokenService: cURL error: {$curlError}");

            throw new Error('Could not reach Meta Graph API.');
        }

        if (!is_string($response)) {
            throw new Error('Empty response from Meta Graph API.');
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            throw new Error('Could not decode Meta Graph API response.');
        }

        if ($httpCode >= 400) {
            $error = $decoded['error'] ?? [];
            $message = $error['message'] ?? ('HTTP ' . $httpCode);
            $code = $error['code'] ?? null;
            $sub = $error['error_subcode'] ?? null;

            $detail = $message
                . ($code !== null ? " (code {$code}" . ($sub !== null ? ", subcode {$sub}" : '') . ')' : '');

            throw new Error('Meta Graph API error: ' . $detail);
        }

        return $decoded;
    }
}
