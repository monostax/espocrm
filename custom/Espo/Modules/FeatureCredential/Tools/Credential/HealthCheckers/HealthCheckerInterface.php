<?php

namespace Espo\Modules\FeatureCredential\Tools\Credential\HealthCheckers;

use Espo\ORM\Entity;
use stdClass;

/**
 * Interface for credential health checkers.
 *
 * Each CredentialType can have a dedicated health checker class that implements
 * this interface. The HealthCheckManager resolves the right checker by the
 * CredentialType's code.
 */
interface HealthCheckerInterface
{
    /**
     * Perform a health check for a credential.
     *
     * @param stdClass $resolvedConfig The fully resolved credential config (with OAuth tokens merged).
     * @param Entity $credential The Credential entity.
     * @param Entity $credentialType The CredentialType entity.
     * @return HealthCheckResult
     */
    public function check(stdClass $resolvedConfig, Entity $credential, Entity $credentialType): HealthCheckResult;
}
