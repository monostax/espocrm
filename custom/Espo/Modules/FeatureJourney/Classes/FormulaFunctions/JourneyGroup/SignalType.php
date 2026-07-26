<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Classes\FormulaFunctions\JourneyGroup;

use Espo\Core\Di\EntityManagerAware;
use Espo\Core\Di\EntityManagerSetter;
use Espo\Core\Di\InjectableFactoryAware;
use Espo\Core\Di\InjectableFactorySetter;
use Espo\Core\Exceptions\Error;
use Espo\Core\Formula\ArgumentList;
use Espo\Core\Formula\Functions\BaseFunction;
use Espo\Modules\FeatureJourney\Services\JourneyEffectDepth;
use Espo\Modules\FeatureJourney\Services\JourneySignalDispatcher;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\Modules\FeatureJourney\Services\TenantResolver;

/**
 * journey\signal(CODE, TARGET_TYPE, TARGET_ID, PAYLOAD?)
 *
 * Tenant is ALWAYS derived from the target entity — free tenantId args rejected.
 * Legacy 5-arg form journey\signal(TENANT_ID, CODE, ...) is accepted only when
 * the supplied tenantId matches the target's resolved tenant (else throw).
 */
class SignalType extends BaseFunction implements EntityManagerAware, InjectableFactoryAware
{
    use EntityManagerSetter;
    use InjectableFactorySetter;

    public function process(ArgumentList $args)
    {
        $args = $this->evaluate($args);

        return JourneyEffectDepth::run(function () use ($args) {
            [$code, $targetType, $targetId, $payload, $legacyTenant] = $this->parseArgs($args);

            if ($code === '') {
                throw new Error("Formula: journey\\signal: code required.");
            }

            $target = null;
            if ($targetType && $targetId) {
                $target = $this->entityManager->getEntityById((string) $targetType, (string) $targetId);
                if (!$target) {
                    throw new Error("Formula: journey\\signal: target not found.");
                }
            } else {
                throw new Error("Formula: journey\\signal: TARGET_TYPE and TARGET_ID required.");
            }

            $resolver = $this->injectableFactory->create(TenantResolver::class);
            $tenantId = $resolver->resolveTenantIdForEntity($target);

            if ($tenantId === null || $tenantId === '') {
                throw new Error("Formula: journey\\signal: cannot resolve tenant for target.");
            }

            if ($legacyTenant !== null && $legacyTenant !== $tenantId) {
                throw new Error("Formula: journey\\signal: tenantId argument does not match target tenant.");
            }

            $guard = $this->injectableFactory->create(TenantGuard::class);
            $guard->assertEntityTenant($target, $tenantId, 'signal-target');

            if (is_object($payload)) {
                $payload = json_decode(json_encode($payload) ?: '{}', true) ?: [];
            }
            if (!is_array($payload)) {
                $payload = [];
            }

            $dispatcher = $this->injectableFactory->create(JourneySignalDispatcher::class);
            $dispatcher->dispatch($tenantId, $code, $target, $payload);

            return true;
        });
    }

    /**
     * @param array<int, mixed> $args
     * @return array{0: string, 1: ?string, 2: ?string, 3: mixed, 4: ?string}
     */
    private function parseArgs(array $args): array
    {
        // New: (CODE, TARGET_TYPE, TARGET_ID, PAYLOAD?)
        // Legacy: (TENANT_ID, CODE, TARGET_TYPE, TARGET_ID, PAYLOAD?)
        $a0 = $args[0] ?? null;
        $a1 = $args[1] ?? null;
        $a2 = $args[2] ?? null;
        $a3 = $args[3] ?? null;
        $a4 = $args[4] ?? null;

        $looksLegacy = is_string($a0)
            && is_string($a1)
            && is_string($a2)
            && is_string($a3)
            && (
                $this->entityManager->getEntityById('Tenant', (string) $a0) !== null
                || (
                    // target entity type looks valid based on 4th being id
                    in_array((string) $a2, ['Contact', 'Lead', 'Account', 'Opportunity'], true)
                )
            )
            && !in_array((string) $a0, ['Contact', 'Lead', 'Account', 'Opportunity'], true)
            && strlen((string) $a0) >= 10;

        if ($looksLegacy) {
            return [
                (string) $a1,
                $a2 !== null ? (string) $a2 : null,
                $a3 !== null ? (string) $a3 : null,
                $a4,
                (string) $a0,
            ];
        }

        return [
            (string) $a0,
            $a1 !== null ? (string) $a1 : null,
            $a2 !== null ? (string) $a2 : null,
            $a3,
            null,
        ];
    }
}
