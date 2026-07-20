<?php

namespace Espo\Modules\FeatureIntegrationGmail\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\Entities\OAuthProvider;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * Rebuild action to seed the Google Gmail OAuth provider.
 *
 * Ensures the provider is configured with the correct endpoints, scopes, and
 * authorization params (access_type=offline for refresh token support).
 *
 * Does NOT overwrite client_id/client_secret if the provider already exists
 * (those are environment-specific secrets set via the admin UI).
 */
class SeedOAuthProviderGmail implements RebuildAction
{
    public const PROVIDER_ID = 'msx_gmail_01';
    public const PROVIDER_NAME = 'Google Gmail';
    public const PROVIDER_DISCRIMINATOR = 'google-gmail';

    /**
     * Full mail scope required for IMAP/SMTP XOAUTH2.
     * Gmail REST-only scopes (gmail.readonly / gmail.send) are NOT enough.
     */
    public const SCOPE_MAIL = 'https://mail.google.com/';

    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    public function process(): void
    {
        $this->log->info('FeatureIntegrationGmail: Seeding OAuth provider for Google Gmail...');

        $teamIds = $this->getAllTeamIds();

        $existing = $this->entityManager
            ->getEntityById(OAuthProvider::ENTITY_TYPE, self::PROVIDER_ID);

        if ($existing) {
            $this->updateProvider($existing, $teamIds);
            $this->log->info('FeatureIntegrationGmail: OAuth provider "' . self::PROVIDER_NAME . '" updated.');
        } else {
            $this->createProvider($teamIds);
            $this->log->info('FeatureIntegrationGmail: OAuth provider "' . self::PROVIDER_NAME . '" created.');
        }
    }

    /**
     * @param string[] $teamIds
     */
    private function updateProvider(OAuthProvider $provider, array $teamIds): void
    {
        $provider->set('name', self::PROVIDER_NAME);
        $provider->set('provider', self::PROVIDER_DISCRIMINATOR);
        $provider->set('isActive', true);
        $provider->set('isGloballyShared', true);
        $provider->set('authorizationEndpoint', 'https://accounts.google.com/o/oauth2/v2/auth');
        $provider->set('tokenEndpoint', 'https://oauth2.googleapis.com/token');
        $provider->set('authorizationPrompt', 'consent');
        $provider->set('scopes', $this->getScopes());
        $provider->set('authorizationParams', $this->getAuthorizationParams());
        $provider->set('teamsIds', $teamIds);

        $this->entityManager->saveEntity($provider);
    }

    /**
     * @param string[] $teamIds
     */
    private function createProvider(array $teamIds): void
    {
        $provider = $this->entityManager->getNewEntity(OAuthProvider::ENTITY_TYPE);

        $provider->set('id', self::PROVIDER_ID);
        $provider->set('name', self::PROVIDER_NAME);
        $provider->set('provider', self::PROVIDER_DISCRIMINATOR);
        $provider->set('isActive', true);
        $provider->set('isGloballyShared', true);
        $provider->set('authorizationEndpoint', 'https://accounts.google.com/o/oauth2/v2/auth');
        $provider->set('tokenEndpoint', 'https://oauth2.googleapis.com/token');
        $provider->set('authorizationPrompt', 'consent');
        $provider->set('scopes', $this->getScopes());
        $provider->set('authorizationParams', $this->getAuthorizationParams());
        $provider->set('teamsIds', $teamIds);

        $this->entityManager->saveEntity($provider);
    }

    /**
     * @return string[]
     */
    private function getAllTeamIds(): array
    {
        $teams = $this->entityManager->getRDBRepository('Team')
            ->select(['id'])
            // NB: must be boolean false (not int 0) — on Postgres the ORM
            // renders int as a literal and "boolean = integer" is an error.
            ->where(['deleted' => false])
            ->find();

        $ids = [];
        foreach ($teams as $team) {
            $ids[] = $team->get('id');
        }

        return $ids;
    }

    /**
     * @return string[]
     */
    private function getScopes(): array
    {
        return [
            // OpenID / identity (optional but useful for email claim)
            'openid',
            'email',
            'https://www.googleapis.com/auth/userinfo.email',
            // Full mail access — required for IMAP/SMTP XOAUTH2
            self::SCOPE_MAIL,
        ];
    }

    private function getAuthorizationParams(): stdClass
    {
        $params = new stdClass();
        $params->access_type = 'offline';

        return $params;
    }
}
