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

namespace Espo\Modules\FeatureOAuthEnhanced\Classes\RecordHooks\OAuthAccount;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Record\Hook\SaveHook;
use Espo\Modules\FeatureCredential\Tools\AgentEgress\AgentEgressShape;
use Espo\Modules\FeatureOAuthEnhanced\Tools\OAuthAccount\AgentEgressConfigPath;
use Espo\Modules\FeatureOAuthEnhanced\Tools\OAuthAccount\AgentEgressResolver;
use Espo\ORM\Entity;

/**
 * Validate OAuthAccount.agentEgress shape, allowlisted configPath, and live accessibility.
 *
 * @implements SaveHook<Entity>
 */
class ValidateAgentEgress implements SaveHook
{
    public function __construct(
        private AgentEgressResolver $resolver,
    ) {}

    public function process(Entity $entity): void
    {
        if (!$entity->has('agentEgress')) {
            return;
        }

        $egress = AgentEgressShape::parseAndValidateShape($entity->get('agentEgress'));

        if ($egress === null) {
            $entity->set('agentEgress', null);

            return;
        }

        if ($egress->enabled !== true) {
            $entity->set('agentEgress', $egress);

            return;
        }

        foreach ($egress->secrets as $index => $secret) {
            $path = (string) $secret->configPath;

            if (!AgentEgressConfigPath::isAllowed($path)) {
                throw new BadRequest(
                    "agentEgress.secrets[{$index}].configPath '{$path}' is not allowed on OAuthAccount. " .
                    "Use accessToken, access_token, refreshToken, refresh_token, or data.<key>."
                );
            }
        }

        $id = $entity->getId();

        if (is_string($id) && $id !== '') {
            foreach ($egress->secrets as $index => $secret) {
                $path = (string) $secret->configPath;
                $value = $this->resolver->peekPath($id, $path);

                if ($value === null || $value === '') {
                    throw new BadRequest(
                        "agentEgress.secrets[{$index}].configPath '{$path}' is not accessible on this " .
                        "OAuthAccount (missing, empty, or not a scalar value). " .
                        "Confirm the token/data exists before enabling egress injection."
                    );
                }
            }
        }

        $entity->set('agentEgress', $egress);
    }
}
