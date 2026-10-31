<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Tools\FeaturePack;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\Global\Classes\Utils\FeatureVerticalChecker;
use Espo\Modules\Global\Tools\FeaturePack\Api\GetForAccount;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The Chatwoot shell asks which packs a workspace has. A user may only learn
 * the packs of a tenant they can act for; another tenant's answer is refused.
 */
class GetForAccountTest extends TestCase
{
    public function testMemberGetsTheTenantPacks(): void
    {
        $action = $this->makeAction(isAdmin: false, canRead: true, canAct: true, tenantPacks: ['calls']);

        $this->assertSame(
            ['tenantId' => 'tenant-1', 'packs' => ['calls']],
            $this->decode($action->process($this->request('9'))),
        );
    }

    public function testUserOfAnotherTenantIsForbidden(): void
    {
        $action = $this->makeAction(isAdmin: false, canRead: true, canAct: false, expectPackLookup: false);

        $this->expectException(Forbidden::class);
        $action->process($this->request('9'));
    }

    public function testUnreadableAccountIsForbidden(): void
    {
        $action = $this->makeAction(isAdmin: false, canRead: false, canAct: true, expectPackLookup: false);

        $this->expectException(Forbidden::class);
        $action->process($this->request('9'));
    }

    public function testUnprovisionedAccountIsForbiddenEvenForAdmins(): void
    {
        $action = $this->makeAction(isAdmin: true, canRead: true, canAct: true, platformId: null, expectPackLookup: false);

        $this->expectException(Forbidden::class);
        $action->process($this->request('9'));
    }

    public function testInstanceAdminGetsEveryPack(): void
    {
        $action = $this->makeAction(isAdmin: true, canRead: false, canAct: false, allPacks: ['b2b', 'calls', 'sms']);

        $this->assertSame(
            ['tenantId' => 'tenant-1', 'packs' => ['b2b', 'calls', 'sms']],
            $this->decode($action->process($this->request('9'))),
        );
    }

    public function testMissingAccountIdIsRejected(): void
    {
        $action = $this->makeAction(isAdmin: false, canRead: true, canAct: true, expectPackLookup: false);

        $this->expectException(BadRequest::class);
        $action->process($this->request(null));
    }

    public function testUnknownAccountIsRejected(): void
    {
        $action = $this->makeAction(isAdmin: false, canRead: true, canAct: true, accountFound: false, expectPackLookup: false);

        $this->expectException(BadRequest::class);
        $action->process($this->request('404'));
    }

    /**
     * @param list<string> $tenantPacks
     * @param list<string> $allPacks
     */
    private function makeAction(
        bool $isAdmin,
        bool $canRead,
        bool $canAct,
        array $tenantPacks = [],
        array $allPacks = [],
        ?string $platformId = 'platform-1',
        bool $accountFound = true,
        bool $expectPackLookup = true,
    ): GetForAccount {
        $account = $this->createMock(Entity::class);
        $account->method('get')->willReturnCallback(fn (string $field) => match ($field) {
            'tenantId' => 'tenant-1',
            'platformId' => $platformId,
            default => null,
        });

        $builder = $this->createMock(RDBSelectBuilder::class);
        $builder->method('limit')->willReturnSelf();
        $builder->method('find')->willReturn(new EntityCollection($accountFound ? [$account] : []));

        $repository = $this->createMock(RDBRepository::class);
        $repository->method('where')->willReturnCallback(function (array $where) use ($builder) {
            $this->assertSame(['chatwootAccountId' => (int) $where['chatwootAccountId']], $where);

            return $builder;
        });

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getRDBRepository')->with('ChatwootAccount')->willReturn($repository);

        $acl = $this->createMock(Acl::class);
        $acl->method('checkEntityRead')->willReturn($canRead);

        $user = $this->createMock(User::class);
        $user->method('isAdmin')->willReturn($isAdmin);

        $resolver = $this->createMock(UserTenantResolver::class);
        $resolver->method('canActForTenant')->with($user, 'tenant-1')->willReturn($canAct);

        $checker = $this->createMock(FeatureVerticalChecker::class);
        $checker->method('getAllVerticalNames')->willReturn($allPacks);

        if ($expectPackLookup && !$isAdmin) {
            $checker->expects($this->once())->method('getTenantVerticals')->with('tenant-1')->willReturn($tenantPacks);
        } else {
            $checker->expects($this->never())->method('getTenantVerticals');
        }

        return new GetForAccount($entityManager, $acl, $user, $resolver, $checker);
    }

    private function request(?string $accountId): Request
    {
        $request = $this->createMock(Request::class);
        $request->method('getQueryParam')->with('accountId')->willReturn($accountId);

        return $request;
    }

    private function decode(\Espo\Core\Api\Response $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
