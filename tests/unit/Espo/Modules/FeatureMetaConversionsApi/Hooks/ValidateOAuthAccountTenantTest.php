<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureMetaConversionsApi\Hooks;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDataset;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDatasetSource;
use Espo\Modules\FeatureMetaConversionsApi\Hooks\MetaCapiDatasetSource\ValidateOAuthAccountTenant;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Binding an OAuthAccount from another tenant reports one tenant's conversions
 * through another tenant's Pixel and access token, so the link is refused at
 * save time.
 *
 * The resolution used to read `Tenant.baseUserTeam` only and `findOne()`:
 *
 *   - An account held by a tenant's *other user team* resolved to no tenant,
 *     and an unresolved tenant is deliberately permissive here — so the guard
 *     waved through exactly the cross-tenant link it exists to refuse.
 *   - When several tenants matched, `findOne()` picked one arbitrarily, which
 *     both invented mismatches and hid real ones.
 *
 * Resolution now delegates to TenantResolver (base user team AND other user
 * teams) and the dataset tenant is tested for membership in that set.
 */
class ValidateOAuthAccountTenantTest extends TestCase
{
    /**
     * @param array<string, list<string>> $baseTeamToTenants  team id => tenants owning it as base user team
     * @param array<string, list<string>> $otherTeamToTenants team id => tenants owning it as other user team
     * @param list<string>|null           $accountTeamIds     null => the OAuthAccount row is missing
     */
    private function makeHook(
        ?string $datasetTenantId,
        ?array $accountTeamIds,
        array $baseTeamToTenants = [],
        array $otherTeamToTenants = [],
        bool $datasetExists = true,
        bool $teamsThrow = false,
    ): ValidateOAuthAccountTenant {
        $entityManager = $this->createMock(EntityManager::class);

        $entityManager->method('getEntityById')->willReturnCallback(
            function (string $entityType, string $id) use (
                $datasetTenantId,
                $datasetExists,
                $accountTeamIds,
                $teamsThrow
            ): ?Entity {
                if ($entityType === MetaCapiDataset::ENTITY_TYPE) {
                    if (!$datasetExists) {
                        return null;
                    }

                    $dataset = $this->createMock(MetaCapiDataset::class);
                    $dataset->method('getId')->willReturn($id);
                    $dataset->method('get')->willReturnCallback(
                        static fn (string $attr) => $attr === 'tenantId' ? $datasetTenantId : null,
                    );

                    return $dataset;
                }

                if ($accountTeamIds === null) {
                    return null;
                }

                $account = $this->createMock(CoreEntity::class);
                $account->method('getId')->willReturn($id);

                if ($teamsThrow) {
                    $account->method('getLinkMultipleIdList')
                        ->willThrowException(new \RuntimeException('teams not loaded'));
                } else {
                    $account->method('getLinkMultipleIdList')->willReturn($accountTeamIds);
                }

                return $account;
            },
        );

        $tenantResolver = $this->createMock(TenantResolver::class);
        $tenantResolver->method('resolveAllFromTeamIds')->willReturnCallback(
            static function (array $teamIds) use ($baseTeamToTenants, $otherTeamToTenants): array {
                $tenantIds = [];

                foreach ($teamIds as $teamId) {
                    foreach ($baseTeamToTenants[$teamId] ?? [] as $tenantId) {
                        $tenantIds[$tenantId] = true;
                    }

                    foreach ($otherTeamToTenants[$teamId] ?? [] as $tenantId) {
                        $tenantIds[$tenantId] = true;
                    }
                }

                return array_keys($tenantIds);
            },
        );

        return new ValidateOAuthAccountTenant(
            $entityManager,
            $tenantResolver,
            $this->createMock(Log::class),
        );
    }

