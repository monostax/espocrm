<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Hooks\Contact;

use Espo\Modules\FeatureCredits\Enrichment\ContactEnrichmentResults;
use Espo\ORM\Entity;

/** A removed Contact keeps no enrichmentResults rows. */
class RemoveEnrichmentResults
{
    public function __construct(private ContactEnrichmentResults $results) {}

    public function afterRemove(Entity $entity, array $options): void
    {
        if ($entity->hasId() && $this->results->hasRows($entity->getId())) $this->results->refreshQuietly([$entity->getId()]);
    }
}
