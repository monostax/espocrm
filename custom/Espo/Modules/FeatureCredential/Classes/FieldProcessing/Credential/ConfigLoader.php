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
use Espo\Core\Utils\Crypt;
use Espo\Tools\OAuth\TokensProvider;
use Espo\Tools\OAuth\Exceptions\AccountNotFound;
use Espo\Tools\OAuth\Exceptions\NoToken;
use Espo\Tools\OAuth\Exceptions\ProviderNotAvailable;
use Espo\Tools\OAuth\Exceptions\TokenObtainingFailure;
use Espo\Core\Utils\Log;
use stdClass;

/**
 * Merges live OAuth tokens into the Credential's config field on read.
 *
 * For OAuth-backed credentials (those with an oAuthAccountId), this loader
 * fetches fresh tokens from the linked OAuthAccount via TokensProvider and
 * maps them into the config JSON using the tokenFieldMapping defined on the
 * CredentialType. Non-OAuth credentials are left unchanged.
 *
 * This ensures that API consumers reading a Credential via standard CRUD
 * endpoints receive the fully resolved config (with accessToken, etc.)
 * while benefiting from EspoCRM's built-in ACL checks.
 *
 * @implements LoaderInterface<Entity>
 */
class ConfigLoader implements LoaderInterface
{
    public function __construct(
        private EntityManager $entityManager,
        private TokensProvider $tokensProvider,
        private Log $log,
    ) {}

    public function process(Entity $entity, Params $params): void
    {
        $oAuthAccountId = $entity->get('oAuthAccountId');

        if (!$oAuthAccountId) {
            return;
        }

        $credentialTypeId = $entity->get('credentialTypeId');

        if (!$credentialTypeId) {
            return;
        }

        $credentialType = $this->entityManager->getEntityById('CredentialType', $credentialTypeId);

        if (!$credentialType) {
            return;
        }

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

        $configRaw = $entity->get('config') ?: '{}';
        $config = json_decode($configRaw);

        if (!$config instanceof stdClass) {
            $config = new stdClass();
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

        $entity->set('config', json_encode($config));
    }
}
