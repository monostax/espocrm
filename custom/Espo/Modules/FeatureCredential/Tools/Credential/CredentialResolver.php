<?php

namespace Espo\Modules\FeatureCredential\Tools\Credential;

use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\NotFound;
use Espo\ORM\EntityManager;
use Espo\Tools\OAuth\TokensProvider;
use Espo\Tools\OAuth\Exceptions\AccountNotFound;
use Espo\Tools\OAuth\Exceptions\NoToken;
use Espo\Tools\OAuth\Exceptions\ProviderNotAvailable;
use Espo\Tools\OAuth\Exceptions\TokenObtainingFailure;
use stdClass;

/**
 * Resolves a Credential into its complete configuration by merging
 * static config values with live OAuth tokens (when applicable).
 */
class CredentialResolver
{
    public function __construct(
        private EntityManager $entityManager,
        private TokensProvider $tokensProvider,
    ) {}

    /**
     * Resolve a credential by ID, returning the full merged configuration.
     *
     * For non-OAuth credentials, returns the parsed config JSON as-is.
     * For OAuth-backed credentials, merges the static config with fresh
     * (auto-refreshed) tokens from the linked OAuthAccount, using the
     * tokenFieldMapping defined on the CredentialType.
     *
     * @throws NotFound
     * @throws Error
     */
    public function resolve(string $credentialId): stdClass
    {
        $credential = $this->entityManager->getEntityById('Credential', $credentialId);

        if (!$credential) {
            throw new NotFound("Credential '{$credentialId}' not found.");
        }

        if (!$credential->get('isActive')) {
            throw new Error("Credential '{$credentialId}' is not active.");
        }

        $configRaw = $credential->get('config') ?: '{}';
        $config = json_decode($configRaw);

        if (!$config instanceof stdClass) {
            $config = new stdClass();
        }

        $oAuthAccountId = $credential->get('oAuthAccountId');

        if (!$oAuthAccountId) {
            return $config;
        }

        $credentialTypeId = $credential->get('credentialTypeId');

        if (!$credentialTypeId) {
            return $config;
        }

        $credentialType = $this->entityManager->getEntityById('CredentialType', $credentialTypeId);

        if (!$credentialType) {
            return $config;
        }

        $mappingRaw = $credentialType->get('tokenFieldMapping');

        if (!$mappingRaw) {
            return $config;
        }

        $mapping = is_string($mappingRaw)
            ? json_decode($mappingRaw, true)
            : (array) $mappingRaw;

        if (empty($mapping)) {
            return $config;
        }

        try {
            $tokens = $this->tokensProvider->get($oAuthAccountId);
        } catch (AccountNotFound $e) {
            throw new Error("OAuth account not found for credential '{$credentialId}'.", 0, $e);
        } catch (ProviderNotAvailable $e) {
            throw new Error("OAuth provider not available for credential '{$credentialId}'.", 0, $e);
        } catch (NoToken $e) {
            throw new Error("No OAuth token available for credential '{$credentialId}'. Connect the OAuth account first.", 0, $e);
        } catch (TokenObtainingFailure $e) {
            throw new Error("Failed to refresh OAuth token for credential '{$credentialId}': " . $e->getMessage(), 0, $e);
        }

        foreach ($mapping as $configField => $tokenField) {
            $config->$configField = match ($tokenField) {
                'access_token' => $tokens->getAccessToken(),
                'refresh_token' => $tokens->getRefreshToken(),
                'expires_at' => $tokens->getExpiresAt()?->toString(),
                default => null,
            };
        }

        return $config;
    }

    /**
     * Check whether a credential is OAuth-backed.
     */
    public function isOAuthBacked(string $credentialId): bool
    {
        $credential = $this->entityManager->getEntityById('Credential', $credentialId);

        if (!$credential) {
            return false;
        }

        return !empty($credential->get('oAuthAccountId'));
    }
}
