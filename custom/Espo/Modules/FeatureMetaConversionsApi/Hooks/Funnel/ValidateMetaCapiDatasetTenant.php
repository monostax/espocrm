<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Hooks\Funnel;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDataset;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Refuses cross-tenant Funnel.metaCapiDataset wiring.
 *
 * Why this is necessary:
 *   `DatasetResolver::resolveFromOpportunity` reads `Funnel.metaCapiDatasetId`
 *   directly. If that wiring crosses tenants (admin error, data migration,
 *   ACL bypass), Tenant A's Opportunity stage change fires a CAPI event
 *   under Tenant B's Pixel using Tenant B's access token. We have a
 *   defense-in-depth check in the resolver itself (returns null + logs),
 *   but it's better to refuse the misconfiguration at save time so admins
 *   get an explicit error instead of mysteriously-empty CAPI deliveries.
 *
 * Bypass: any save with `silent => true` (scripted migrations, restore
 * tools) is allowed through, matching the rest of the codebase's
 * silent-respecting convention.
 *
 * @implements BeforeSave<Entity>
 */
class ValidateMetaCapiDatasetTenant implements BeforeSave
{
    public static int $order = 15;

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        // Only act on Funnel rows. Cheaper than a strict instanceof check
        // because Funnel lives in the Crm module and we don't want to take
        // a hard dep on it from this module.
        if ($entity->getEntityType() !== 'Funnel') {
            return;
        }

        if ($options->get('silent')) {
            return;
        }

        if (!$entity->isAttributeChanged('metaCapiDatasetId')) {
            return;
        }

        $datasetId = $entity->get('metaCapiDatasetId');

        if (!$datasetId) {
            return;
        }

        $dataset = $this->entityManager
            ->getEntityById(MetaCapiDataset::ENTITY_TYPE, $datasetId);

        if (!$dataset instanceof MetaCapiDataset) {
            return;
        }

        $funnelTenant  = (string) ($entity->get('tenantId') ?? '');
        $datasetTenant = (string) ($dataset->get('tenantId') ?? '');

        // If either side has no tenant, we can't assert mismatch.
        // (Funnel.tenant is derived by a different hook; if it's missing
        // we let the save proceed and rely on the DatasetResolver's
        // runtime check.)
        if ($funnelTenant === '' || $datasetTenant === '') {
            return;
        }

        if ($funnelTenant === $datasetTenant) {
            return;
        }

        $this->log->error(sprintf(
            'MetaCapi: refused cross-tenant Funnel.metaCapiDataset link — Funnel %s tenant=%s vs MetaCapiDataset %s tenant=%s.',
            (string) $entity->getId(),
            $funnelTenant,
            (string) $dataset->getId(),
            $datasetTenant,
        ));

        throw new BadRequest(
            'Cross-tenant link refused: the selected MetaCapiDataset belongs to a different tenant than this Funnel.'
        );
    }
}
