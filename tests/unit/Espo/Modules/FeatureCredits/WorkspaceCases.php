<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Acl;
use Espo\Core\Api\{Request, Response};
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\FeatureAiUsage\Services\Access;
use Espo\Modules\FeatureCredits\Accounting\{Clock, Cutovers, CutoverInput, Funding};
use Espo\Modules\FeatureCredits\Controllers\CreditWorkspace;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\{BaseEntity, EntityCollection, EntityManager};
use Espo\ORM\Repository\{RDBRepository, RDBSelectBuilder};
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

trait WorkspaceCases
{
    #[DataProvider('dialects')]
    public function testOperationalConversationOriginalReceipts(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            (new Funding($this->lock($pdo)))->grant($this->input());
            $reservations = $this->reservations($pdo);
            $usage = $reservations->reserve($this->admission())['usageId'];
            $reservations->reserve($this->admission('unrelated', '1'));
            $q = $pdo->prepare("UPDATE credit_usage SET source_type='ChatwootConversation', source_id='conversation' WHERE id=?");
            $q->execute([$usage]);
            $account = new BaseEntity('ChatwootAccount', ['attributes' => ['tenantId' => ['type' => 'varchar']]]);
            $account->set('id', 'account');
            $account->set('tenantId', 'tenant');
            $conversation = new BaseEntity('ChatwootConversation', ['attributes' => ['chatwootAccountId' => ['type' => 'varchar']]]);
            $conversation->set('id', 'conversation');
            $conversation->set('chatwootAccountId', 'account');
            $manager = $this->createMock(EntityManager::class);
            $manager->method('getPDO')->willReturn($pdo);
            $manager->method('getRDBRepository')->willReturnCallback(function ($type) use ($account, $conversation) {
                $builder = $this->createMock(RDBSelectBuilder::class);
                $builder->method('limit')->with(0, 2)->willReturnSelf();
                $builder->method('find')->willReturn(new EntityCollection([$type === 'ChatwootAccount' ? $account : $conversation]));
                $repo = $this->createMock(RDBRepository::class);
                $repo->method('where')->with($type === 'ChatwootAccount' ? ['chatwootAccountId' => 6] :
                    ['chatwootAccountId' => 'account', 'chatwootConversationId' => 10])->willReturn($builder);
                return $repo;
            });
            $user = $this->createMock(User::class);
            $user->method('getId')->willReturn('member');
            $acl = $this->createMock(Acl::class);
            $acl->method('checkScope')->willReturn(true);
            $acl->method('check')->willReturn(true);
            $memberships = $this->createMock(UserTenantResolver::class);
            $memberships->method('canActForTenant')->with($user, 'tenant')->willReturn(true);
            $financial = $this->createMock(Access::class);
            $financial->expects($this->never())->method('tenantIds');
            $clock = $this->createMock(Clock::class);
            $clock->method('now')->willReturn($this->now);
            $controller = new CreditWorkspace($user, $acl, $memberships, $manager, $financial, $this->balanceStatus($pdo), $clock);
            $request = $this->createMock(Request::class);
            $request->method('getQueryParams')->willReturn(['accountId' => '6', 'conversationId' => '10']);
            $response = $this->createMock(Response::class);
            $response->expects($this->exactly(3))->method('setHeader')->with('Cache-Control', 'private, no-store');
            $before = $this->auditSnapshot($pdo);
            $result = $controller->getActionConversation($request, $response);
            $this->assertSame(['accountId', 'conversationId', 'tenantId', 'observedAt', 'list'], array_keys((array) $result));
            $this->assertSame(['6', '10', 'tenant'], [$result->accountId, $result->conversationId, $result->tenantId]);
            $this->assertSame([['id' => $usage, 'state' => 'pending', 'settledCredits' => null, 'admittedAt' => $this->now]], $result->list);
            $this->assertSame($before, $this->auditSnapshot($pdo));
            $reservations->release('tenant', $usage, 'execution');
            $before = $this->auditSnapshot($pdo);
            $result = $controller->getActionConversation($request, $response);
            $this->assertSame('released', $result->list[0]['state']);
            $this->assertSame('0.0000', $result->list[0]['settledCredits']);
            $this->assertEquals($result, $controller->getActionConversation($request, $response));
            $this->assertSame($before, $this->auditSnapshot($pdo));
        });
    }

    #[DataProvider('dialects')]
    public function testOperationalWorkspaceUsesTrustedMappingAndMinimalExactProjection(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            (new Funding($this->lock($pdo)))->grant($this->input(credits: '9.1234'));
            $account = new BaseEntity('ChatwootAccount', ['attributes' => ['tenantId' => ['type' => 'varchar']]]);
            $account->set('tenantId', 'tenant');
            $builder = $this->createMock(RDBSelectBuilder::class);
            $builder->method('limit')->with(0, 2)->willReturnSelf();
            $builder->method('find')->willReturn(new EntityCollection([$account]));
            $repository = $this->createMock(RDBRepository::class);
            $repository->method('where')->willReturnCallback(function ($where) use ($builder) {
                $this->assertContains($where['chatwootAccountId'], [6, 7]);
                return $builder;
            });
            $manager = $this->createMock(EntityManager::class);
            $manager->method('getRDBRepository')->with('ChatwootAccount')->willReturn($repository);
            $manager->method('getPDO')->willReturn($pdo);
            $user = $this->createMock(User::class);
            $user->method('getId')->willReturn('member');
            $acl = $this->createMock(Acl::class);
            $acl->method('checkScope')->with('ChatwootAccount', 'read')->willReturn(true);
            $acl->method('check')->with($account, 'read')->willReturn(true);
            $memberships = $this->createMock(UserTenantResolver::class);
            $allowed = true;
            $memberships->method('canActForTenant')->with($user, 'tenant')->willReturnCallback(static fn () => $allowed);
            $financial = $this->createMock(Access::class);
            $financial->method('tenantIds')->willReturn([]);
            $clock = $this->createMock(Clock::class);
            $clock->method('now')->willReturn($this->now);
            $controller = new CreditWorkspace($user, $acl, $memberships, $manager, $financial, $this->balanceStatus($pdo), $clock);
            $response = $this->createMock(Response::class);
            $response->expects($this->exactly(3))->method('setHeader')->with('Cache-Control', 'private, no-store');
            $before = $this->auditSnapshot($pdo);
            foreach (['6', '7'] as $id) {
                $request = $this->createMock(Request::class);
                $request->method('getQueryParams')->willReturn(['accountId' => $id]);
                $result = (array) $controller->getActionRead($request, $response);
                $this->assertSame($id, $result['accountId']);
                $this->assertSame('tenant', $result['tenantId']);
                $this->assertSame('9.1234', $result['availableCredits']);
                $this->assertSame('0.0000', $result['reservedCredits']);
                $this->assertSame('legacy-engagement-v1', $result['billingRegime']);
                $this->assertFalse($result['canManage']);
                $this->assertSame(['accountId', 'tenantId', 'billingRegime', 'canManage', 'availableCredits', 'reservedCredits', 'observedAt'], array_keys($result));
            }
            $this->assertSame($before, $this->auditSnapshot($pdo));
            (new Cutovers($manager, $clock))->schedule(new CutoverInput('tenant', $this->now, (object) ['approval' => 'isolated test']));
            $this->assertSame('unified-prepaid-v1', $controller->getActionRead($request, $response)->billingRegime);
        });
    }
}
