<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Classes\RebuildActions;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Modules\FeatureCredits\Enrichment\RelationProvenance;
use Espo\ORM\EntityManager;

/** Links that enrichment created before `via` existed get it (record query: `contacts.where(origin = "enrichment")`). */
final class MarkEnrichmentRelations implements RebuildAction
{
    public function __construct(private EntityManager $em) {}

    public function process(): void
    {
        RelationProvenance::backfill($this->em->getPDO());
    }
}
