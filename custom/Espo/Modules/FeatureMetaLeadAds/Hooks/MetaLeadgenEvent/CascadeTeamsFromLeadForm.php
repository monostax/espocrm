<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaLeadAds\Hooks\MetaLeadgenEvent;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureMetaLeadAds\Entities\MetaLeadForm;
use Espo\Modules\FeatureMetaLeadAds\Entities\MetaLeadgenEvent;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Cascades teams from the parent MetaLeadForm to this leadgen event.
 *
 * Always runs (not just on insert) so re-saves with a different parent
 * stay consistent. Mirrors CascadeTeamsFromPage on MetaLeadForm.
 *
 * @implements BeforeSave<MetaLeadgenEvent>
 */
class CascadeTeamsFromLeadForm implements BeforeSave
{
    public static int $order = 1;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof MetaLeadgenEvent) {
            return;
        }

        if ($options->get('silent')) {
            return;
        }

        $leadFormId = $entity->get('leadFormId');

        if (!$leadFormId) {
            return;
        }

        $leadForm = $this->entityManager->getEntityById(MetaLeadForm::ENTITY_TYPE, $leadFormId);

        if (!$leadForm) {
            return;
        }

        try {
            $teamsIds = $leadForm->getLinkMultipleIdList('teams');
        } catch (\Throwable) {
            return;
        }

        if (!empty($teamsIds)) {
            $entity->set('teamsIds', $teamsIds);
        }
    }
}
