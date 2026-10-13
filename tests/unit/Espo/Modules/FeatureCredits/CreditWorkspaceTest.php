<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Acl;
use Espo\Core\Api\{Request, Response};
use Espo\Core\Exceptions\{BadRequest, Forbidden};
use Espo\Entities\User;
use Espo\Modules\FeatureAiUsage\Services\Access;
use Espo\Modules\FeatureCredits\Accounting\{BalanceStatus, Clock};
use Espo\Modules\FeatureCredits\Controllers\CreditWorkspace;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\{BaseEntity, EntityCollection, EntityManager};
use Espo\ORM\Repository\{RDBRepository, RDBSelectBuilder};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CreditWorkspaceTest extends TestCase
{
    public static function denials(): iterable
    {
        foreach (['portal', 'api', 'system', 'scope', 'mapping', 'duplicate', 'record', 'membership'] as $kind) {
            yield [$kind, false];
            yield [$kind, true];
        }
    }

    #[DataProvider('denials')]
    public function testDeniedWorkspaceNeverReadsFinancialData(string $kind, bool $conversation): void
    {
        $manager = $this->createMock(EntityManager::class);
        $manager->expects($this->never())->method('getPDO');
        $account = new BaseEntity('ChatwootAccount', ['attributes' => ['tenantId' => ['type' => 'varchar']]]);
        $account->set('tenantId', 'tenant');
        $builder = $this->createMock(RDBSelectBuilder::class);
        $builder->method('limit')->willReturnSelf();
        $builder->method('find')->willReturn(new EntityCollection(match ($kind) { 'mapping' => [], 'duplicate' => [$account, $account], default => [$account] }));
        $repository = $this->createMock(RDBRepository::class);
        $repository->method('where')->willReturn($builder);
        $manager->method('getRDBRepository')->willReturn($repository);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user');
        $user->method('isPortal')->willReturn($kind === 'portal');
        $user->method('isApi')->willReturn($kind === 'api');
        $user->method('isSystem')->willReturn($kind === 'system');
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturn($kind !== 'scope');
        $acl->method('check')->willReturn($kind !== 'record');
        $memberships = $this->createMock(UserTenantResolver::class);
        $memberships->method('canActForTenant')->willReturn($kind !== 'membership');
        $request = $this->createMock(Request::class);
        $request->method('getQueryParams')->willReturn($conversation ? ['accountId' => '6', 'conversationId' => '10'] : ['accountId' => '6']);
        $response = $this->createMock(Response::class);
        $response->expects($this->once())->method('setHeader')->with('Cache-Control', 'private, no-store');
        $clock = new Clock();
        $this->expectException(Forbidden::class);
        $controller = new CreditWorkspace($user, $acl, $memberships, $manager, $this->createMock(Access::class), new BalanceStatus($manager, $clock), $clock);
        $conversation ? $controller->getActionConversation($request, $response) : $controller->getActionRead($request, $response);
    }

    public static function conversationDenials(): iterable
    {
        foreach (['scope', 'missing', 'duplicate', 'foreign', 'record'] as $kind) yield [$kind];
    }

    #[DataProvider('conversationDenials')]
    public function testConversationSourceDeniedBeforeAccounting(string $kind): void
    {
        $manager = $this->createMock(EntityManager::class);
        $manager->expects($this->never())->method('getPDO');
        $account = new BaseEntity('ChatwootAccount', ['attributes' => ['tenantId' => ['type' => 'varchar']]]);
        $account->set('id', 'account');
        $account->set('tenantId', 'tenant');
        $conversation = new BaseEntity('ChatwootConversation', ['attributes' => ['chatwootAccountId' => ['type' => 'varchar']]]);
        $conversation->set('id', 'conversation');
        $conversation->set('chatwootAccountId', $kind === 'foreign' ? 'other' : 'account');
        $manager->method('getRDBRepository')->willReturnCallback(function (string $type) use ($account, $conversation, $kind) {
            $builder = $this->createMock(RDBSelectBuilder::class);
            $builder->method('limit')->willReturnSelf();
            $builder->method('find')->willReturn(new EntityCollection($type === 'ChatwootAccount' ? [$account] :
                match ($kind) { 'missing' => [], 'duplicate' => [$conversation, $conversation], default => [$conversation] }));
            $repo = $this->createMock(RDBRepository::class);
            $repo->expects($this->once())->method('where')->with($type === 'ChatwootAccount' ?
                ['chatwootAccountId' => 6] : ['chatwootAccountId' => 'account', 'chatwootConversationId' => 10])->willReturn($builder);
            return $repo;
        });
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('human');
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturnCallback(fn ($scope) => $kind !== 'scope' || $scope !== 'ChatwootConversation');
        $acl->method('check')->willReturnCallback(fn ($entity) => $kind !== 'record' || $entity === $account);
        $memberships = $this->createMock(UserTenantResolver::class);
        $memberships->method('canActForTenant')->willReturn(true);
        $request = $this->createMock(Request::class);
        $request->method('getQueryParams')->willReturn(['accountId' => '6', 'conversationId' => '10']);
        $clock = new Clock();
        $this->expectException(Forbidden::class);
        (new CreditWorkspace($user, $acl, $memberships, $manager, $this->createMock(Access::class), new BalanceStatus($manager, $clock), $clock))
            ->getActionConversation($request, $this->createMock(Response::class));
    }
}
