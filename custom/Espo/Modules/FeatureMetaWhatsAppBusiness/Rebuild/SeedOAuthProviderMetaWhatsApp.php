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

namespace Espo\Modules\FeatureMetaWhatsAppBusiness\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\Entities\OAuthProvider;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * Rebuild action to seed the Meta WhatsApp OAuth provider.
 *
 * Ensures the provider row exists with the correct authorization/token
 * endpoints, scopes, and authorization params for Meta (WhatsApp Business)
 * OAuth.
 *
 * Seeds the public Monostax Meta App clientId + Embedded Signup
 * configurationId when empty (or still a known-stale default). Does NOT
 * overwrite clientSecret (environment-specific, set via Admin UI / brokered
 * remotely for Coexistence in local environments).
 */
class SeedOAuthProviderMetaWhatsApp implements RebuildAction
{
    private const PROVIDER_ID = 'msx_meta_wa_01';
    private const PROVIDER_NAME = 'Meta (WhatsApp)';

    /** Public Meta App ID (not a secret). */
    private const DEFAULT_CLIENT_ID = '1914611219187440';

    /**
     * Facebook Login for Business configuration ID (config_id) used by the
     * FB JS SDK Embedded Signup launcher. Same Monostax-shared Meta App
     * config as production msx_wa_coex_01.
     */
    private const DEFAULT_CONFIGURATION_ID = '25911734595153620';

    /** @var string[] */
    private const STALE_CONFIGURATION_IDS = [
        '1238481398208023',
    ];

    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    public function process(): void
    {
        $this->log->info('FeatureMetaWhatsAppBusiness: Seeding OAuth provider for Meta WhatsApp...');

        $existing = $this->entityManager
            ->getEntityById(OAuthProvider::ENTITY_TYPE, self::PROVIDER_ID);

        if ($existing) {
            $this->updateProvider($existing);
            $this->log->info('FeatureMetaWhatsAppBusiness: OAuth provider "' . self::PROVIDER_NAME . '" updated.');
        } else {
            $this->createProvider();
            $this->log->info('FeatureMetaWhatsAppBusiness: OAuth provider "' . self::PROVIDER_NAME . '" created.');
        }
    }

    private function updateProvider(OAuthProvider $provider): void
    {
        $provider->set('name', self::PROVIDER_NAME);
        $provider->set('provider', 'meta-whatsapp');
        $provider->set('isActive', true);
        $provider->set('isGloballyShared', true);
        $provider->set('authorizationEndpoint', 'https://www.facebook.com/v22.0/dialog/oauth');
        $provider->set('tokenEndpoint', 'https://graph.facebook.com/v22.0/oauth/access_token');
        $provider->set('scopes', $this->getScopes());
        $provider->set('authorizationParams', $this->getAuthorizationParams());
        $provider->set('embeddedSignupVersion', 'v4');

        if (!$provider->get('clientId')) {
            $provider->set('clientId', self::DEFAULT_CLIENT_ID);
        }

        $configurationId = (string) ($provider->get('configurationId') ?? '');

        if (
            $configurationId === '' ||
            in_array($configurationId, self::STALE_CONFIGURATION_IDS, true)
        ) {
            $provider->set('configurationId', self::DEFAULT_CONFIGURATION_ID);
        }

        $this->entityManager->saveEntity($provider);
    }

    private function createProvider(): void
    {
        $provider = $this->entityManager->getNewEntity(OAuthProvider::ENTITY_TYPE);

        $provider->set('id', self::PROVIDER_ID);
        $provider->set('name', self::PROVIDER_NAME);
        $provider->set('provider', 'meta-whatsapp');
        $provider->set('isActive', true);
        $provider->set('isGloballyShared', true);
        $provider->set('authorizationEndpoint', 'https://www.facebook.com/v22.0/dialog/oauth');
        $provider->set('tokenEndpoint', 'https://graph.facebook.com/v22.0/oauth/access_token');
        $provider->set('scopes', $this->getScopes());
        $provider->set('authorizationParams', $this->getAuthorizationParams());
        $provider->set('embeddedSignupVersion', 'v4');
        $provider->set('clientId', self::DEFAULT_CLIENT_ID);
        $provider->set('configurationId', self::DEFAULT_CONFIGURATION_ID);

        $this->entityManager->saveEntity($provider);
    }

    /**
     * @return string[]
     */
    private function getScopes(): array
    {
        return [
            'whatsapp_business_management',
            'whatsapp_business_messaging',
            'business_management',
        ];
    }

    private function getAuthorizationParams(): stdClass
    {
        $params = new stdClass();
        $params->response_type = 'code';

        return $params;
    }
}
