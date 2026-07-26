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

namespace Espo\Modules\FeatureCredential\Classes\RecordHooks\Credential;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\Hook\SaveHook;
use Espo\Modules\FeatureCredential\Tools\AgentEgress\AgentEgressShape;
use Espo\Modules\FeatureCredential\Tools\Credential\CredentialResolver;
use Espo\ORM\Entity;
use stdClass;

/**
 * Validate Credential.agentEgress shape, path format, and resolved accessibility.
 *
 * @implements SaveHook<Entity>
 */
class ValidateAgentEgress implements SaveHook
{
    public function __construct(
        private CredentialResolver $credentialResolver,
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

        $bag = $this->buildConfigBag($entity);

        if ($bag !== null) {
            foreach ($egress->secrets as $index => $secret) {
                $path = (string) $secret->configPath;
                $value = AgentEgressShape::readPath($bag, $path);

                if ($value === null || $value === '') {
                    throw new BadRequest(
                        "agentEgress.secrets[{$index}].configPath '{$path}' is not accessible on this " .
                        "Credential (missing, empty, or not a scalar value after OAuth merge)."
                    );
                }
            }
        }

        $entity->set('agentEgress', $egress);
    }

    private function buildConfigBag(Entity $entity): ?stdClass
    {
        $id = $entity->getId();

        if (is_string($id) && $id !== '') {
            try {
                return $this->credentialResolver->resolve($id);
            } catch (NotFound | Error) {
                // Fall through to raw config on the in-memory entity.
            }
        }

        $configRaw = $entity->get('config');

        if ($configRaw === null || $configRaw === '') {
            return null;
        }

        if (is_string($configRaw)) {
            $decoded = json_decode($configRaw);

            return $decoded instanceof stdClass ? $decoded : null;
        }

        if ($configRaw instanceof stdClass) {
            return $configRaw;
        }

        if (is_array($configRaw)) {
            return (object) $configRaw;
        }

        return null;
    }
}
