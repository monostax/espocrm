<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureSelfService;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Config;
use Espo\Entities\User;
use Espo\Modules\FeatureSelfService\Controllers\SelfServiceWorkspace;
use Espo\Modules\FeatureSelfService\Tools\Plans\PlanCheckout;
use Espo\Modules\FeatureSelfService\Tools\Workspace\WorkspaceTrial;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PlanPurchaseAccessTest extends TestCase
{
    public static function accessCases(): array
    {
        return [
            'regular agent without admin roles' => [true, true, true, 'regular', true, true],
            'another tenant' => [true, false, true, 'regular', true, false],
            'account outside scoped ACL' => [false, true, true, 'regular', true, false],
            'no human membership in requested account' => [true, true, true, 'regular', false, false],
            'inactive agent' => [true, true, false, 'regular', true, false],
            'API identity' => [true, true, true, 'api', true, false],
            'portal identity' => [true, true, true, 'portal', true, false],
            'system identity' => [true, true, true, 'system', true, false],
            'instance administrator' => [false, false, true, 'admin', false, true],
        ];
    }

    #[DataProvider('accessCases')]
    public function testCheckoutUsesWorkspaceMembershipWithoutPromotingTheAgent(
        bool $canRead, bool $canAct, bool $active, string $type, bool $member, bool $allowed,
    ): void {
        $account = $this->createMock(Entity::class);
        $account->method('getId')->willReturn('account-1');
        $account->method('get')->willReturnCallback(fn ($key) => $key === 'tenantId' ? 'tenant-1' : null);
        $tenant = $this->createMock(Entity::class);
        $tenant->method('getId')->willReturn('tenant-1');

        $accounts = $this->createMock(RDBSelectBuilder::class);
        $accounts->method('limit')->willReturnSelf();
        $accounts->method('find')->willReturn(new EntityCollection([$account]));
        $accountRepository = $this->createMock(RDBRepository::class);
        $accountRepository->method('where')->with(['chatwootAccountId' => 5])->willReturn($accounts);

        $memberships = $this->createMock(RDBSelectBuilder::class);
        $memberships->method('where')->with([
            'chatwootAccountId' => 'account-1',
            'chatwootUser.assignedUserId' => 'user-1',
            'isAI' => false,
        ])->willReturnSelf();
        $memberships->method('findOne')->willReturn($member ? $this->createMock(Entity::class) : null);
        $membershipRepository = $this->createMock(RDBRepository::class);
        $membershipRepository->method('join')->with('chatwootUser')->willReturn($memberships);

        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->willReturnMap([
            ['ChatwootAccount', $accountRepository],
            ['ChatwootAccountUserMembership', $membershipRepository],
        ]);
        $em->method('getEntityById')->with('Tenant', 'tenant-1')->willReturn($tenant);

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user-1');
        $user->method('isActive')->willReturn($active);
        $user->method('isAdmin')->willReturn($type === 'admin');
        $user->method('isRegular')->willReturn($type === 'regular');
        $acl = $this->createMock(Acl::class);
        $acl->method('checkEntityRead')->with($account)->willReturn($canRead);
        $resolver = $this->createMock(UserTenantResolver::class);
        $resolver->method('canActForTenant')->with($user, 'tenant-1')->willReturn($canAct);

        $payer = ['name' => 'Agent Payer', 'cpfCnpj' => '52998224725', 'email' => 'agent@example.test'];
        $checkout = $this->createMock(PlanCheckout::class);
        $factory = $this->createMock(InjectableFactory::class);
        if ($allowed) {
            $factory->expects($this->once())->method('create')->with(PlanCheckout::class)->willReturn($checkout);
            $checkout->expects($this->once())->method('start')
                ->with('tenant-1', 'user-1', 3, '0', $payer, null)
                ->willReturn(['state' => 'pending', 'paymentUrl' => 'https://pay.example/plan']);
        } else {
            $factory->expects($this->never())->method('create');
            $checkout->expects($this->never())->method('start');
            $this->expectException(Forbidden::class);
        }

        $controller = new SelfServiceWorkspace($em, $acl, $user, $resolver,
            $this->createMock(WorkspaceTrial::class), $factory, $this->createMock(Config::class));
        $request = $this->createMock(Request::class);
        $request->method('getParsedBody')->willReturn((object) [
            'accountId' => 5, 'seats' => 3, 'credits' => '0', 'payer' => (object) $payer,
        ]);

        $response = $controller->postActionCheckout($request);
        self::assertSame('https://pay.example/plan', $response->paymentUrl);
    }
}
