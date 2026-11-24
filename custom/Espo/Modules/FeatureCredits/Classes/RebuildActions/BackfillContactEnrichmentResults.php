<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Classes\RebuildActions;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Modules\FeatureCredits\Enrichment\ContactEnrichmentResults;

/** Contacts with purchased email/phone results get their enrichmentResults rows (first deploy, or a refresh that failed). */
final class BackfillContactEnrichmentResults implements RebuildAction
{
    public function __construct(private ContactEnrichmentResults $results) {}

    public function process(): void
    {
        $this->results->backfill();
    }
}
