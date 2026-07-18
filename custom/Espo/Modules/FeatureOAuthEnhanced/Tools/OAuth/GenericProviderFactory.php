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

use Espo\Core\Utils\Crypt;
use Espo\Entities\OAuthProvider;
use Espo\Modules\FeatureOAuthEnhanced\Services\MetaWhatsAppOAuthBrokerService;
use Espo\Tools\OAuth\ConfigDataProvider;
use Espo\Tools\OAuth\GenericProviderFactory as BaseGenericProviderFactory;
use League\OAuth2\Client\Provider\GenericProvider;

/**
 * Overrides the core GenericProviderFactory to omit the redirect_uri
 * parameter from the token request for providers whose authorization code
 * is obtained through Meta's Embedded Signup (FB JS SDK `FB.login`).
 *
 * Codes returned by `FB.login` are not bound to our OAuth callback URL —
 * the SDK handles the dialog redirect internally. If the subsequent
 * authorization_code exchange includes a `redirect_uri`, Meta rejects it:
 *
 *   OAuthException code 100, error_subcode 36008
 *   "Error validating verification code. Please make sure your
 *    redirect_uri is identical to the one you used in the OAuth dialog
 *    request"
 *
 * Per Meta's Embedded Signup docs, such codes must be exchanged with
 * client_id, client_secret, and code only. league/oauth2-client drops
 * null-valued parameters (http_build_query), so leaving redirectUri unset
 * omits redirect_uri from the token request entirely.
 *
 * Local k3d/dev can additionally route **only** msx_wa_coex_01 through the
 * production Meta WhatsApp OAuth broker (see MetaWhatsAppOAuthBrokerConfig)
 * so the Meta App clientSecret never leaves production. Production keeps
 * META_WHATSAPP_OAUTH_BROKER_URL unset and exchanges directly with Meta.
 *
 * All other providers keep the standard behavior (redirect_uri included),
 * since their codes come from the regular authorization-code popup flow.
 */
class GenericProviderFactory extends BaseGenericProviderFactory
{
    /**
     * OAuthProvider.provider types whose codes come from FB.login
     * (Embedded Signup) rather than the redirect-based dialog.
     */
    private const EMBEDDED_SIGNUP_PROVIDERS = [
        'meta-whatsapp',
        'meta-whatsapp-coexistence',
    ];

    public function __construct(
        private ConfigDataProvider $configDataProvider,
        private Crypt $crypt,
        private MetaWhatsAppOAuthBrokerConfig $brokerConfig,
    ) {
        parent::__construct($configDataProvider, $crypt);
    }

    public function create(OAuthProvider $provider): GenericProvider
    {
        if ($this->shouldUseBroker($provider)) {
            return $this->createBroker($provider);
        }

        return $this->createDirect($provider);
    }

    /**
     * Always talk to the provider's real token endpoint (Meta), never the
     * broker. Used by the production broker endpoint itself so a mis-set
     * META_WHATSAPP_OAUTH_BROKER_URL cannot recurse.
     */
    public function createDirect(OAuthProvider $provider): GenericProvider
    {
        $secret = $this->crypt->decrypt($provider->getClientSecret());

        $options = [
            'clientId' => $provider->getClientId(),
            'clientSecret' => $secret,
            'urlAccessToken' => $provider->getTokenEndpoint(),

            'urlAuthorize' => 'dummy',
            'urlResourceOwnerDetails' => 'dummy',
        ];

        if (!$this->isEmbeddedSignup($provider)) {
            $options['redirectUri'] = $this->configDataProvider->getRedirectUri();
        }

        return new GenericProvider($options);
    }

    private function createBroker(OAuthProvider $provider): GenericProvider
    {
        [$url, $token] = $this->brokerConfig->requireClientCredentials();

        $clientId = $provider->get('clientId');

        return new GenericProvider(
            [
                'clientId' => is_string($clientId) && $clientId !== '' ? $clientId : 'broker',
                'clientSecret' => 'broker',
                'urlAccessToken' => $url,
                'urlAuthorize' => 'dummy',
                'urlResourceOwnerDetails' => 'dummy',
            ],
            [
                'optionProvider' => new BrokerAccessTokenOptionProvider($token),
            ]
        );
    }

    private function shouldUseBroker(OAuthProvider $provider): bool
    {
        if ($provider->getId() !== MetaWhatsAppOAuthBrokerService::PROVIDER_ID) {
            return false;
        }

        return $this->brokerConfig->isClientEnabled();
    }

    private function isEmbeddedSignup(OAuthProvider $provider): bool
    {
        return in_array(
            $provider->get('provider'),
            self::EMBEDDED_SIGNUP_PROVIDERS,
            true
        );
    }
}
