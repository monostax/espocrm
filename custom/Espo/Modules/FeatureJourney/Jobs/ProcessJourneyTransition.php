<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureJourney\Entities\Journey;
use Espo\Modules\FeatureJourney\Entities\JourneyRecord;
use Espo\Modules\FeatureJourney\Entities\JourneyTransition;
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
        $transitionIds = $data->get('transitionIds');
        $signal = $data->get('signal');
        $firedBy = (string) ($data->get('firedBy') ?? 'system');
        $allowEnrollment = false;

        if (is_object($signal)) {
            $signal = json_decode(json_encode($signal) ?: '{}', true);
        }
        if (!is_array($signal)) {
            $signal = $signal ? ['code' => (string) $signal] : null;
        }

        if (is_object($transitionIds)) {
            $transitionIds = json_decode(json_encode($transitionIds) ?: '[]', true);
        }
        if (!is_array($transitionIds)) {
            $transitionIds = [];
        }
        $transitionIds = array_values(array_filter(
            $transitionIds,
            static fn (mixed $id): bool => is_string($id) && $id !== '',
        ));

        if ($transitionId === '__goal__') {
            if ($recordId !== '') {
                $this->executor->executeGoal(
                    $recordId,
                    is_array($signal) ? $signal : null,
                    is_array($signal) && !empty($signal['code']) ? 'signal' : $firedBy,
                );
            }

            return;
        }

        if ($recordId === '' && is_array($signal) && !empty($signal['enrollTargetType'])) {
            $transition = $transitionId !== ''
                ? $this->entityManager->getEntityById(JourneyTransition::ENTITY_TYPE, $transitionId)
                : null;

            if (
                !$transition ||
                !$transition->get('isActive') ||
                JourneyTransition::resolveScope($transition) !== JourneyTransition::SCOPE_ENROLLMENT
            ) {
                return;
            }

            $journeyId = (string) ($signal['journeyId'] ?? '');
            $journey = $journeyId
                ? $this->entityManager->getEntityById(Journey::ENTITY_TYPE, $journeyId)
                : null;

            if (
                !$journey ||
                $journey->get('status') !== Journey::STATUS_ACTIVE ||
                (string) $transition->get('journeyId') !== $journeyId
            ) {
                return;
            }

            $enrolled = $this->enrollmentService->enrollOne(
                $journey,
                (string) $signal['enrollTargetType'],
                (string) $signal['enrollTargetId'],
            );

            if (!$enrolled) {
                return;
            }

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
            $allowEnrollment = true;
        }

        if ($recordId === '' || $transitionId === '') {
            $this->log->warning('ProcessJourneyTransition: missing record/transition id');

            return;
        }

        $mapFired = $firedBy;
        if (is_array($signal) && !empty($signal['code'])) {
            $mapFired = 'signal';
        }

        if ($transitionIds !== []) {
            foreach ($transitionIds as $candidateId) {
                if (!$this->executor->matches($recordId, $candidateId, $signal, $mapFired)) {
                    continue;
                }

                $this->executor->executeMatched($recordId, $candidateId, $signal, $mapFired);

                return;
            }

            return;
        }

        if ($allowEnrollment) {
            $this->executor->executeEnrollment($recordId, $transitionId, $signal, $mapFired);

            return;
        }

        $this->executor->execute($recordId, $transitionId, $signal, $mapFired);
    }
}
