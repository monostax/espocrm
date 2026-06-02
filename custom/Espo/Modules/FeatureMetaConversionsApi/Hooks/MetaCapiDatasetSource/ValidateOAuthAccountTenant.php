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
 * Refuses binding an OAuthAccount from a different tenant than the dataset.
 *
 * A source binding tells the DatasetResolver which dataset (Pixel + access
 * token) reports conversions for a given WABA / IG business account. If the
 * authorizing OAuthAccount belongs to another tenant, we'd report one tenant's
 * conversions through another tenant's credentials. Refuse at save time.
 *
 * The OAuthAccount's tenant is resolved via its teams → Tenant.baseUserTeam.
 * If either side's tenant is unknown the save proceeds (runtime checks apply).
 *
 * @implements BeforeSave<MetaCapiDatasetSource>
 */
class ValidateOAuthAccountTenant implements BeforeSave
{
    public static int $order = 15;

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

        $oAuthTenant = $this->resolveOAuthAccountTenant((string) $oAuthAccountId);

        if ($oAuthTenant === '' || $oAuthTenant === $datasetTenant) {
            return;
        }

        $this->log->error(sprintf(
            'MetaCapi: refused cross-tenant MetaCapiDatasetSource.oAuthAccount link — OAuthAccount %s tenant=%s vs MetaCapiDataset %s tenant=%s.',
            (string) $oAuthAccountId,
            $oAuthTenant,
            (string) $dataset->getId(),
            $datasetTenant,
        ));

        throw new BadRequest(
            'Cross-tenant link refused: the selected Meta account belongs to a different tenant than this dataset.'
        );
    }

    /**
     * Resolves an OAuthAccount's tenant via its teams → Tenant.baseUserTeam.
     */
    private function resolveOAuthAccountTenant(string $oAuthAccountId): string
    {
        $oAuthAccount = $this->entityManager->getEntityById('OAuthAccount', $oAuthAccountId);

        if (!$oAuthAccount) {
            return '';
        }

        try {
            $teamIds = $oAuthAccount->getLinkMultipleIdList('teams');
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
