<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Hooks\TrackingLink;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingLink;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingSource;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use Throwable;

/**
 * Convenience default: a TrackingLink with no teams inherits the teams of
 * its TrackingSource, so "pick a source, paste a URL, save" just works and
 * the link lands in the same team scope (and therefore the same tenant,
 * derived next by AssignTenantFromTeam at order 9) as its source.
 *
 * Only applies when the link genuinely has no teams — neither in-memory nor
 * persisted — so a user's explicit team choice is never overwritten.
 *
 * @implements BeforeSave<TrackingLink>
 */
class CascadeTeamsFromSource implements BeforeSave
{
    public static int $order = 8;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof TrackingLink) {
            return;
        }

        if ($options->get('silent')) {
            return;
        }

        if ($this->resolveTeamIds($entity) !== []) {
            return;
        }

        $sourceId = $entity->get('trackingSourceId');

        if (!is_string($sourceId) || $sourceId === '') {
            return;
        }

        $source = $this->entityManager->getEntityById(TrackingSource::ENTITY_TYPE, $sourceId);

        if (!$source instanceof TrackingSource) {
            return;
        }

        try {
            $teamsIds = array_values(array_unique($source->getLinkMultipleIdList('teams')));
        } catch (Throwable) {
            return;
        }

        if ($teamsIds !== []) {
            $entity->set('teamsIds', $teamsIds);
        }
    }

    /**
     * In-memory teams, falling back to the persisted entity_team relation
     * (mirrors AssignTenantFromTeam's resolution).
     *
     * @return list<string>
     */
    private function resolveTeamIds(TrackingLink $entity): array
    {
        $ids = [];

        try {
            $ids = $entity->getLinkMultipleIdList('teams') ?: [];
        } catch (Throwable) {
            $ids = [];
        }

        if (!empty($ids)) {
            return array_values(array_unique($ids));
        }

        $teamsIds = $entity->get('teamsIds');

        if (is_array($teamsIds) && !empty($teamsIds)) {
            return array_values(array_unique($teamsIds));
        }

        if (!$entity->isNew() && $entity->getId()) {
            try {
                $teams = $this->entityManager
                    ->getRDBRepository(TrackingLink::ENTITY_TYPE)
                    ->getRelation($entity, 'teams')
                    ->find();

                $ids = [];

                foreach ($teams as $team) {
                    $ids[] = $team->getId();
                }

                return array_values(array_unique($ids));
            } catch (Throwable) {
                return [];
            }
        }

        return [];
    }
}
