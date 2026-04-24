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
 * Does NOT overwrite client_id/client_secret if the provider already
 * exists (those are environment-specific secrets set via the admin UI).
 */
class SeedOAuthProviderMetaWhatsApp implements RebuildAction
{
    private const PROVIDER_ID = 'msx_meta_wa_01';
    private const PROVIDER_NAME = 'Meta (WhatsApp)';

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
