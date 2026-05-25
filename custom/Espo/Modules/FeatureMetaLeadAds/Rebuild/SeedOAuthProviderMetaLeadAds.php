<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaLeadAds\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\Entities\OAuthProvider;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * Rebuild action to seed the Meta Lead Ads OAuth provider.
 *
 * Ensures the provider row exists with the correct Facebook Login
 * authorization/token endpoints, scopes, and authorization params.
 *
 * Distinct from `meta-instagram` (which uses api.instagram.com).
 *
 * Does NOT overwrite client_id / client_secret / webhookVerifyToken if the
 * provider already exists (those are environment-specific secrets set via
 * the admin UI — each tenant has its own Meta App with own credentials).
 *
 * Required permissions for the OAuth scopes:
 *   - leads_retrieval        — read lead data (REQUIRES app review for prod)
 *   - pages_show_list        — list pages user manages
 *   - pages_manage_metadata  — subscribe app to leadgen field
 *   - pages_manage_ads       — subscribe app to leadgen webhook on page
 *   - pages_read_engagement  — read page metadata
 */
class SeedOAuthProviderMetaLeadAds implements RebuildAction
{
    private const PROVIDER_ID   = 'msx_meta_lead_01';
    private const PROVIDER_NAME = 'Meta (Lead Ads)';
    private const PROVIDER_DISCRIMINATOR = 'meta-leadads';

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function process(): void
    {
        $this->log->info('FeatureMetaLeadAds: Seeding OAuth provider for Meta Lead Ads...');

        $existing = $this->entityManager
            ->getEntityById(OAuthProvider::ENTITY_TYPE, self::PROVIDER_ID);

        if ($existing instanceof OAuthProvider) {
            $this->updateProvider($existing);
            $this->log->info('FeatureMetaLeadAds: OAuth provider "' . self::PROVIDER_NAME . '" updated.');
        } else {
            $this->createProvider();
            $this->log->info('FeatureMetaLeadAds: OAuth provider "' . self::PROVIDER_NAME . '" created.');
        }
    }

    private function updateProvider(OAuthProvider $provider): void
    {
        // Refresh endpoints/scopes only — DO NOT touch isGloballyShared, name,
        // or credentials. The admin may have toggled this row off-global to
        // make it tenant-specific (BYOA), or renamed it; we must not clobber
        // those operator decisions on every rebuild.
        $provider->set('provider',             self::PROVIDER_DISCRIMINATOR);
        $provider->set('authorizationEndpoint', 'https://www.facebook.com/v21.0/dialog/oauth');
        $provider->set('tokenEndpoint',        'https://graph.facebook.com/v21.0/oauth/access_token');
        $provider->set('scopes',               $this->getScopes());
        $provider->set('authorizationParams',  $this->getAuthorizationParams());
        $provider->set('scopeSeparator',       ',');

        $this->entityManager->saveEntity($provider);
    }

    private function createProvider(): void
    {
        $provider = $this->entityManager->getNewEntity(OAuthProvider::ENTITY_TYPE);

        $provider->set('id',                   self::PROVIDER_ID);
        $provider->set('name',                 self::PROVIDER_NAME);
        $provider->set('provider',             self::PROVIDER_DISCRIMINATOR);
        $provider->set('isActive',             true);
        $provider->set('isGloballyShared',     true);
        $provider->set('authorizationEndpoint', 'https://www.facebook.com/v21.0/dialog/oauth');
        $provider->set('tokenEndpoint',        'https://graph.facebook.com/v21.0/oauth/access_token');
        $provider->set('scopes',               $this->getScopes());
        $provider->set('authorizationParams',  $this->getAuthorizationParams());
        $provider->set('scopeSeparator',       ',');

        $this->entityManager->saveEntity($provider);
    }

    /**
     * @return string[]
     */
    private function getScopes(): array
    {
        return [
            'leads_retrieval',
            'pages_show_list',
            'pages_manage_metadata',
            'pages_manage_ads',
            'pages_read_engagement',
        ];
    }

    private function getAuthorizationParams(): stdClass
    {
        $params = new stdClass();
        $params->response_type = 'code';
        $params->display       = 'popup';

        return $params;
    }
}
