<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureJourney\Entities\Journey;
use Espo\Modules\FeatureJourney\Entities\JourneyRecord;
use Espo\Modules\FeatureJourney\Entities\JourneyStage;
use Espo\Modules\FeatureJourney\Entities\JourneyTransition;
use Espo\Modules\FeatureJourney\Services\PeriodParser;
use Espo\ORM\EntityManager;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Throwable;

class ProcessJourneyTimers implements JobDataLess
{
    private const STALE_CLAIM_MINUTES = 15;

    public function __construct(
        private EntityManager $entityManager,
        private PeriodParser $periodParser,
        private JobSchedulerFactory $jobSchedulerFactory,
        private Log $log,
    ) {}

    public function run(): void
    {
        $this->recoverStaleClaims();
        $this->processTimerTransitions();
        $this->processMaxDurationSla();
    }

    private function recoverStaleClaims(): void
    {
        $cutoff = date('Y-m-d H:i:s', time() - self::STALE_CLAIM_MINUTES * 60);

        $stale = $this->entityManager
            ->getRDBRepository(JourneyRecord::ENTITY_TYPE)
            ->where([
                'status' => JourneyRecord::STATUS_PROCESSING,
                'claimedAt<' => $cutoff,
            ])
            ->limit(0, 200)
            ->find();

        foreach ($stale as $record) {
            try {
                $record->set([
                    'status' => JourneyRecord::STATUS_ACTIVE,
                    'claimedAt' => null,
                ]);
                $this->entityManager->saveEntity($record, [
                    SaveOption::SILENT => true,
                    'skipJourneyDispatch' => true,
                ]);
            } catch (Throwable $e) {
                $this->log->error('ProcessJourneyTimers: stale claim recovery: ' . $e->getMessage());
            }
        }
    }

    private function processTimerTransitions(): void
    {
        $transitions = $this->entityManager
            ->getRDBRepository(JourneyTransition::ENTITY_TYPE)
            ->where([
                'isActive' => true,
            ])
            ->find();

        foreach ($transitions as $transition) {
            if (!JourneyTransition::entityWakesOn($transition, JourneyTransition::TRIGGER_TIMER)) {
                continue;
            }

            $wait = $transition->get('waitPeriod');
            if (!$wait) {
                continue;
            }

            // Validated once per transition rather than swallowed per record: an
            // unparseable waitPeriod used to make the timer silently never fire.
            if (!$this->periodParser->isValid((string) $wait)) {
                $this->log->warning(
                    'ProcessJourneyTimers: transition ' . $transition->getId() .
                    ' has an invalid waitPeriod "' . $wait . '" — timer will never fire. ' .
                    'Use a format like "3 days" / "3 dias".'
                );

                continue;
            }

            $journey = $this->entityManager->getEntityById(
                Journey::ENTITY_TYPE,
                (string) $transition->get('journeyId')
            );

            if (!$journey || $journey->get('status') !== Journey::STATUS_ACTIVE) {
                continue;
            }

            $fromStageId = $transition->get('fromStageId');
            if (!$fromStageId) {
                continue;
            }

            $records = $this->entityManager
                ->getRDBRepository(JourneyRecord::ENTITY_TYPE)
                ->where([
                    'journeyId' => $journey->getId(),
                    'currentStageId' => $fromStageId,
                    'status' => JourneyRecord::STATUS_ACTIVE,
                ])
                ->limit(0, 200)
                ->find();

            foreach ($records as $record) {
                $entered = $record->get('enteredStageAt');
                if (!$entered) {
                    continue;
                }

                try {
                    if (!$this->periodParser->isDue((string) $entered, (string) $wait)) {
                        continue;
                    }
                } catch (Throwable $e) {
                    $this->log->warning(
                        'ProcessJourneyTimers: transition ' . $transition->getId() .
                        ' record ' . $record->getId() . ' timer check failed: ' . $e->getMessage()
                    );

                    continue;
                }

                try {
                    $this->jobSchedulerFactory
                        ->create()
                        ->setClassName(ProcessJourneyTransition::class)
                        ->setData([
                            'journeyRecordId' => $record->getId(),
                            'transitionId' => $transition->getId(),
                            'firedBy' => 'timer',
                        ])
                        ->schedule();
                } catch (Throwable $e) {
                    $this->log->error('ProcessJourneyTimers: queue failed: ' . $e->getMessage());
                }
            }
        }
    }

    private function processMaxDurationSla(): void
    {
        $stages = $this->entityManager
            ->getRDBRepository(JourneyStage::ENTITY_TYPE)
            ->where([
                'isActive' => true,
                'maxDuration!=' => null,
            ])
            ->find();

        foreach ($stages as $stage) {
            $max = $stage->get('maxDuration');
            if (!$max) {
                continue;
            }

            // Validated once per stage: an unparseable maxDuration used to make the SLA
            // silently never apply.
            if (!$this->periodParser->isValid((string) $max)) {
                $this->log->warning(
                    'ProcessJourneyTimers: stage ' . $stage->getId() .
                    ' has an invalid maxDuration "' . $max . '" — SLA will never apply. ' .
                    'Use a format like "3 days" / "3 dias".'
                );

                continue;
            }

            $records = $this->entityManager
                ->getRDBRepository(JourneyRecord::ENTITY_TYPE)
                ->where([
                    'currentStageId' => $stage->getId(),
                    'status' => JourneyRecord::STATUS_ACTIVE,
                ])
                ->limit(0, 100)
                ->find();

            foreach ($records as $record) {
                $entered = $record->get('enteredStageAt');
                if (!$entered) {
                    continue;
                }

                try {
                    if (!$this->periodParser->isDue((string) $entered, (string) $max)) {
                        continue;
                    }
                } catch (Throwable $e) {
                    $this->log->warning(
                        'ProcessJourneyTimers: stage ' . $stage->getId() .
                        ' record ' . $record->getId() . ' SLA check failed: ' . $e->getMessage()
                    );

                    continue;
                }

                // Mark exit on SLA breach if no timer path — exitReason only
                try {
                    $record->set([
                        'status' => JourneyRecord::STATUS_EXITED,
                        'exitReason' => 'max-duration-sla',
                    ]);
                    $this->entityManager->saveEntity($record, [
                        SaveOption::SILENT => true,
                        'skipJourneyDispatch' => true,
                    ]);

                    $journeyId = $record->get('journeyId');
                    if ($journeyId) {
                        $j = $this->entityManager->getEntityById(Journey::ENTITY_TYPE, (string) $journeyId);
                        if ($j) {
                            $j->set('activeCount', max(0, (int) $j->get('activeCount') - 1));
                            $j->set('exitedCount', (int) $j->get('exitedCount') + 1);
                            $this->entityManager->saveEntity($j, [
                                SaveOption::SILENT => true,
                                'skipJourneyDispatch' => true,
                            ]);
                        }
                    }
                } catch (Throwable $e) {
                    $this->log->error('ProcessJourneyTimers: SLA: ' . $e->getMessage());
                }
            }
        }
    }
}
