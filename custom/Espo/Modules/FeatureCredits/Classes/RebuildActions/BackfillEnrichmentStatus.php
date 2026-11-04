<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Classes\RebuildActions;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Modules\FeatureCredits\Enrichment\EnrichmentStatus;
use Espo\ORM\EntityManager;

/** Fills the per-record enrichment status from history. Idempotent: unchanged rows are not rewritten. */
final class BackfillEnrichmentStatus implements RebuildAction
{
    public function __construct(private EntityManager $em) {}

    public function process(): void
    {
        $this->em->getTransactionManager()->run(fn () => EnrichmentStatus::backfill($this->em->getPDO()));
    }
}
