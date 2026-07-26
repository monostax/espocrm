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
use Espo\Modules\FeatureJourney\Entities\Journey;
use Espo\Modules\FeatureJourney\Entities\JourneyRecord;
use Espo\Modules\FeatureJourney\Services\JourneyEffectDepth;
use Espo\Modules\FeatureJourney\Services\JourneyEnrollmentService;
use Espo\Modules\FeatureJourney\Services\TenantGuard;

class EnrollType extends BaseFunction implements EntityManagerAware, InjectableFactoryAware
{
    use EntityManagerSetter;
    use InjectableFactorySetter;

    public function process(ArgumentList $args)
    {
        $args = $this->evaluate($args);
        $journeyId = $args[0] ?? null;
        $targetType = $args[1] ?? null;
        $targetId = $args[2] ?? null;

        if (!$journeyId || !$targetType || !$targetId) {
            throw new Error("Formula: journey\\enroll: Too few arguments.");
        }

        return JourneyEffectDepth::run(function () use ($journeyId, $targetType, $targetId) {
            $journey = $this->entityManager->getEntityById(Journey::ENTITY_TYPE, (string) $journeyId);
            if (!$journey) {
                return null;
            }

            $target = $this->entityManager->getEntityById((string) $targetType, (string) $targetId);
            if (!$target) {
                return null;
            }

            $guard = $this->injectableFactory->create(TenantGuard::class);
            $guard->assertTargetBelongsToJourney($target, $journey);

            $service = $this->injectableFactory->create(JourneyEnrollmentService::class);
            $ok = $service->enrollOne($journey, (string) $targetType, (string) $targetId);

            if (!$ok) {
                return null;
            }

            $record = $this->entityManager
                ->getRDBRepository(JourneyRecord::ENTITY_TYPE)
                ->where([
                    'journeyId' => $journeyId,
                    'targetType' => $targetType,
                    'targetId' => $targetId,
                    'status' => JourneyRecord::STATUS_ACTIVE,
                ])
                ->order('createdAt', 'DESC')
                ->findOne();

            return $record?->getId();
        });
    }
}
