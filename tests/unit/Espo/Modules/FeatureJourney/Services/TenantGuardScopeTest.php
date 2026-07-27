<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureJourney\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata;
use Espo\Modules\FeatureJourney\Services\CustomFieldsBag;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\Modules\FeatureJourney\Services\TenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * Tenant isolation is the security boundary for Journey/Automation reads: the
 * run-as user may be an Espo admin, and admins bypass ACL entirely, so an
 * unresolved tenant must abort rather than widen to an instance-wide query.
 */
class TenantGuardScopeTest extends TestCase
{
    private function makeGuard(?TenantResolver $resolver = null): TenantGuard
    {
        return new TenantGuard(
            $this->createMock(EntityManager::class),
            $resolver ?? $this->createMock(TenantResolver::class),
            $this->createMock(Metadata::class),
            $this->createMock(CustomFieldsBag::class),
            $this->createMock(Config::class),
            $this->createMock(Log::class),
        );
    }

    /**
     * @return iterable<string, array{0: ?string}>
     */
    public static function unresolvedTenantProvider(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
        yield 'whitespace only' => ['   '];
    }

    /**
     * @dataProvider unresolvedTenantProvider
     */
    public function testAssertTenantScopeRejectsUnresolvedTenant(?string $tenantId): void
    {
        $guard = $this->makeGuard();

        $this->expectException(Error::class);
        $this->expectExceptionMessage('requires a resolved tenant');

        $guard->assertTenantScope($tenantId, 'unit read');
    }

    public function testAssertTenantScopeReturnsTrimmedTenantId(): void
    {
        $this->assertSame('tenant-1', $this->makeGuard()->assertTenantScope(' tenant-1 ', 'unit read'));
    }

    /**
     * @dataProvider unresolvedTenantProvider
     */
    public function testTenantWhereForReadFailsClosedWithoutCrossTenantOptIn(?string $tenantId): void
    {
        $guard = $this->makeGuard();

        $this->expectException(Error::class);

        $guard->tenantWhereForRead('Contact', $tenantId, 'unit read');
    }

    public function testTenantWhereForReadReturnsEmptyOnlyWhenCrossTenantAuthorized(): void
    {
        $guard = $this->makeGuard();

        // The empty array is the sentinel for "no tenant predicate". It must be
        // reachable only through the explicit opt-in, never through a null tenant.
        $this->assertSame([], $guard->tenantWhereForRead('Contact', null, 'unit read', true));
    }

    public function testTenantWhereForEntityTypeScopesTenantEntityByIdentity(): void
    {
        // A Tenant row has no tenantId column; scoping it by teams (or matching
        // nothing) would silently expose or hide the wrong workspace.
        $this->assertSame(
            ['id' => 'tenant-9'],
            $this->makeGuard()->tenantWhereForEntityType('Tenant', 'tenant-9'),
        );
    }

    public function testTenantWhereForEntityTypeMatchesNothingOnEmptyTenant(): void
    {
        $this->assertSame(
            ['id' => null],
            $this->makeGuard()->tenantWhereForEntityType('Contact', ''),
        );
    }

    /**
     * @dataProvider unresolvedTenantProvider
     */
    public function testEntityAllowedForReadFailsClosedOnUnresolvedTenant(?string $tenantId): void
    {
        $guard = $this->makeGuard();

        $this->assertFalse($guard->entityAllowedForRead($this->createMock(Entity::class), $tenantId));
    }

    public function testEntityAllowedForReadAllowsAnyEntityWhenCrossTenantAuthorized(): void
    {
        $guard = $this->makeGuard();

        $this->assertTrue(
            $guard->entityAllowedForRead($this->createMock(Entity::class), null, true),
        );
    }

    public function testEntityAllowedForReadRejectsForeignTenantRow(): void
    {
        $resolver = $this->createMock(TenantResolver::class);
        $resolver->method('resolveTenantIdForEntity')->willReturn('tenant-other');

        $entity = $this->createMock(Entity::class);
        $entity->method('hasAttribute')->with('tenantId')->willReturn(true);
        $entity->method('get')->with('tenantId')->willReturn('tenant-other');

        $this->assertFalse(
            $this->makeGuard($resolver)->entityAllowedForRead($entity, 'tenant-mine'),
        );
    }

    public function testEntityAllowedForReadAcceptsSameTenantRow(): void
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('hasAttribute')->with('tenantId')->willReturn(true);
        $entity->method('get')->with('tenantId')->willReturn('tenant-mine');

        $this->assertTrue(
            $this->makeGuard()->entityAllowedForRead($entity, 'tenant-mine'),
        );
    }
}
