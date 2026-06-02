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
 * Rebuild action to seed the Meta WhatsApp **Coexistence** OAuth provider.
 *
 * This provider is a sibling to `meta-whatsapp` and powers the v4
 * Embedded Signup flow with `featureType = whatsapp_business_app_onboarding`.
 *
 * It is intentionally a separate `OAuthProvider` row (and a separate
 * `provider` enum value) so admins can:
 *   - keep distinct `client_id` / `client_secret` per Meta App if needed,
 *   - bind the FB JS SDK launcher to a different Facebook Login for
 *     Business **configuration** (`configurationId`) than the legacy
 *     "Cloud API only" flow,
 *   - and route different `ChatwootInboxIntegration.channelType` values
 *     (`whatsappCloudApi` vs `whatsappCoexistence`) to the right provider.
 *
 * The token endpoint, scopes, and authorization endpoint are identical
 * to the regular `meta-whatsapp` provider because Embedded Signup still
 * goes through Facebook's authorization-code grant — the only thing the
 * customer sees differently is the popup contents, controlled by the
 * FB JS SDK and the `configurationId`.
 *
 * Does NOT overwrite client_id/client_secret if the provider already
 * exists.
 */
class SeedOAuthProviderMetaWhatsAppCoexistence implements RebuildAction
{
    private const PROVIDER_ID = 'msx_wa_coex_01';
    private const PROVIDER_NAME = 'Meta (WhatsApp Coexistence)';

    /**
     * Default Facebook Login for Business configuration ID for the
     * Monostax-shared Meta App, copied from Meta Business Suite.
     *
     * Admins can override this in Admin → OAuth Providers; the seed only
     * writes this value when the row is being created for the first time.
     */
    private const DEFAULT_CONFIGURATION_ID = '1238481398208023';

    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    public function process(): void
    {
        $this->log->info('FeatureMetaWhatsAppBusiness: Seeding OAuth provider for Meta WhatsApp Coexistence...');

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
        $provider->set('provider', 'meta-whatsapp-coexistence');
        $provider->set('isActive', true);
        $provider->set('isGloballyShared', true);
        $provider->set('authorizationEndpoint', 'https://www.facebook.com/v22.0/dialog/oauth');
        $provider->set('tokenEndpoint', 'https://graph.facebook.com/v22.0/oauth/access_token');
        $provider->set('scopes', $this->getScopes());
        $provider->set('authorizationParams', $this->getAuthorizationParams());
        $provider->set('embeddedSignupVersion', 'v4');

        // Seed the default configurationId only if not already set;
        // never overwrite an admin-chosen one.
        if (!$provider->get('configurationId')) {
            $provider->set('configurationId', self::DEFAULT_CONFIGURATION_ID);
        }

        $this->entityManager->saveEntity($provider);
    }

    private function createProvider(): void
    {
        $provider = $this->entityManager->getNewEntity(OAuthProvider::ENTITY_TYPE);

        $provider->set('id', self::PROVIDER_ID);
        $provider->set('name', self::PROVIDER_NAME);
        $provider->set('provider', 'meta-whatsapp-coexistence');
        $provider->set('isActive', true);
        $provider->set('isGloballyShared', true);
        $provider->set('authorizationEndpoint', 'https://www.facebook.com/v22.0/dialog/oauth');
        $provider->set('tokenEndpoint', 'https://graph.facebook.com/v22.0/oauth/access_token');
        $provider->set('scopes', $this->getScopes());
        $provider->set('authorizationParams', $this->getAuthorizationParams());
        $provider->set('embeddedSignupVersion', 'v4');
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
