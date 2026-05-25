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
 * Cascades tenant from the parent MetaLeadForm to this question row.
 *
 * Only sets tenantId on insert / when the field is empty so explicit
 * admin assignment is never overwritten. Mirrors
 * MetaLeadForm/CascadeTenantFromPage.
 *
 * @implements BeforeSave<MetaLeadFormQuestion>
 */
class CascadeTenantFromForm implements BeforeSave
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

        if ($entity->get('tenantId')) {
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

        $tenantId = $form->get('tenantId');

        if ($tenantId) {
            $entity->set('tenantId', $tenantId);
        }
    }
}
