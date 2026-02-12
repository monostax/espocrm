<?php

namespace Espo\Modules\PackEnterprise\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\Entities\OAuthProvider;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * Rebuild action to seed the Google Calendar OAuth provider.
 * Ensures the provider is configured with the correct endpoints, scopes,
 * and authorization params (access_type=offline for refresh token support).
 *
 * Does NOT overwrite client_id/client_secret if the provider already exists
 * (those are environment-specific secrets set via the admin UI).
 */
class SeedOAuthProviderGoogleCalendar implements RebuildAction
{
    private const PROVIDER_ID = 'msx_google_cal_01';
    private const PROVIDER_NAME = 'Google Calendar';

    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    public function process(): void
    {
        $this->log->info('PackEnterprise: Seeding OAuth provider for Google Calendar...');

        $existing = $this->entityManager
            ->getEntityById(OAuthProvider::ENTITY_TYPE, self::PROVIDER_ID);

        if ($existing) {
            $this->updateProvider($existing);
            $this->log->info('PackEnterprise: OAuth provider "' . self::PROVIDER_NAME . '" updated.');
        } else {
            $this->createProvider();
            $this->log->info('PackEnterprise: OAuth provider "' . self::PROVIDER_NAME . '" created.');
        }
    }

    private function updateProvider(OAuthProvider $provider): void
    {
        $provider->set('name', self::PROVIDER_NAME);
        $provider->set('isActive', true);
        $provider->set('authorizationEndpoint', 'https://accounts.google.com/o/oauth2/v2/auth');
        $provider->set('tokenEndpoint', 'https://oauth2.googleapis.com/token');
        $provider->set('authorizationPrompt', 'consent');
        $provider->set('scopes', $this->getScopes());
        $provider->set('authorizationParams', $this->getAuthorizationParams());

        $this->entityManager->saveEntity($provider);
    }

    private function createProvider(): void
    {
        $provider = $this->entityManager->getNewEntity(OAuthProvider::ENTITY_TYPE);

        $provider->set('id', self::PROVIDER_ID);
        $provider->set('name', self::PROVIDER_NAME);
        $provider->set('isActive', true);
        $provider->set('authorizationEndpoint', 'https://accounts.google.com/o/oauth2/v2/auth');
        $provider->set('tokenEndpoint', 'https://oauth2.googleapis.com/token');
        $provider->set('authorizationPrompt', 'consent');
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
            'https://www.googleapis.com/auth/userinfo.profile',
            'https://www.googleapis.com/auth/user.emails.read',
            'https://www.googleapis.com/auth/calendar',
            'https://www.googleapis.com/auth/contacts',
            'https://www.google.com/m8/feeds',
        ];
    }

    private function getAuthorizationParams(): stdClass
    {
        $params = new stdClass();
        $params->access_type = 'offline';

        return $params;
    }
}
