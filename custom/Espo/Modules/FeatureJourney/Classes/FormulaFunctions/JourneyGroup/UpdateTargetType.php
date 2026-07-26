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
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Modules\FeatureJourney\Services\JourneyEffectDepth;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\Modules\FeatureJourney\Services\TenantResolver;
use stdClass;

/**
 * journey\updateTarget(FIELD, VALUE [, FIELD, VALUE ...])
 * or journey\updateTarget(OBJECT)
 *
 * Allow-listed writes on the formula target entity (same policy as UpdateTarget action).
 * Never accepts foreign targets — always the formula context entity.
 */
class UpdateTargetType extends BaseFunction implements EntityManagerAware, InjectableFactoryAware
{
    use EntityManagerSetter;
    use InjectableFactorySetter;

    public function process(ArgumentList $args)
    {
        $args = $this->evaluate($args);

        return JourneyEffectDepth::run(function () use ($args) {
            $target = $this->getEntity();
            $fields = $this->parseFields($args);

            if ($fields === []) {
                return false;
            }

            $resolver = $this->injectableFactory->create(TenantResolver::class);
            $tenantId = $resolver->resolveTenantIdForEntity($target);

            if ($tenantId === null || $tenantId === '') {
                throw new Error("Formula: journey\\updateTarget: cannot resolve tenant for target.");
            }

            $guard = $this->injectableFactory->create(TenantGuard::class);
            $guard->assertEntityTenant($target, $tenantId, 'formula-updateTarget');

            $filtered = $guard->filterTargetUpdateFields($target->getEntityType(), $fields);

            if ($filtered === []) {
                return false;
            }

            $written = $guard->applyTargetUpdateFields($target, $filtered);

            if ($written === []) {
                return false;
            }

            // Nested effects already under depth — do not re-fire journey entity hooks.
            $this->entityManager->saveEntity($target, [
                SaveOption::SILENT => false,
                'skipJourneyDispatch' => true,
            ]);

            return true;
        });
    }

    /**
     * @param array<int, mixed> $args
     * @return array<string, mixed>
     */
    private function parseFields(array $args): array
    {
        if ($args === []) {
            return [];
        }

        $first = $args[0];

        if ($first instanceof stdClass) {
            return (array) $first;
        }

        if (is_array($first) && $this->isAssoc($first)) {
            return $first;
        }

        $fields = [];
        $n = count($args);

        for ($i = 0; $i + 1 < $n; $i += 2) {
            $key = $args[$i];
            if (!is_string($key) || $key === '') {
                continue;
            }
            $fields[$key] = $args[$i + 1];
        }

        return $fields;
    }

    /**
     * @param array<mixed> $arr
     */
    private function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
