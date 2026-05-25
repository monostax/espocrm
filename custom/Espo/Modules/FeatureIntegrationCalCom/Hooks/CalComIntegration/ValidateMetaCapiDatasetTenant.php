<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureIntegrationCalCom\Hooks\CalComIntegration;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureIntegrationCalCom\Entities\CalComIntegration;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDataset;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Refuses cross-tenant CalComIntegration.metaCapiDataset wiring.
 *
 * Why this is necessary:
 *   CalComBookingProcessor reads `integration.metaCapiDatasetId` and passes
 *   it to SendCapiEvent. If the wiring crosses tenants (admin error,
 *   migration), the cal.com webhook for tenant T1 fires a CAPI event
 *   under tenant T2's Pixel + access token. CapiDispatcher::assertTenantMatch
 *   catches this at dispatch time (defense in depth), but it's better to
 *   refuse the misconfiguration at config time so admins get an explicit
 *   error rather than mysteriously-Skipped CAPI deliveries.
 *
 * Bypass: `silent => true` saves (scripted migrations, restore tools) are
 * allowed through, matching the codebase's silent-respecting convention.
 *
 * Order 15 — after AssignTenantFromTeam (order 9) so the integration's
 * tenantId is populated by the time we compare.
 *
 * @implements BeforeSave<CalComIntegration>
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
        if (!$entity instanceof CalComIntegration) {
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

        $integrationTenant = (string) ($entity->get('tenantId') ?? '');
        $datasetTenant     = (string) ($dataset->get('tenantId') ?? '');

        // If either side has no tenant, we can't assert mismatch.
        if ($integrationTenant === '' || $datasetTenant === '') {
            return;
        }

        if ($integrationTenant === $datasetTenant) {
            return;
        }

        $this->log->error(sprintf(
            'CalCom: refused cross-tenant metaCapiDataset link — CalComIntegration %s tenant=%s vs MetaCapiDataset %s tenant=%s.',
            (string) $entity->getId(),
            $integrationTenant,
            (string) $dataset->getId(),
            $datasetTenant,
        ));

        throw new BadRequest(
            'Cross-tenant link refused: the selected MetaCapiDataset belongs to a different tenant than this CalComIntegration.'
        );
    }
}
