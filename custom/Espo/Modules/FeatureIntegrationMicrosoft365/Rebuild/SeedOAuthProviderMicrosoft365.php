<?php

namespace Espo\Modules\FeatureIntegrationMicrosoft365\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\Entities\OAuthProvider;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * Rebuild action to seed the Microsoft 365 OAuth provider.
 *
 * Ensures the provider is configured with the correct endpoints and scopes
 * for IMAP/SMTP XOAUTH2 (Office 365).
 *
 * clientId: CRM_MICROSOFT_365_OAUTH_CLIENT_ID env or DEFAULT_CLIENT_ID when empty.
 * clientSecret: CRM_MICROSOFT_365_OAUTH_CLIENT_SECRET env when empty.
 * Never overwrites credentials already present on the provider row.
 */
class SeedOAuthProviderMicrosoft365 implements RebuildAction
{
    public const PROVIDER_ID = 'msx_m365_01';
    public const PROVIDER_NAME = 'Microsoft 365';
    public const PROVIDER_DISCRIMINATOR = 'microsoft-365';

    /**
     * Public Azure AD Application (client) ID for the Monostax Microsoft 365
     * mail app. Not a secret. Overridden at rebuild by
     * CRM_MICROSOFT_365_OAUTH_CLIENT_ID when set (gitops per environment).
     */
    public const DEFAULT_CLIENT_ID = '5ba3294b-8d07-41c1-a9c4-c920b7ff4291';

    /**
     * IMAP XOAUTH2 scope for Outlook / Microsoft 365 mailboxes.
     */
    public const SCOPE_IMAP = 'https://outlook.office365.com/IMAP.AccessAsUser.All';

    /**
     * SMTP XOAUTH2 scope for Outlook / Microsoft 365 mailboxes.
     */
    public const SCOPE_SMTP = 'https://outlook.office365.com/SMTP.Send';

    public const AUTH_ENDPOINT = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';
    public const TOKEN_ENDPOINT = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';

    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    public function process(): void
    {
        $this->log->info('FeatureIntegrationMicrosoft365: Seeding OAuth provider for Microsoft 365...');

        $teamIds = $this->getAllTeamIds();

        $existing = $this->entityManager
            ->getEntityById(OAuthProvider::ENTITY_TYPE, self::PROVIDER_ID);

        if ($existing) {
            $this->updateProvider($existing, $teamIds);
            $this->log->info('FeatureIntegrationMicrosoft365: OAuth provider "' . self::PROVIDER_NAME . '" updated.');
        } else {
            $this->createProvider($teamIds);
            $this->log->info('FeatureIntegrationMicrosoft365: OAuth provider "' . self::PROVIDER_NAME . '" created.');
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
        $provider->set('authorizationEndpoint', self::AUTH_ENDPOINT);
        $provider->set('tokenEndpoint', self::TOKEN_ENDPOINT);
        $provider->set('authorizationPrompt', 'consent');
        $provider->set('scopes', $this->getScopes());
        $provider->set('authorizationParams', $this->getAuthorizationParams());
        $provider->set('teamsIds', $teamIds);

        // Never overwrite admin-chosen or previously seeded credentials.
        if (!$provider->get('clientId')) {
            $provider->set('clientId', $this->resolveClientId());
        }

        $secret = $this->resolveClientSecret();

        if ($secret !== null && !$provider->get('clientSecret')) {
            $provider->set('clientSecret', $secret);
        }

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
        $provider->set('authorizationEndpoint', self::AUTH_ENDPOINT);
        $provider->set('tokenEndpoint', self::TOKEN_ENDPOINT);
        $provider->set('authorizationPrompt', 'consent');
        $provider->set('scopes', $this->getScopes());
        $provider->set('authorizationParams', $this->getAuthorizationParams());
        $provider->set('clientId', $this->resolveClientId());
        $provider->set('teamsIds', $teamIds);

        $secret = $this->resolveClientSecret();

        if ($secret !== null) {
            $provider->set('clientSecret', $secret);
        }

        $this->entityManager->saveEntity($provider);
    }

    /**
     * Prefer CRM_MICROSOFT_365_OAUTH_CLIENT_ID (gitops) over built-in default.
     */
    private function resolveClientId(): string
    {
        $fromEnv = getenv('CRM_MICROSOFT_365_OAUTH_CLIENT_ID');

        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        return self::DEFAULT_CLIENT_ID;
    }

    /**
     * CRM_MICROSOFT_365_OAUTH_CLIENT_SECRET from gitops secrets.nix.
     * Null when unset/empty so we never clear an existing DB secret.
     */
    private function resolveClientSecret(): ?string
    {
        $fromEnv = getenv('CRM_MICROSOFT_365_OAUTH_CLIENT_SECRET');

        if (!is_string($fromEnv) || $fromEnv === '') {
            return null;
        }

        return $fromEnv;
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
            'openid',
            'email',
            'offline_access',
            self::SCOPE_IMAP,
            self::SCOPE_SMTP,
        ];
    }

    private function getAuthorizationParams(): stdClass
    {
        // response_mode=query keeps the auth code in the query string so the
        // parent window popup poller can read it (same as Google).
        $params = new stdClass();
        $params->response_mode = 'query';

        return $params;
    }
}
