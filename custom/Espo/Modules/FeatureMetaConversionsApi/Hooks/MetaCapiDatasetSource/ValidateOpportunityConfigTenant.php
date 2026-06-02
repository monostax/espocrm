<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Hooks\MetaCapiDatasetSource;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDataset;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDatasetSource;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Refuses binding a Funnel / OpportunityStage / assignedUser from a different
 * tenant than the source's dataset.
 *
 * The per-source Opportunity-creation config drives auto-creation of
 * Opportunities (and, transitively, the SendCapiOnStageChange CAPI send keyed
 * off the funnel's dataset). If an admin wires a funnel belonging to another
 * tenant, conversions for this source would land in the wrong tenant's
 * pipeline and could fire CAPI events under the wrong dataset/token. Refuse
 * at save time.
 *
 * Funnel / OpportunityStage / User carry NO tenantId column — tenancy on those
 * entities is expressed via `teams`. So we derive each linked entity's tenant
 * from its teams → Tenant.baseUserTeam and compare to the dataset's tenantId.
 * If either side's tenant is unknown, the save proceeds (runtime checks in
 * OpportunityFactory still apply as defense in depth).
 *
 * @implements BeforeSave<MetaCapiDatasetSource>
 */
class ValidateOpportunityConfigTenant implements BeforeSave
{
    public static int $order = 16;

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof MetaCapiDatasetSource) {
            return;
        }

        if ($options->get('silent')) {
            return;
        }

        $changed =
            $entity->isNew()
            || $entity->isAttributeChanged('funnelId')
            || $entity->isAttributeChanged('opportunityStageId')
            || $entity->isAttributeChanged('assignedUserId');

        if (!$changed) {
            return;
        }

        $datasetId = $entity->get('metaCapiDatasetId');

        if (!$datasetId) {
            return;
        }

        $dataset = $this->entityManager->getEntityById(MetaCapiDataset::ENTITY_TYPE, $datasetId);

        if (!$dataset instanceof MetaCapiDataset) {
            return;
        }

        $datasetTenant = (string) ($dataset->get('tenantId') ?? '');

        if ($datasetTenant === '') {
            return;
        }

        $this->assertLinkTenant($entity, 'funnelId', 'Funnel', $datasetTenant);
        $this->assertLinkTenant($entity, 'opportunityStageId', 'OpportunityStage', $datasetTenant);
        $this->assertLinkTenant($entity, 'assignedUserId', 'User', $datasetTenant);
    }

    private function assertLinkTenant(
        MetaCapiDatasetSource $entity,
        string $attribute,
        string $entityType,
        string $datasetTenant
    ): void {
        $linkedId = (string) ($entity->get($attribute) ?? '');

        if ($linkedId === '') {
            return;
        }

        $linkedTenant = $this->resolveTenantViaTeams($entityType, $linkedId);

        if ($linkedTenant === '' || $linkedTenant === $datasetTenant) {
            return;
        }

        $this->log->error(sprintf(
            'MetaCapi: refused cross-tenant MetaCapiDatasetSource.%s — %s %s tenant=%s vs dataset tenant=%s.',
            $attribute,
            $entityType,
            $linkedId,
            $linkedTenant,
            $datasetTenant,
        ));

        throw new BadRequest(sprintf(
            'Cross-tenant link refused: the selected %s belongs to a different tenant than this source\'s dataset.',
            $entityType === 'OpportunityStage' ? 'stage' : strtolower($entityType),
        ));
    }

    /**
     * Resolve a teams-scoped entity's tenant via its teams → Tenant.baseUserTeam.
     */
    private function resolveTenantViaTeams(string $entityType, string $id): string
    {
        $linked = $this->entityManager->getEntityById($entityType, $id);

        if (!$linked) {
            return '';
        }

        try {
            $teamIds = $linked->getLinkMultipleIdList('teams');
        } catch (\Throwable) {
            return '';
        }

        if (empty($teamIds)) {
            return '';
        }

        $tenant = $this->entityManager
            ->getRDBRepository('Tenant')
            ->where(['baseUserTeamId' => $teamIds])
            ->findOne();

        return $tenant ? (string) $tenant->getId() : '';
    }
}
