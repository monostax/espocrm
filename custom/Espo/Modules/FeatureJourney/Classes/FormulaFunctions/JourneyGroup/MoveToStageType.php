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
use Espo\Modules\FeatureJourney\Entities\Journey;
use Espo\Modules\FeatureJourney\Entities\JourneyRecord;
use Espo\Modules\FeatureJourney\Entities\JourneyStage;
use Espo\Modules\FeatureJourney\Entities\JourneyTransition;
use Espo\Modules\FeatureJourney\Services\JourneyEffectDepth;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\Modules\FeatureJourney\Services\TransitionExecutor;

class MoveToStageType extends BaseFunction implements EntityManagerAware, InjectableFactoryAware
{
    use EntityManagerSetter;
    use InjectableFactorySetter;

    public function process(ArgumentList $args)
    {
        $args = $this->evaluate($args);
        $recordId = $args[0] ?? null;
        $stageId = $args[1] ?? null;

        if (!$recordId || !$stageId) {
            throw new Error("Formula: journey\\moveToStage: Too few arguments.");
        }

        return JourneyEffectDepth::run(function () use ($recordId, $stageId) {
            $record = $this->entityManager->getEntityById(JourneyRecord::ENTITY_TYPE, (string) $recordId);
            $stage = $this->entityManager->getEntityById(JourneyStage::ENTITY_TYPE, (string) $stageId);

            if (!$record || !$stage) {
                return false;
            }

            $journey = $this->entityManager->getEntityById(
                Journey::ENTITY_TYPE,
                (string) $record->get('journeyId')
            );

            if (!$journey) {
                return false;
            }

            $guard = $this->injectableFactory->create(TenantGuard::class);
            $guard->assertRecordMatchesJourney($record, $journey);
            $guard->assertStageInJourney($stage, $journey);

            $target = $this->entityManager->getEntityById(
                (string) $record->get('targetType'),
                (string) $record->get('targetId')
            );

            if (!$target) {
                return false;
            }

            $guard->assertTargetBelongsToJourney($target, $journey);

            $candidates = $this->entityManager
                ->getRDBRepository(JourneyTransition::ENTITY_TYPE)
                ->where([
                    'journeyId' => $record->get('journeyId'),
                    'toStageId' => $stageId,
                    'isActive' => true,
                ])
                ->order('priority', 'ASC')
                ->find();

            $transition = null;

            foreach ($candidates as $candidate) {
                if (JourneyTransition::entityWakesOn($candidate, JourneyTransition::TRIGGER_MANUAL)) {
                    $transition = $candidate;
                    break;
                }
            }

            if ($transition) {
                $executor = $this->injectableFactory->create(TransitionExecutor::class);

                return $executor->execute((string) $recordId, (string) $transition->getId(), null, 'formula');
            }

            $record->set([
                'currentStageId' => $stageId,
                'enteredStageAt' => date('Y-m-d H:i:s'),
            ]);
            $this->entityManager->saveEntity($record, [
                SaveOption::SILENT => true,
                'skipJourneyDispatch' => true,
            ]);

            return true;
        });
    }
}
