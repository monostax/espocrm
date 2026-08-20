<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureGoogleAdsConversions\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\Entities\OAuthProvider;
use Espo\ORM\EntityManager;
use stdClass;

class SeedOAuthProviderGoogleDataManager implements RebuildAction
{
    public const PROVIDER_ID = 'msx_google_dm_01';
    public const PROVIDER_DISCRIMINATOR = 'google-data-manager';

    private const PROVIDER_NAME = 'Google Data Manager';
    private const AUTHORIZATION_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const SCOPE = 'https://www.googleapis.com/auth/datamanager';

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function process(): void
    {
        $existing = $this->entityManager->getEntityById(OAuthProvider::ENTITY_TYPE, self::PROVIDER_ID);

        if ($existing instanceof OAuthProvider) {
            $this->configure($existing);
            $this->entityManager->saveEntity($existing);
            $this->log->info('FeatureGoogleAdsConversions: Google Data Manager OAuth provider updated.');

            return;
        }

        /** @var OAuthProvider $provider */
        $provider = $this->entityManager->getNewEntity(OAuthProvider::ENTITY_TYPE);
        $provider->set([
            'id' => self::PROVIDER_ID,
            'name' => self::PROVIDER_NAME,
            'provider' => self::PROVIDER_DISCRIMINATOR,
            'isActive' => true,
            'isGloballyShared' => true,
        ]);
        $this->configure($provider);

        $this->entityManager->saveEntity($provider);
        $this->log->info('FeatureGoogleAdsConversions: Google Data Manager OAuth provider created.');
    }

    private function configure(OAuthProvider $provider): void
    {
        // Existing rows retain operator-owned name, sharing, activation, and credentials.
        $provider->set('provider', self::PROVIDER_DISCRIMINATOR);
        $provider->set('authorizationEndpoint', self::AUTHORIZATION_ENDPOINT);
        $provider->set('tokenEndpoint', self::TOKEN_ENDPOINT);
        $provider->set('authorizationPrompt', 'consent');
        $provider->set('scopes', [self::SCOPE]);
        $provider->set('authorizationParams', $this->authorizationParams());
    }

    private function authorizationParams(): stdClass
    {
        $params = new stdClass();
        $params->access_type = 'offline';
        $params->include_granted_scopes = true;

        return $params;
    }
}
