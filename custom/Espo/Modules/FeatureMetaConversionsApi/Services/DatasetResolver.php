<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Services;

use Espo\Core\Utils\Log;
use Espo\Modules\Crm\Entities\Opportunity;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDataset;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Resolves which MetaCapiDataset should receive an event for a given source entity.
 *
 * Resolution order:
 *   1. Opportunity.funnel.metaCapiDataset (if funnel.metaCapiEnabled=true)
 *      — with a defense-in-depth assertion that the resolved dataset's
 *        tenantId matches the Opportunity's tenantId.
 *   2. Tenant-scoped default: MetaCapiDataset where
 *      `tenantId={subject.tenantId} AND isDefault=true AND isActive=true`.
 *   3. null (caller must skip).
 *
 * The legacy global `getDefault()` (no tenant filter) is intentionally removed.
 * Returning a "global default" in a shared-database multi-tenant CRM means
 * Tenant B's stage change could fire a CAPI event under Tenant A's Pixel
 * using Tenant A's access token — silently mis-attributing ad spend and
 * leaking PII (hashed email/phone) across tenants.
 */
class DatasetResolver
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function resolveForEntity(Entity $entity): ?MetaCapiDataset
    {
        if ($entity instanceof Opportunity) {
            $resolved = $this->resolveFromOpportunity($entity);

            if ($resolved) {
                return $resolved;
            }
        }

        // Tenant-scoped default fallback. Refuse to lookup globally.
        $tenantId = $this->extractTenantId($entity);

        if (!$tenantId) {
            $this->log->info(sprintf(
                'MetaCapi DatasetResolver: %s %s has no tenantId; refusing global-default lookup.',
                $entity->getEntityType(),
                (string) $entity->getId(),
            ));

            return null;
        }

        return $this->getDefaultForTenant($tenantId);
    }

    public function resolveFromOpportunity(Opportunity $opportunity): ?MetaCapiDataset
    {
        $funnelId = $opportunity->get('funnelId');

        if (!$funnelId) {
            return null;
        }

        $funnel = $this->entityManager->getEntityById('Funnel', $funnelId);

        if (!$funnel) {
            return null;
        }

        if (!$funnel->get('metaCapiEnabled')) {
            return null;
        }

        $datasetId = $funnel->get('metaCapiDatasetId');

        if (!$datasetId) {
            return null;
        }

        $dataset = $this->entityManager->getEntityById('MetaCapiDataset', $datasetId);

        if (!$dataset instanceof MetaCapiDataset) {
            return null;
        }

        if (!$dataset->get('isActive')) {
            return null;
        }

        // Defense in depth — guard against funnel→dataset wiring that crosses
        // tenant boundaries (admin misconfiguration, data migration error,
        // ACL bypass). Without this check, a misconfigured funnel would
        // silently route Tenant A's events to Tenant B's Pixel.
        $oppTenantId = (string) ($opportunity->get('tenantId') ?? '');
        $dsTenantId  = (string) ($dataset->get('tenantId') ?? '');

        if ($oppTenantId !== '' && $dsTenantId !== '' && $oppTenantId !== $dsTenantId) {
            $this->log->error(sprintf(
                'MetaCapi: cross-tenant dataset resolution refused — Opportunity %s tenant=%s vs Funnel.metaCapiDataset %s tenant=%s.',
                (string) $opportunity->getId(),
                $oppTenantId,
                (string) $dataset->getId(),
                $dsTenantId,
            ));

            return null;
        }

        return $dataset;
    }

    /**
     * Find the active default dataset for a specific tenant.
     */
    public function getDefaultForTenant(string $tenantId): ?MetaCapiDataset
    {
        $dataset = $this->entityManager
            ->getRDBRepository('MetaCapiDataset')
            ->where([
                'tenantId'  => $tenantId,
                'isDefault' => true,
                'isActive'  => true,
                'deleted'   => false,
            ])
            ->findOne();

        return $dataset instanceof MetaCapiDataset ? $dataset : null;
    }

    /**
     * Tenant-blind global default lookup.
     *
     * Kept for backwards compatibility with any external code, but
     * intentionally returns null with a warning. All callers should pass
     * tenant context via `resolveForEntity` or `getDefaultForTenant`.
     *
     * @deprecated Tenant-blind default lookups are unsafe in shared-DB
     *             multi-tenant deployments. Use `getDefaultForTenant` or
     *             `resolveForEntity` instead.
     */
    public function getDefault(): ?MetaCapiDataset
    {
        $this->log->warning(
            'MetaCapi DatasetResolver::getDefault() called without tenant context — refused. ' .
            'Use getDefaultForTenant() or resolveForEntity() instead.'
        );

        return null;
    }

    private function extractTenantId(Entity $entity): ?string
    {
        $tid = $entity->get('tenantId');

        return is_string($tid) && $tid !== '' ? $tid : null;
    }
}
