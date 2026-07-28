<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use Throwable;

class BackfillTransitionScopes implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function process(): void
    {
        try {
            $statement = $this->entityManager->getPDO()->prepare(
                "UPDATE journey_transition
                 SET scope = CASE
                     WHEN from_stage_id IS NOT NULL THEN 'stage'
                     ELSE 'enrollment'
                 END
                 WHERE scope IS NULL
                    OR scope = ''
                    OR (from_stage_id IS NOT NULL AND scope <> 'stage')"
            );
            $statement->execute();

            if ($statement->rowCount() > 0) {
                $this->log->info(
                    'FeatureJourney BackfillTransitionScopes: updated ' . $statement->rowCount() . ' transition(s).'
                );
            }
        } catch (Throwable $e) {
            $this->log->info(
                'FeatureJourney BackfillTransitionScopes: skipped (schema not ready): ' . $e->getMessage()
            );
        }
    }
}
