<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Hooks\MetaCapiDatasetSource;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Utils\Log;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDataset;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDatasetSource;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Refuses binding an OAuthAccount from a different tenant than the dataset.
 *
 * A source binding tells the DatasetResolver which dataset (Pixel + access
 * token) reports conversions for a given WABA / IG business account. If the
 * authorizing OAuthAccount belongs to another tenant, we'd report one tenant's
 * conversions through another tenant's credentials. Refuse at save time.
 *
 * The OAuthAccount's tenants are resolved from its teams via TenantResolver,
 * counting both a tenant's base user team and its other user teams, and the
 * dataset's tenant must be one of them. If the dataset has no tenant, or the
 * account resolves to none, the save proceeds (runtime checks apply).
 *
 * @implements BeforeSave<MetaCapiDatasetSource>
 */
class ValidateOAuthAccountTenant implements BeforeSave
{
    public static int $order = 15;

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

        if (!$entity->isNew() && !$entity->isAttributeChanged('oAuthAccountId')) {
            return;
        }

        $oAuthAccountId = $entity->get('oAuthAccountId');
        $datasetId = $entity->get('metaCapiDatasetId');

        if (!$oAuthAccountId || !$datasetId) {
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

        $oAuthTenantIds = $this->resolveOAuthAccountTenantIds((string) $oAuthAccountId);

        // Unresolved stays permissive, as before: an account owned by no tenant
        // cannot name a foreign one. But an account reachable from several tenants
        // is legitimate for any of them, so test membership instead of equality —
        // picking one and comparing would refuse valid links at random.
        if ($oAuthTenantIds === [] || in_array($datasetTenant, $oAuthTenantIds, true)) {
            return;
        }

        $this->log->error(sprintf(
            'MetaCapi: refused cross-tenant MetaCapiDatasetSource.oAuthAccount link — OAuthAccount %s tenant=%s vs MetaCapiDataset %s tenant=%s.',
            (string) $oAuthAccountId,
            implode(',', $oAuthTenantIds),
            (string) $dataset->getId(),
            $datasetTenant,
        ));

        throw new BadRequest(
            'Cross-tenant link refused: the selected Meta account belongs to a different tenant than this dataset.'
        );
    }

    /**
     * Every tenant reachable from the OAuthAccount's teams.
     *
     * Delegated to the canonical TenantResolver, which counts a tenant's base user
     * team AND its other user teams. Matching only the base team returned '' for an
     * account held by a secondary team, and '' is treated as "no tenant, allow" up
     * in beforeSave — so this guard used to wave through exactly the cross-tenant
     * link it exists to refuse.
     *
     * @return list<string>
     */
    private function resolveOAuthAccountTenantIds(string $oAuthAccountId): array
    {
        $oAuthAccount = $this->entityManager->getEntityById('OAuthAccount', $oAuthAccountId);

        // Only CoreEntity exposes link-multiple reads; anything else cannot name a
        // tenant, which the permissive branch in beforeSave already handles.
        if (!$oAuthAccount instanceof CoreEntity) {
            return [];
        }

        try {
            $teamIds = $oAuthAccount->getLinkMultipleIdList('teams');
        } catch (\Throwable) {
            return [];
        }

        if (empty($teamIds)) {
            return [];
        }

        return $this->tenantResolver->resolveAllFromTeamIds(array_values($teamIds));
    }
}
