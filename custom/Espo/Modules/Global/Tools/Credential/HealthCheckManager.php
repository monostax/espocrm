<?php

namespace Espo\Modules\Global\Tools\Credential;

use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Modules\Global\Tools\Credential\HealthCheckers\GenericHttpHealthChecker;
use Espo\Modules\Global\Tools\Credential\HealthCheckers\HealthCheckerInterface;
use Espo\Modules\Global\Tools\Credential\HealthCheckers\HealthCheckResult;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * Manages credential health checks using a strategy pattern.
 *
 * Resolution order:
 * 1. Look for a dedicated checker class by CredentialType code.
 * 2. Fall back to GenericHttpHealthChecker if healthCheckConfig exists.
 * 3. Return "unknown" if no checker is available.
 */
class HealthCheckManager
{
    private const CHECKER_NAMESPACE = 'Espo\\Modules\\Global\\Tools\\Credential\\HealthCheckers\\';

    public function __construct(
        private InjectableFactory $injectableFactory,
        private EntityManager $entityManager,
        private CredentialResolver $credentialResolver,
        private Log $log,
    ) {}

    /**
     * Run a health check for a credential by ID.
     */
    public function checkById(string $credentialId): HealthCheckResult
    {
        $credential = $this->entityManager->getEntityById('Credential', $credentialId);

        if (!$credential) {
            return new HealthCheckResult(
                HealthCheckResult::STATUS_UNKNOWN,
                'Credential not found.',
            );
        }

        $credentialTypeId = $credential->get('credentialTypeId');

        if (!$credentialTypeId) {
            return new HealthCheckResult(
                HealthCheckResult::STATUS_UNKNOWN,
                'No credential type linked.',
            );
        }

        $credentialType = $this->entityManager->getEntityById('CredentialType', $credentialTypeId);

        if (!$credentialType) {
            return new HealthCheckResult(
                HealthCheckResult::STATUS_UNKNOWN,
                'Credential type not found.',
            );
        }

        // Resolve the full config (with OAuth tokens if applicable).
        $resolvedConfig = null;

        try {
            $resolvedConfig = $this->credentialResolver->resolve($credentialId);
        } catch (\Throwable $e) {
            $result = new HealthCheckResult(
                HealthCheckResult::STATUS_UNHEALTHY,
                'Failed to resolve credential config: ' . $e->getMessage(),
            );

            return $this->persistAndReturn($credential, $result);
        }

        // Find a checker.
        $checker = $this->resolveChecker($credentialType);

        if (!$checker) {
            $result = new HealthCheckResult(
                HealthCheckResult::STATUS_UNKNOWN,
                'No health check configured for this credential type.',
            );

            return $this->persistAndReturn($credential, $result);
        }

        // Run the check.
        try {
            $result = $checker->check($resolvedConfig, $credential, $credentialType);
        } catch (\Throwable $e) {
            $this->log->error(
                "Health check failed for credential '{$credentialId}': " . $e->getMessage()
            );

            $result = new HealthCheckResult(
                HealthCheckResult::STATUS_UNHEALTHY,
                'Health check error: ' . $e->getMessage(),
            );
        }

        return $this->persistAndReturn($credential, $result);
    }

    /**
     * Persist the health check result on the credential entity and return it.
     */
    private function persistAndReturn(Entity $credential, HealthCheckResult $result): HealthCheckResult
    {
        $credential->set('lastHealthCheckAt', date('Y-m-d H:i:s'));
        $credential->set('lastHealthCheckStatus', $result->status);
        $this->entityManager->saveEntity($credential);

        return $result;
    }

    /**
     * Resolve the appropriate health checker for a CredentialType.
     */
    private function resolveChecker(Entity $credentialType): ?HealthCheckerInterface
    {
        $code = $credentialType->get('code');

        // 1. Try dedicated checker class by code.
        $dedicatedClass = $this->getDedicatedCheckerClass($code);

        if ($dedicatedClass && class_exists($dedicatedClass)) {
            $checker = $this->injectableFactory->create($dedicatedClass);

            if ($checker instanceof HealthCheckerInterface) {
                return $checker;
            }
        }

        // 2. Fall back to GenericHttpHealthChecker if healthCheckConfig exists.
        $healthCheckConfig = $credentialType->get('healthCheckConfig');

        if ($healthCheckConfig) {
            return $this->injectableFactory->create(GenericHttpHealthChecker::class);
        }

        // 3. No checker available.
        return null;
    }

    /**
     * Convert a CredentialType code to a PascalCase checker class name.
     *
     * Examples:
     *   'whatsappCloudApi' => 'WhatsappCloudApiHealthChecker'
     *   'basicAuth'        => 'BasicAuthHealthChecker'
     *   'apiKey'           => 'ApiKeyHealthChecker'
     */
    private function getDedicatedCheckerClass(string $code): string
    {
        $pascalCode = ucfirst($code);

        return self::CHECKER_NAMESPACE . $pascalCode . 'HealthChecker';
    }
}
