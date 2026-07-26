<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureJourney\Entities\Journey;
use Espo\Modules\FeatureJourney\Services\JourneyEnrollmentService;
use Espo\ORM\EntityManager;

class EnrollJourneyRecords implements JobDataLess
{
    public function __construct(
        private EntityManager $entityManager,
        private JourneyEnrollmentService $enrollmentService,
        private Log $log,
    ) {}

    public function run(): void
    {
        $journeys = $this->entityManager
            ->getRDBRepository(Journey::ENTITY_TYPE)
            ->where([
                'status' => Journey::STATUS_ACTIVE,
                'continuousEnrollment' => true,
            ])
            ->find();

        foreach ($journeys as $journey) {
            try {
                $count = $this->enrollmentService->enrollAudience((string) $journey->getId());

                if ($count > 0) {
                    $this->log->info(
                        "EnrollJourneyRecords: enrolled {$count} into journey {$journey->getId()}"
                    );
                }
            } catch (\Throwable $e) {
                $this->log->error(
                    "EnrollJourneyRecords: journey {$journey->getId()}: " . $e->getMessage()
                );
            }
        }
    }
}
