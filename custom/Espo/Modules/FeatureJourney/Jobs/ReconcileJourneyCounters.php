<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureJourney\Entities\Journey;
use Espo\Modules\FeatureJourney\Entities\JourneyRecord;
use Espo\ORM\EntityManager;

/**
 * Nightly recompute of denormalized Journey counters from JourneyRecord rows.
 */
class ReconcileJourneyCounters implements JobDataLess
{
    private const BATCH = 200;

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function run(): void
    {
        $offset = 0;
        $updated = 0;

        while (true) {
            $journeys = $this->entityManager
                ->getRDBRepository(Journey::ENTITY_TYPE)
                ->where([
                    'status!=' => Journey::STATUS_ARCHIVED,
                ])
                ->limit($offset, self::BATCH)
                ->find();

            $count = 0;

            foreach ($journeys as $journey) {
                $count++;
                $id = (string) $journey->getId();

                $active = $this->countStatus($id, [
                    JourneyRecord::STATUS_ACTIVE,
                    JourneyRecord::STATUS_PROCESSING,
                    JourneyRecord::STATUS_PAUSED,
                ]);
                $completed = $this->countStatus($id, [JourneyRecord::STATUS_COMPLETED]);
                $exited = $this->countStatus($id, [JourneyRecord::STATUS_EXITED]);
                $goal = $this->countGoals($id);

                $dirty =
                    (int) $journey->get('activeCount') !== $active ||
                    (int) $journey->get('completedCount') !== $completed ||
                    (int) $journey->get('exitedCount') !== $exited ||
                    (int) $journey->get('goalCount') !== $goal;

                if (!$dirty) {
                    continue;
                }

                $journey->set('activeCount', $active);
                $journey->set('completedCount', $completed);
                $journey->set('exitedCount', $exited);
                $journey->set('goalCount', $goal);

                $this->entityManager->saveEntity($journey, [
                    SaveOption::SKIP_ALL => true,
                    'skipJourneyDispatch' => true,
                ]);

                $updated++;
            }

            if ($count < self::BATCH) {
                break;
            }

            $offset += self::BATCH;
        }

        if ($updated > 0) {
            $this->log->info("ReconcileJourneyCounters: updated {$updated} journey counter set(s).");
        }
    }

    /**
     * @param list<string> $statuses
     */
    private function countStatus(string $journeyId, array $statuses): int
    {
        return $this->entityManager
            ->getRDBRepository(JourneyRecord::ENTITY_TYPE)
            ->where([
                'journeyId' => $journeyId,
                'status' => $statuses,
            ])
            ->count();
    }

    private function countGoals(string $journeyId): int
    {
        return $this->entityManager
            ->getRDBRepository(JourneyRecord::ENTITY_TYPE)
            ->where([
                'journeyId' => $journeyId,
                'status' => JourneyRecord::STATUS_COMPLETED,
                'exitReason' => 'goal',
            ])
            ->count();
    }
}
