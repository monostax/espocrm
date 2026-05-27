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

namespace Espo\Modules\FeatureCredential\Classes\FieldProcessing\Credential;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\FieldProcessing\Loader as LoaderInterface;
use Espo\Core\FieldProcessing\Loader\Params;
use Espo\Modules\FeatureCredential\Tools\Credential\CredentialConfigCipher;
use Espo\Tools\OAuth\TokensProvider;
use Espo\Tools\OAuth\Exceptions\AccountNotFound;
use Espo\Tools\OAuth\Exceptions\NoToken;
use Espo\Tools\OAuth\Exceptions\ProviderNotAvailable;
use Espo\Tools\OAuth\Exceptions\TokenObtainingFailure;
use Espo\Core\Utils\Log;
use stdClass;

/**
 * Decrypts secret fields and merges live OAuth tokens into `Credential.config`
 * at read time.
 *
 * # Pipeline
 *
 *   1. Parse the raw `config` JSON.
 *   2. {@see CredentialConfigCipher::decryptFields()} — transparent decrypt
 *      of any field listed in `CredentialType.encryptionFields` that carries
 *      the `enc:v1:` marker. Legacy plaintext rows pass through unchanged.
 *   3. If the Credential is linked to an OAuthAccount AND
 *      `CredentialType.tokenFieldMapping` is defined: overwrite the mapped
 *      config fields with live (auto-refreshed) tokens from `TokensProvider`.
 *      This is the existing behaviour; OAuth tokens always come from the
 *      live source, never from `config` at rest.
 *   4. Write the resolved config back onto the entity (in-memory only).
 *
 * # Why the write-back is important
 *
 * `readLoaderClassNameList` runs during API serialization. By overwriting
 * `entity.config` here we make standard CRUD GET responses return the fully
 * resolved (decrypted + OAuth-merged) config without callers needing to know
 * about encryption or OAuth. ACL is still enforced upstream by the standard
 * record service.
 *
 * # Safety
 *
 * Decryption failures and OAuth-token-fetch failures are non-fatal: a warning
 * is logged and the partially-resolved config is still returned. We don't
 * want a single broken OAuth account to break list-views of all Credentials.
 *
 * @implements LoaderInterface<Entity>
 */
class ConfigLoader implements LoaderInterface
{
    public function __construct(
        private EntityManager $entityManager,
        private TokensProvider $tokensProvider,
        private Log $log,
        private CredentialConfigCipher $cipher,
    ) {}

    public function process(Entity $entity, Params $params): void
    {
        $configRaw = $entity->get('config');

        if (!is_string($configRaw) || $configRaw === '') {
            return;
        }

        $config = json_decode($configRaw);

        if (!$config instanceof stdClass) {
            $config = new stdClass();
        }

        $credentialTypeId = $entity->get('credentialTypeId');

        $credentialType = $credentialTypeId
            ? $this->entityManager->getEntityById('CredentialType', $credentialTypeId)
            : null;

        // Step 1: transparent decrypt of secret fields.
        $config = $this->cipher->decryptFields($config, $credentialType);

        // Step 2: OAuth token merge (only when applicable).
        $oAuthAccountId = $entity->get('oAuthAccountId');

        if ($oAuthAccountId && $credentialType) {
            $this->mergeOAuthTokens($entity, $config, $credentialType, (string) $oAuthAccountId);
        }

        $entity->set('config', json_encode($config));
    }

    private function mergeOAuthTokens(
        Entity $entity,
        stdClass $config,
        Entity $credentialType,
        string $oAuthAccountId,
    ): void {
        $mappingRaw = $credentialType->get('tokenFieldMapping');

        if (!$mappingRaw) {
            return;
        }

        $mapping = is_string($mappingRaw)
            ? json_decode($mappingRaw, true)
            : (array) $mappingRaw;

        if (empty($mapping)) {
            return;
        }

        try {
            $tokens = $this->tokensProvider->get($oAuthAccountId);
        } catch (AccountNotFound | ProviderNotAvailable | NoToken | TokenObtainingFailure $e) {
            $this->log->warning(
                "Credential ConfigLoader: Could not resolve OAuth tokens for " .
                "Credential '{$entity->getId()}': {$e->getMessage()}"
            );

            return;
        }

        foreach ($mapping as $configField => $tokenField) {
            $config->$configField = match ($tokenField) {
                'access_token' => $tokens->getAccessToken(),
                'refresh_token' => $tokens->getRefreshToken(),
                'expires_at' => $tokens->getExpiresAt()?->toString(),
                default => null,
            };
        }
    }
}
