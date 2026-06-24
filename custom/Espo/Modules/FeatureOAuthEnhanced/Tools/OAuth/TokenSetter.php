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

namespace Espo\Modules\FeatureOAuthEnhanced\Tools\OAuth;

use Espo\Core\Field\DateTime;
use Espo\Core\Utils\Crypt;
use Espo\Entities\OAuthAccount;
use Espo\Tools\OAuth\TokenSetter as BaseTokenSetter;
use League\OAuth2\Client\Token\AccessTokenInterface;

/**
 * Overrides the core TokenSetter to preserve the existing refresh token when
 * the OAuth provider does not return a new one during token refresh.
 *
 * Google Calendar only returns a refresh_token on the initial consent flow.
 * On subsequent refresh_token grant requests, the refresh_token field is
 * omitted from the response. The core TokenSetter overwrites the stored
 * refresh token with null in this case, making future refreshs impossible
 * and locking the account out once the access token expires.
 *
 * This override falls back to the account's existing (encrypted) refresh
 * token when the response does not include one.
 */
class TokenSetter extends BaseTokenSetter
{
    public function __construct(
        private Crypt $crypt,
    ) {
        parent::__construct($crypt);
    }

    public function set(OAuthAccount $account, AccessTokenInterface $tokens): void
    {
        $accessToken = $this->crypt->encrypt($tokens->getToken());

        $refreshToken = $tokens->getRefreshToken()
            ? $this->crypt->encrypt($tokens->getRefreshToken())
            : $account->getRefreshToken();

        $expires = $tokens->getExpires() !== null
            ? DateTime::fromTimestamp($tokens->getExpires())
            : null;

        $account->setAccessToken($accessToken);
        $account->setRefreshToken($refreshToken);
        $account->setExpiresAt($expires);
    }
}
