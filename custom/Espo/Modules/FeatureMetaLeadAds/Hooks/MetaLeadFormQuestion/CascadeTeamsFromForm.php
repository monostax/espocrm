<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaLeadAds\Hooks\MetaLeadFormQuestion;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureMetaLeadAds\Entities\MetaLeadForm;
use Espo\Modules\FeatureMetaLeadAds\Entities\MetaLeadFormQuestion;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Cascades teams from the parent MetaLeadForm to this question row.
 *
 * Mirrors MetaLeadForm/CascadeTeamsFromPage so questions inherit the same
 * tenancy boundary as their form. Always runs (not only on insert) so a
 * re-parent stays consistent.
 *
 * @implements BeforeSave<MetaLeadFormQuestion>
 */
class CascadeTeamsFromForm implements BeforeSave
{
    public static int $order = 1;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof MetaLeadFormQuestion) {
            return;
        }

        if ($options->get('silent')) {
            return;
        }

        $formId = $entity->get('formId');

        if (!$formId) {
            return;
        }

        $form = $this->entityManager->getEntityById(MetaLeadForm::ENTITY_TYPE, $formId);

        if (!$form) {
            return;
        }

        try {
            $teamsIds = $form->getLinkMultipleIdList('teams');
        } catch (\Throwable) {
            return;
        }

        if (!empty($teamsIds)) {
            $entity->set('teamsIds', $teamsIds);
        }
    }
}
