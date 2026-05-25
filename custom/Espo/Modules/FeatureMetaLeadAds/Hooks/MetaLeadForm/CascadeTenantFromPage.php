<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaLeadAds\Hooks\MetaLeadForm;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureMetaLeadAds\Entities\MetaFacebookPage;
use Espo\Modules\FeatureMetaLeadAds\Entities\MetaLeadForm;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Cascades tenant from the parent MetaFacebookPage to this lead form.
 *
 * Denormalizes MetaFacebookPage.tenant onto MetaLeadForm so per-tenant
 * filters can index/group directly on the lead form table without
 * joining through the page.
 *
 * Only sets tenantId on insert / when the field is empty, so an
 * explicit assignment is never overwritten.
 *
 * @implements BeforeSave<MetaLeadForm>
 */
class CascadeTenantFromPage implements BeforeSave
{
    public static int $order = 1;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof MetaLeadForm) {
            return;
        }

        if ($options->get('silent')) {
            return;
        }

        if ($entity->get('tenantId')) {
            return;
        }

        $pageId = $entity->get('pageId');

        if (!$pageId) {
            return;
        }

        $page = $this->entityManager->getEntityById(MetaFacebookPage::ENTITY_TYPE, $pageId);

        if (!$page) {
            return;
        }

        $tenantId = $page->get('tenantId');

        if ($tenantId) {
            $entity->set('tenantId', $tenantId);
        }
    }
}
