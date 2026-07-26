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

namespace Espo\Modules\FeatureOAuthEnhanced\Tools\OAuthAccount;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCredential\Tools\AgentEgress\AgentEgressShape;
use Espo\ORM\EntityManager;
use Espo\Tools\OAuth\Exceptions\AccountNotFound;
use Espo\Tools\OAuth\Exceptions\NoToken;
use Espo\Tools\OAuth\Exceptions\ProviderNotAvailable;
use Espo\Tools\OAuth\Exceptions\TokenObtainingFailure;
use Espo\Tools\OAuth\TokensProvider;
use stdClass;

/**
 * Resolve OAuthAccount.agentEgress configPaths → live token/data values.
 *
 * accessToken/refreshToken stay API-forbidden; backend calls this action so
 * agentbox egress can substitute without exposing tokens on GETs.
 */
class AgentEgressResolver
{
    public function __construct(
        private EntityManager $entityManager,
        private TokensProvider $tokensProvider,
        private Acl $acl,
        private Log $log,
    ) {}

    /**
     * @return stdClass{id: string, values: stdClass}
     */
    public function resolve(string $oauthAccountId): stdClass
    {
        $account = $this->entityManager->getEntityById('OAuthAccount', $oauthAccountId);

        if (!$account) {
            throw new NotFound("OAuthAccount '{$oauthAccountId}' not found.");
        }

        if (!$this->acl->check($account, 'read')) {
            throw new Forbidden('Access denied.');
        }

        $agentEgressRaw = $account->get('agentEgress');

        if ($agentEgressRaw === null || $agentEgressRaw === '') {
            return $this->emptyResult($oauthAccountId);
        }

        $agentEgress = is_string($agentEgressRaw)
            ? json_decode($agentEgressRaw)
            : $agentEgressRaw;

        if (!$agentEgress instanceof stdClass) {
            return $this->emptyResult($oauthAccountId);
        }

        if (isset($agentEgress->enabled) && $agentEgress->enabled === false) {
            return $this->emptyResult($oauthAccountId);
        }

        $secrets = $agentEgress->secrets ?? null;

        if (!is_array($secrets) && !($secrets instanceof stdClass)) {
            return $this->emptyResult($oauthAccountId);
        }

        $secretList = is_array($secrets) ? $secrets : get_object_vars($secrets);
        $paths = [];

        foreach ($secretList as $secret) {
            if ($secret instanceof stdClass) {
                $path = $secret->configPath ?? null;
            } elseif (is_array($secret)) {
                $path = $secret['configPath'] ?? null;
            } else {
                $path = null;
            }

            if (is_string($path) && $path !== '') {
                $paths[] = $path;
            }
        }

        $bag = $this->buildValueBag($oauthAccountId, $account);
        $values = new stdClass();

        foreach (array_unique($paths) as $path) {
            $value = AgentEgressShape::readPath($bag, $path);

            if ($value === null || $value === '') {
                $this->log->warning(
                    "OAuthAccount AgentEgressResolver: path '{$path}' not accessible on " .
                    "OAuthAccount '{$oauthAccountId}'."
                );
                continue;
            }

            $values->$path = $value;
        }

        $result = new stdClass();
        $result->id = $oauthAccountId;
        $result->values = $values;

        return $result;
    }

    /**
     * Resolve a single path without requiring it in agentEgress (save-time checks).
     */
    public function peekPath(string $oauthAccountId, string $path): ?string
    {
        $account = $this->entityManager->getEntityById('OAuthAccount', $oauthAccountId);

        if (!$account) {
            return null;
        }

        $bag = $this->buildValueBag($oauthAccountId, $account);

        return AgentEgressShape::readPath($bag, $path);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildValueBag(string $oauthAccountId, $account): array
    {
        $bag = [
            'name' => $account->get('name'),
            'providerId' => $account->get('providerId'),
            'userId' => $account->get('userId'),
            'expiresAt' => $account->get('expiresAt'),
            'data' => $this->normalizeData($account->get('data')),
        ];

        try {
            $tokens = $this->tokensProvider->get($oauthAccountId);
            $access = $tokens->getAccessToken();
            $refresh = $tokens->getRefreshToken();
            $expires = $tokens->getExpiresAt()?->toString();

            $bag['accessToken'] = $access;
            $bag['access_token'] = $access;
            $bag['refreshToken'] = $refresh;
            $bag['refresh_token'] = $refresh;
            $bag['expiresAt'] = $expires ?? $bag['expiresAt'];
            $bag['expires_at'] = $expires ?? $bag['expiresAt'];
        } catch (AccountNotFound | ProviderNotAvailable | NoToken | TokenObtainingFailure $e) {
            $this->log->warning(
                "OAuthAccount AgentEgressResolver: Could not resolve tokens for " .
                "OAuthAccount '{$oauthAccountId}': {$e->getMessage()}"
            );
        }

        return $bag;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeData(mixed $data): array
    {
        if ($data === null || $data === '') {
            return [];
        }

        if (is_string($data)) {
            $decoded = json_decode($data, true);

            return is_array($decoded) ? $this->deepArray($decoded) : [];
        }

        if ($data instanceof stdClass) {
            return $this->deepArray(get_object_vars($data));
        }

        if (is_array($data)) {
            return $this->deepArray($data);
        }

        return [];
    }

    /**
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    private function deepArray(array $value): array
    {
        $out = [];

        foreach ($value as $k => $v) {
            if (!is_string($k) && !is_int($k)) {
                continue;
            }

            $key = (string) $k;

            if ($v instanceof stdClass) {
                $out[$key] = $this->deepArray(get_object_vars($v));
            } elseif (is_array($v)) {
                $out[$key] = $this->deepArray($v);
            } else {
                $out[$key] = $v;
            }
        }

        return $out;
    }

    private function emptyResult(string $oauthAccountId): stdClass
    {
        $result = new stdClass();
        $result->id = $oauthAccountId;
        $result->values = new stdClass();

        return $result;
    }
}
