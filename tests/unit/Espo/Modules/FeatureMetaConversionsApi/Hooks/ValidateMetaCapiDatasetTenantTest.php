<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureMetaConversionsApi\Hooks;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDataset;
use Espo\Modules\FeatureMetaConversionsApi\Hooks\Funnel\ValidateMetaCapiDatasetTenant;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Wiring Funnel.metaCapiDataset across tenants is what later routes one
 * tenant's hashed PII (email/phone) to another tenant's Meta Pixel under that
 * tenant's access token. The guard originally read
 *
 *     if ($a !== '' && $b !== '' && $a !== $b) refuse;
 *
 * which fails OPEN on an unresolvable tenant: an empty tenant on either side
 * means "cannot prove a mismatch", so the cross-tenant link was persisted.
 * Both sides must now be known AND equal.
 */
class ValidateMetaCapiDatasetTenantTest extends TestCase
{
    private function makeHook(?string $datasetTenantId, bool $datasetExists = true): ValidateMetaCapiDatasetTenant
    {
        $entityManager = $this->createMock(EntityManager::class);

        $entityManager->method('getEntityById')->willReturnCallback(
            function (string $entityType, string $id) use ($datasetTenantId, $datasetExists): ?Entity {
                if (!$datasetExists) {
                    return null;
                }

                $dataset = $this->createMock(MetaCapiDataset::class);
                $dataset->method('getId')->willReturn($id);
                $dataset->method('get')->willReturnCallback(
                    static fn (string $attr) => $attr === 'tenantId' ? $datasetTenantId : null,
                );

                return $dataset;
            },
        );

        return new ValidateMetaCapiDatasetTenant($entityManager, $this->createMock(Log::class));
    }

    private function funnel(?string $tenantId, ?string $datasetId, bool $changed = true): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('getEntityType')->willReturn('Funnel');
        $entity->method('getId')->willReturn('funnel-1');
        $entity->method('isAttributeChanged')->willReturn($changed);
        $entity->method('get')->willReturnCallback(
            static fn (string $attr) => match ($attr) {
                'tenantId' => $tenantId,
                'metaCapiDatasetId' => $datasetId,
                default => null,
            },
        );

        return $entity;
    }

    private function options(bool $silent = false): SaveOptions
    {
        return SaveOptions::fromAssoc($silent ? ['silent' => true] : []);
    }

    public function testSameTenantLinkIsAllowed(): void
    {
        $hook = $this->makeHook('tenant-1');

        $hook->beforeSave($this->funnel('tenant-1', 'dataset-1'), $this->options());

        $this->addToAssertionCount(1);
    }

    public function testCrossTenantLinkIsRefused(): void
    {
        $hook = $this->makeHook('tenant-2');

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('different tenant');

        $hook->beforeSave($this->funnel('tenant-1', 'dataset-1'), $this->options());
    }

    /**
     * Fail-closed cases. Previously both returned early and persisted the link.
     */
    public function testUnresolvableDatasetTenantIsRefused(): void
    {
        $hook = $this->makeHook(null);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('could not be determined');

        $hook->beforeSave($this->funnel('tenant-1', 'dataset-1'), $this->options());
    }

    public function testUnresolvableFunnelTenantIsRefused(): void
    {
        $hook = $this->makeHook('tenant-1');

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('could not be determined');

        $hook->beforeSave($this->funnel(null, 'dataset-1'), $this->options());
    }

    public function testBothTenantsUnresolvableIsRefused(): void
    {
        $hook = $this->makeHook(null);

        $this->expectException(BadRequest::class);

        $hook->beforeSave($this->funnel('', 'dataset-1'), $this->options());
    }

    public function testNonFunnelEntityIsIgnored(): void
    {
        $hook = $this->makeHook(null);

        $entity = $this->createMock(Entity::class);
        $entity->method('getEntityType')->willReturn('Opportunity');
        $entity->expects($this->never())->method('isAttributeChanged');

        $hook->beforeSave($entity, $this->options());
    }

    /**
     * Documented escape hatch for scripted migrations / restore tooling.
     */
    public function testSilentSaveBypassesTheGuard(): void
    {
        $hook = $this->makeHook('tenant-2');

        $hook->beforeSave($this->funnel('tenant-1', 'dataset-1'), $this->options(silent: true));

        $this->addToAssertionCount(1);
    }

    public function testUnchangedDatasetLinkIsNotRevalidated(): void
    {
        $hook = $this->makeHook('tenant-2');

        $hook->beforeSave($this->funnel('tenant-1', 'dataset-1', changed: false), $this->options());

        $this->addToAssertionCount(1);
    }

    public function testClearingTheDatasetLinkIsAllowed(): void
    {
        $hook = $this->makeHook('tenant-2');

        $hook->beforeSave($this->funnel('tenant-1', null), $this->options());

        $this->addToAssertionCount(1);
    }

    public function testMissingDatasetIsLeftToTheRequiredValidators(): void
    {
        $hook = $this->makeHook(null, datasetExists: false);

        $hook->beforeSave($this->funnel('tenant-1', 'dataset-gone'), $this->options());

        $this->addToAssertionCount(1);
    }
}
