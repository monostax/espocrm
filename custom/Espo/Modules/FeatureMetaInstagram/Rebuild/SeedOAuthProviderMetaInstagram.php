<?php

namespace Espo\Modules\FeatureMetaInstagram\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\Entities\OAuthProvider;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * Rebuild action to seed the Meta Instagram OAuth provider.
 *
 * Ensures the provider row exists with the correct authorization/token
 * endpoints, scopes, and authorization params for Instagram Login.
 *
 * Does NOT overwrite client_id/client_secret if the provider already
 * exists (those are environment-specific secrets set via the admin UI).
 */
class SeedOAuthProviderMetaInstagram implements RebuildAction
{
    private const PROVIDER_ID = 'msx_meta_ig_01';
    private const PROVIDER_NAME = 'Meta (Instagram)';

    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    public function process(): void
    {
        $this->log->info('FeatureMetaInstagram: Seeding OAuth provider for Meta Instagram...');

        $existing = $this->entityManager
            ->getEntityById(OAuthProvider::ENTITY_TYPE, self::PROVIDER_ID);

        if ($existing) {
            $this->updateProvider($existing);
            $this->log->info('FeatureMetaInstagram: OAuth provider "' . self::PROVIDER_NAME . '" updated.');
        } else {
            $this->createProvider();
            $this->log->info('FeatureMetaInstagram: OAuth provider "' . self::PROVIDER_NAME . '" created.');
        }
    }

    private function updateProvider(OAuthProvider $provider): void
    {
        $provider->set('name', self::PROVIDER_NAME);
        $provider->set('provider', 'meta-instagram');
        $provider->set('isActive', true);
        $provider->set('isGloballyShared', true);
        $provider->set('authorizationEndpoint', 'https://api.instagram.com/oauth/authorize');
        $provider->set('tokenEndpoint', 'https://api.instagram.com/oauth/access_token');
        $provider->set('scopes', $this->getScopes());
        $provider->set('authorizationParams', $this->getAuthorizationParams());

        $this->entityManager->saveEntity($provider);
    }

    private function createProvider(): void
    {
        $provider = $this->entityManager->getNewEntity(OAuthProvider::ENTITY_TYPE);

        $provider->set('id', self::PROVIDER_ID);
        $provider->set('name', self::PROVIDER_NAME);
        $provider->set('provider', 'meta-instagram');
        $provider->set('isActive', true);
        $provider->set('isGloballyShared', true);
        $provider->set('authorizationEndpoint', 'https://api.instagram.com/oauth/authorize');
        $provider->set('tokenEndpoint', 'https://api.instagram.com/oauth/access_token');
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
            'instagram_business_basic',
            'instagram_business_manage_messages',
        ];
    }

    private function getAuthorizationParams(): stdClass
    {
        $params = new stdClass();
        $params->enable_fb_login = '0';
        $params->force_authentication = '1';
        $params->response_type = 'code';

        return $params;
    }
}
