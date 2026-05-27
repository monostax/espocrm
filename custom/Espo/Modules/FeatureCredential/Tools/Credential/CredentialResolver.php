<?php

namespace Espo\Modules\FeatureCredential\Tools\Credential;

use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\NotFound;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Tools\OAuth\TokensProvider;
use Espo\Tools\OAuth\Exceptions\AccountNotFound;
use Espo\Tools\OAuth\Exceptions\NoToken;
use Espo\Tools\OAuth\Exceptions\ProviderNotAvailable;
use Espo\Tools\OAuth\Exceptions\TokenObtainingFailure;
use stdClass;

/**
 * Resolves a Credential into its complete configuration by:
 *
 *   1. Loading the row + parsing `config` JSON.
 *   2. Transparently decrypting any field listed in `CredentialType.encryptionFields`
 *      that carries the `enc:v1:` marker. Legacy plaintext rows pass through unchanged.
 *   3. Merging static config values with live OAuth tokens (when the
 *      Credential is OAuth-backed).
 *
 * Used by every server-side consumer that needs to *actually authenticate*
 * with a third-party service (Medx, WhatsApp, Chatwoot, ClinicaNasNuvens,
 * SimplesAgenda, etc). Equivalent at-read-time to the `ConfigLoader`
 * field processor used during API serialization.
 */
class CredentialResolver
{
    public function __construct(
        private EntityManager $entityManager,
        private TokensProvider $tokensProvider,
        private CredentialConfigCipher $cipher,
    ) {}

    /**
     * Resolve a credential by ID, returning the full merged configuration.
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

        $credentialTypeId = $credential->get('credentialTypeId');

        $credentialType = $credentialTypeId
            ? $this->entityManager->getEntityById('CredentialType', $credentialTypeId)
            : null;

        // Transparent decrypt of fields listed in encryptionFields.
        $config = $this->cipher->decryptFields($config, $credentialType);

        $oAuthAccountId = $credential->get('oAuthAccountId');

        if (!$oAuthAccountId || !$credentialType) {
            return $config;
        }

        return $this->mergeOAuthTokens($config, $credentialType, $credentialId, (string) $oAuthAccountId);
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

    /**
     * @throws Error
     */
    private function mergeOAuthTokens(
        stdClass $config,
        Entity $credentialType,
        string $credentialId,
        string $oAuthAccountId,
    ): stdClass {
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
}
