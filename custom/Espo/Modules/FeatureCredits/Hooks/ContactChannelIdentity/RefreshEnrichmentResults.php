<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Hooks\ContactChannelIdentity;

use Espo\Modules\FeatureCredits\Enrichment\ContactEnrichmentResults;
use Espo\ORM\Entity;

/** A Contact's enrichmentResults say whether each found value is one of its channels: keep `added` current. */
class RefreshEnrichmentResults
{
    private const CHANNELS = ['email', 'whatsapp', 'sms'];

    public function __construct(private ContactEnrichmentResults $results) {}

    public function afterSave(Entity $entity, array $options): void
    {
        $relevant = $entity->isNew() || $entity->isAttributeChanged('sourceId') || $entity->isAttributeChanged('contactId') ||
            $entity->isAttributeChanged('ownershipStatus') || $entity->isAttributeChanged('channelType') || $entity->isAttributeChanged('deleted');
        if (!$relevant) return;
        $types = [$entity->get('channelType'), $entity->getFetched('channelType')];
        if (!array_intersect($types, self::CHANNELS)) return;
        $this->refresh([$entity->get('contactId'), $entity->isNew() ? null : $entity->getFetched('contactId')]);
    }

    public function afterRemove(Entity $entity, array $options): void
    {
        if (!in_array($entity->get('channelType'), self::CHANNELS, true)) return;
        $this->refresh([$entity->get('contactId')]);
    }

    /** @param list<mixed> $contactIds */
    private function refresh(array $contactIds): void
    {
        $this->results->refreshQuietly(array_values(array_filter(
            array_unique(array_filter($contactIds, 'is_string')),
            fn (string $id) => $this->results->hasRows($id),
        )));
    }
}
