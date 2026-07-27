<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Hooks\MetaCapiDatasetSource;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDataset;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDatasetSource;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
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
 * Funnel carries its own required `tenantId`, so it is compared directly.
 * OpportunityStage and User carry NO tenantId column — tenancy on those is
 * expressed via `teams`, so their tenant is derived through TenantResolver
 * (which covers BOTH Tenant.baseUserTeam and Tenant.otherUserTeams; matching
 * only baseUserTeam silently failed to resolve any entity scoped to a tenant's
 * secondary team, which then fell through this check entirely).
 *
 * Fails CLOSED: a link whose tenant cannot be resolved is refused, because
 * same-tenancy cannot be proven. The single exception is an `assignedUser` who
 * is an instance admin — admins legitimately belong to no tenant team.
 *
 * @implements BeforeSave<MetaCapiDatasetSource>
 */
class ValidateOpportunityConfigTenant implements BeforeSave
{
    public static int $order = 16;

    public function __construct(
        private EntityManager $entityManager,
        private TenantResolver $tenantResolver,
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

        $linked = $this->entityManager->getEntityById($entityType, $linkedId);

        if (!$linked) {
            return;
        }

        // Instance admins legitimately belong to no tenant team, so an admin
        // assignedUser is not evidence of a cross-tenant binding.
        if ($linked instanceof User && $linked->isAdmin()) {
            return;
        }

        $linkedTenant = $this->resolveLinkedTenant($linked);

        if ($linkedTenant !== '' && $linkedTenant === $datasetTenant) {
            return;
        }

        $this->log->error(sprintf(
            'MetaCapi: refused MetaCapiDatasetSource.%s — %s %s tenant=%s vs dataset tenant=%s '
            . '(both must be set and equal).',
            $attribute,
            $entityType,
            $linkedId,
            $linkedTenant !== '' ? $linkedTenant : '(unset)',
            $datasetTenant !== '' ? $datasetTenant : '(unset)',
        ));

        $label = $entityType === 'OpportunityStage' ? 'stage' : strtolower($entityType);

        if ($linkedTenant === '' || $datasetTenant === '') {
            throw new BadRequest(sprintf(
                'Cannot bind this %s: its tenant and/or the dataset\'s tenant could not be determined. '
                . 'Ensure both belong to a team that maps to exactly one Tenant.',
                $label,
            ));
        }

        throw new BadRequest(sprintf(
            'Cross-tenant link refused: the selected %s belongs to a different tenant than this source\'s dataset.',
            $label,
        ));
    }

    /**
     * Resolve a linked entity's tenant: its own tenantId when it has one
     * (Funnel), otherwise derived from its teams via TenantResolver, which
     * covers both Tenant.baseUserTeam and Tenant.otherUserTeams.
     */
    private function resolveLinkedTenant(Entity $linked): string
    {
        $own = (string) ($linked->get('tenantId') ?? '');

        if ($own !== '') {
            return $own;
        }

        if (!$linked instanceof CoreEntity || !$linked->hasLinkMultipleField('teams')) {
            return '';
        }

        $teamIds = [];

        foreach ($linked->getLinkMultipleIdList('teams') as $teamId) {
            if (is_string($teamId) && $teamId !== '') {
                $teamIds[] = $teamId;
            }
        }

        if ($teamIds === []) {
            return '';
        }

        return (string) ($this->tenantResolver->resolveFromTeamIds($teamIds) ?? '');
    }
}
