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
 * Cascades tenant from the parent MetaLeadForm to this leadgen event.
 *
 * Denormalizes MetaLeadForm.tenant onto MetaLeadgenEvent so per-tenant
 * filters can index/group directly on the event log table without
 * joining through the lead form.
 *
 * Only sets tenantId on insert / when the field is empty, so an
 * explicit assignment is never overwritten.
 *
 * @implements BeforeSave<MetaLeadgenEvent>
 */
class CascadeTenantFromLeadForm implements BeforeSave
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

        if ($entity->get('tenantId')) {
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

        $tenantId = $leadForm->get('tenantId');

        if ($tenantId) {
            $entity->set('tenantId', $tenantId);
        }
    }
}
