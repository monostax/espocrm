<?php

namespace Espo\Modules\FeatureCredential\Tools\Credential;

use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCredential\Tools\Credential\HealthCheckers\GenericHttpHealthChecker;
use Espo\Modules\FeatureCredential\Tools\Credential\HealthCheckers\HealthCheckerInterface;
use Espo\Modules\FeatureCredential\Tools\Credential\HealthCheckers\HealthCheckResult;
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
    private const CHECKER_NAMESPACE = 'Espo\\Modules\\FeatureCredential\\Tools\\Credential\\HealthCheckers\\';

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
        $category = $credentialType->get('category');

        // 1. Try dedicated checker class by code.
        $dedicatedClass = $code ? $this->getDedicatedCheckerClass($code) : null;

        if ($dedicatedClass && class_exists($dedicatedClass)) {
            $checker = $this->injectableFactory->create($dedicatedClass);

            if ($checker instanceof HealthCheckerInterface) {
                return $checker;
            }
        }

        // 2. Try checker by credential category (e.g. formAuth).
        $categoryClass = $category ? $this->getDedicatedCheckerClass($category) : null;

        if ($categoryClass && class_exists($categoryClass)) {
            $checker = $this->injectableFactory->create($categoryClass);

            if ($checker instanceof HealthCheckerInterface) {
                return $checker;
            }
        }

        // 3. Fall back to GenericHttpHealthChecker if healthCheckConfig exists.
        $healthCheckConfig = $credentialType->get('healthCheckConfig');

        if ($healthCheckConfig) {
            return $this->injectableFactory->create(GenericHttpHealthChecker::class);
        }

        // 4. No checker available.
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
        $normalized = preg_replace('/[^a-zA-Z0-9]+/', ' ', $code) ?? '';
        $pascalCode = str_replace(' ', '', ucwords(trim($normalized)));

        return self::CHECKER_NAMESPACE . $pascalCode . 'HealthChecker';
    }
}