    private function source(
        ?string $oAuthAccountId,
        ?string $datasetId,
        bool $isNew = true,
        bool $changed = true,
    ): Entity {
        $entity = $this->createMock(MetaCapiDatasetSource::class);
        $entity->method('getId')->willReturn('source-1');
        $entity->method('isNew')->willReturn($isNew);
        $entity->method('isAttributeChanged')->willReturn($changed);
        $entity->method('get')->willReturnCallback(
            static fn (string $attr) => match ($attr) {
                'oAuthAccountId' => $oAuthAccountId,
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
        $hook = $this->makeHook('tenant-1', ['team-a'], ['team-a' => ['tenant-1']]);

        $hook->beforeSave($this->source('oauth-1', 'dataset-1'), $this->options());

        $this->addToAssertionCount(1);
    }

    public function testCrossTenantLinkIsRefused(): void
    {
        $hook = $this->makeHook('tenant-1', ['team-b'], ['team-b' => ['tenant-2']]);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('different tenant');

        $hook->beforeSave($this->source('oauth-1', 'dataset-1'), $this->options());
    }

    /**
     * The regression. The account belongs to tenant-2 through that tenant's
     * *other user team*, so base-team-only resolution saw no tenant at all and
     * the permissive branch persisted a cross-tenant link.
     */
    public function testCrossTenantLinkViaOtherUserTeamIsRefused(): void
    {
        $hook = $this->makeHook(
            'tenant-1',
            ['team-b'],
            baseTeamToTenants: [],
            otherTeamToTenants: ['team-b' => ['tenant-2']],
        );

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('different tenant');

        $hook->beforeSave($this->source('oauth-1', 'dataset-1'), $this->options());
    }

    /**
     * Mirror of the above: a secondary-team account linked to *its own* tenant
     * is legitimate and must not become collateral damage of the fix.
     */
    public function testSameTenantLinkViaOtherUserTeamIsAllowed(): void
    {
        $hook = $this->makeHook(
            'tenant-1',
            ['team-b'],
            baseTeamToTenants: [],
            otherTeamToTenants: ['team-b' => ['tenant-1']],
        );

        $hook->beforeSave($this->source('oauth-1', 'dataset-1'), $this->options());

        $this->addToAssertionCount(1);
    }

    /**
     * An account reachable from several tenants is legitimate for any of them.
     * findOne() used to pick one arbitrarily, so this passed or failed by luck.
     */
    public function testMultiTenantAccountIsAllowedForAnyOfItsTenants(): void
    {
        $hook = $this->makeHook(
            'tenant-2',
            ['team-a', 'team-b'],
            baseTeamToTenants: ['team-a' => ['tenant-1']],
            otherTeamToTenants: ['team-b' => ['tenant-2']],
        );

        $hook->beforeSave($this->source('oauth-1', 'dataset-1'), $this->options());

        $this->addToAssertionCount(1);
    }

    public function testMultiTenantAccountIsStillRefusedForAnOutsideTenant(): void
    {
        $hook = $this->makeHook(
            'tenant-3',
            ['team-a', 'team-b'],
            baseTeamToTenants: ['team-a' => ['tenant-1']],
            otherTeamToTenants: ['team-b' => ['tenant-2']],
        );

        $this->expectException(BadRequest::class);

        $hook->beforeSave($this->source('oauth-1', 'dataset-1'), $this->options());
    }

    /**
     * Deliberately permissive: an account owned by no tenant cannot name a
     * foreign one, and refusing would break functional-team deployments.
     */
    public function testAccountWithNoTenantIsAllowed(): void
    {
        $hook = $this->makeHook('tenant-1', ['team-z']);

        $hook->beforeSave($this->source('oauth-1', 'dataset-1'), $this->options());

        $this->addToAssertionCount(1);
    }

    public function testAccountWithNoTeamsIsAllowed(): void
    {
        $hook = $this->makeHook('tenant-1', []);

        $hook->beforeSave($this->source('oauth-1', 'dataset-1'), $this->options());

        $this->addToAssertionCount(1);
    }

    public function testUnloadableTeamsAreTreatedAsUnresolved(): void
    {
        $hook = $this->makeHook('tenant-1', ['team-b'], ['team-b' => ['tenant-2']], teamsThrow: true);

        $hook->beforeSave($this->source('oauth-1', 'dataset-1'), $this->options());

        $this->addToAssertionCount(1);
    }

    public function testMissingAccountIsLeftToTheRequiredValidators(): void
    {
        $hook = $this->makeHook('tenant-1', null);

        $hook->beforeSave($this->source('oauth-gone', 'dataset-1'), $this->options());

        $this->addToAssertionCount(1);
    }

    public function testMissingDatasetIsLeftToTheRequiredValidators(): void
    {
        $hook = $this->makeHook('tenant-1', ['team-b'], ['team-b' => ['tenant-2']], datasetExists: false);

        $hook->beforeSave($this->source('oauth-1', 'dataset-gone'), $this->options());

        $this->addToAssertionCount(1);
    }

    public function testDatasetWithoutTenantIsAllowed(): void
    {
        $hook = $this->makeHook(null, ['team-b'], ['team-b' => ['tenant-2']]);

        $hook->beforeSave($this->source('oauth-1', 'dataset-1'), $this->options());

        $this->addToAssertionCount(1);
    }

    public function testClearingTheAccountLinkIsAllowed(): void
    {
        $hook = $this->makeHook('tenant-1', ['team-b'], ['team-b' => ['tenant-2']]);

        $hook->beforeSave($this->source(null, 'dataset-1'), $this->options());

        $this->addToAssertionCount(1);
    }

    public function testUnchangedAccountLinkIsNotRevalidated(): void
    {
        $hook = $this->makeHook('tenant-1', ['team-b'], ['team-b' => ['tenant-2']]);

        $hook->beforeSave(
            $this->source('oauth-1', 'dataset-1', isNew: false, changed: false),
            $this->options(),
        );

        $this->addToAssertionCount(1);
    }

    /**
     * Documented escape hatch for scripted migrations / restore tooling.
     */
    public function testSilentSaveBypassesTheGuard(): void
    {
        $hook = $this->makeHook('tenant-1', ['team-b'], ['team-b' => ['tenant-2']]);

        $hook->beforeSave($this->source('oauth-1', 'dataset-1'), $this->options(silent: true));

        $this->addToAssertionCount(1);
    }

    public function testNonSourceEntityIsIgnored(): void
    {
        $hook = $this->makeHook('tenant-1', ['team-b'], ['team-b' => ['tenant-2']]);

        $entity = $this->createMock(Entity::class);
        $entity->expects($this->never())->method('isAttributeChanged');

        $hook->beforeSave($entity, $this->options());
    }
}
