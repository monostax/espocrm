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

namespace Espo\Modules\FeatureOAuthEnhanced\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Log;
use Espo\Entities\OAuthProvider;
use Espo\Modules\FeatureOAuthEnhanced\Tools\OAuth\GenericProviderFactory;
use Espo\ORM\EntityManager;
use GuzzleHttp\Exception\GuzzleException;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Throwable;

/**
 * Production-side authorization-code exchange against msx_wa_coex_01.
 *
 * Local CRM subscriptions never see the Meta App clientSecret — only this
 * service decrypts it on production and talks to Meta directly.
 */
class MetaWhatsAppOAuthBrokerService
{
    public const PROVIDER_ID = 'msx_wa_coex_01';

    public function __construct(
        private EntityManager $entityManager,
        private GenericProviderFactory $genericProviderFactory,
        private Log $log,
    ) {}

    /**
     * @return array{
     *     access_token: string,
     *     token_type: string,
     *     expires_in?: int,
     *     refresh_token?: string,
     *     scope?: string
     * }
     * @throws Error
     */
    public function exchangeAuthorizationCode(string $code): array
    {
        /** @var ?OAuthProvider $provider */
        $provider = $this->entityManager
            ->getEntityById(OAuthProvider::ENTITY_TYPE, self::PROVIDER_ID);

        if (!$provider) {
            $this->log->error(
                'MetaWhatsAppOAuthBroker: provider ' . self::PROVIDER_ID . ' is missing.'
            );

            throw new Error('OAuth provider is not configured.');
        }

        if (!$provider->isActive()) {
            $this->log->error(
                'MetaWhatsAppOAuthBroker: provider ' . self::PROVIDER_ID . ' is inactive.'
            );

            throw new Error('OAuth provider is inactive.');
        }

        $genericProvider = $this->genericProviderFactory->createDirect($provider);

        try {
            $tokens = $genericProvider->getAccessToken('authorization_code', [
                'code' => $code,
            ]);
        } catch (IdentityProviderException $e) {
            $this->log->warning(
                'MetaWhatsAppOAuthBroker: Meta rejected authorization code exchange.'
            );

            throw new Error('invalid_grant', 400);
        } catch (GuzzleException $e) {
            $this->log->error(
                'MetaWhatsAppOAuthBroker: transport error exchanging authorization code.'
            );

            throw new Error('temporarily_unavailable', 503);
        } catch (Throwable $e) {
            $this->log->error(
                'MetaWhatsAppOAuthBroker: unexpected error exchanging authorization code.'
            );

            throw new Error('temporarily_unavailable', 503);
        }

        return $this->normalizeTokenResponse($tokens);
    }

    /**
     * @return array{
     *     access_token: string,
     *     token_type: string,
     *     expires_in?: int,
     *     refresh_token?: string,
     *     scope?: string
     * }
     */
    private function normalizeTokenResponse(AccessTokenInterface $tokens): array
    {
        $payload = [
            'access_token' => $tokens->getToken(),
            'token_type' => 'bearer',
        ];

        $expires = $tokens->getExpires();

        if (is_int($expires) && $expires > 0) {
            $expiresIn = $expires - time();
            $payload['expires_in'] = max(0, $expiresIn);
        }

        $refresh = $tokens->getRefreshToken();

        if (is_string($refresh) && $refresh !== '') {
            $payload['refresh_token'] = $refresh;
        }

        $values = method_exists($tokens, 'getValues') ? $tokens->getValues() : [];

        if (is_array($values) && isset($values['scope']) && is_string($values['scope']) && $values['scope'] !== '') {
            $payload['scope'] = $values['scope'];
        }

        return $payload;
    }
}
