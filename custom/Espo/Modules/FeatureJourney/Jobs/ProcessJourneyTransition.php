<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureJourney\Entities\Journey;
use Espo\Modules\FeatureJourney\Entities\JourneyRecord;
use Espo\Modules\FeatureJourney\Services\JourneyEnrollmentService;
use Espo\Modules\FeatureJourney\Services\TransitionExecutor;
use Espo\ORM\EntityManager;

class ProcessJourneyTransition implements Job
{
    public function __construct(
        private EntityManager $entityManager,
        private TransitionExecutor $executor,
        private JourneyEnrollmentService $enrollmentService,
        private Log $log,
    ) {}

    public function run(Data $data): void
    {
        $recordId = (string) ($data->get('journeyRecordId') ?? '');
        $transitionId = (string) ($data->get('transitionId') ?? '');
        $signal = $data->get('signal');
        $firedBy = (string) ($data->get('firedBy') ?? 'system');

        if (is_object($signal)) {
            $signal = json_decode(json_encode($signal) ?: '{}', true);
        }
        if (!is_array($signal)) {
            $signal = $signal ? ['code' => (string) $signal] : null;
        }

        if ($transitionId === '__goal__') {
            if ($recordId !== '') {
                $this->executor->checkGoalForRecord($recordId, is_array($signal) ? $signal : null);
            }

            return;
        }

        if ($recordId === '' && is_array($signal) && !empty($signal['enrollTargetType'])) {
            $journeyId = (string) ($signal['journeyId'] ?? '');
            $journey = $journeyId
                ? $this->entityManager->getEntityById(Journey::ENTITY_TYPE, $journeyId)
                : null;

            if (!$journey) {
                return;
            }

            $enrolled = $this->enrollmentService->enrollOne(
                $journey,
                (string) $signal['enrollTargetType'],
                (string) $signal['enrollTargetId'],
            );

            if (!$enrolled) {
                $existing = $this->entityManager
                    ->getRDBRepository(JourneyRecord::ENTITY_TYPE)
                    ->where([
                        'journeyId' => $journeyId,
                        'targetType' => $signal['enrollTargetType'],
                        'targetId' => $signal['enrollTargetId'],
                        'status' => JourneyRecord::STATUS_ACTIVE,
                    ])
                    ->findOne();

                if (!$existing) {
                    return;
                }
                $recordId = (string) $existing->getId();
            } else {
                $existing = $this->entityManager
                    ->getRDBRepository(JourneyRecord::ENTITY_TYPE)
                    ->where([
                        'journeyId' => $journeyId,
                        'targetType' => $signal['enrollTargetType'],
                        'targetId' => $signal['enrollTargetId'],
                        'status' => JourneyRecord::STATUS_ACTIVE,
                    ])
                    ->order('createdAt', 'DESC')
                    ->findOne();
                $recordId = $existing ? (string) $existing->getId() : '';
            }
        }

        if ($recordId === '' || $transitionId === '') {
            $this->log->warning('ProcessJourneyTransition: missing record/transition id');

            return;
        }

        $mapFired = $firedBy;
        if (is_array($signal) && !empty($signal['code'])) {
            $mapFired = 'signal';
        }

        $this->executor->execute($recordId, $transitionId, $signal, $mapFired);
    }
}
