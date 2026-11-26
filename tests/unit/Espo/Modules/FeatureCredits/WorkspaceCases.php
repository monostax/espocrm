<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Acl;
use Espo\Core\Api\{Request, Response};
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\FeatureAiUsage\Services\Access;
use Espo\Modules\FeatureCredits\Accounting\{Clock, Cutovers, CutoverInput, Funding, GrantInput, UsageMeter};
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
            $financial->method('tenantIds')->willReturn(['tenant']);
            $attendant = $this->createMock(Access::class);
            $attendant->method('tenantIds')->willReturn(['other']);
            $clock = $this->createMock(Clock::class);
            $clock->method('now')->willReturn($this->now);
            $controller = new CreditWorkspace($user, $acl, $memberships, $manager, $financial, $this->balanceStatus($pdo), $clock);
            $request = $this->createMock(Request::class);
            $request->method('getQueryParams')->willReturn(['accountId' => '6', 'conversationId' => '10']);
            $response = $this->createMock(Response::class);
            $response->expects($this->exactly(4))->method('setHeader')->with('Cache-Control', 'private, no-store');
            $before = $this->auditSnapshot($pdo);
            // Attendants never see what a conversation's AI used (decided 2026-11-26).
            $this->assertEquals((object) ['accountId' => '6', 'conversationId' => '10', 'canManage' => false],
                (new CreditWorkspace($user, $acl, $memberships, $manager, $attendant, $this->balanceStatus($pdo), $clock))
                    ->getActionConversation($request, $response));
            $result = $controller->getActionConversation($request, $response);
            $this->assertSame(['accountId', 'conversationId', 'canManage', 'tenantId', 'observedAt', 'since', 'share', 'interactions', 'list'],
                array_keys((array) $result));
            $this->assertSame(['6', '10', true, 'tenant'], [$result->accountId, $result->conversationId, $result->canManage, $result->tenantId]);
            $this->assertSame([['id' => $usage, 'state' => 'pending', 'kind' => 'agent', 'admittedAt' => $this->now]], $result->list);
            // The held credits count, as a share of the whole period (the unrelated hold included, the 10 granted).
            $q = $pdo->prepare('SELECT reserved_credits FROM credit_reservation WHERE usage_id = ?');
            $q->execute([$usage]);
            $held = (string) $q->fetchColumn();
            $this->assertSame([1, \Espo\Modules\FeatureCredits\Accounting\ConversationUsage::share($held, '10.0000')],
                [$result->interactions, $result->share]);
            $this->assertGreaterThan(0, (float) $result->share);
            $this->assertNotNull($result->since);
            $this->assertSame($before, $this->auditSnapshot($pdo));
            $reservations->release('tenant', $usage, 'execution');
            $before = $this->auditSnapshot($pdo);
            $result = $controller->getActionConversation($request, $response);
            $this->assertSame('released', $result->list[0]['state']);
            $this->assertSame([0, '0.000000'], [$result->interactions, $result->share]);
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
                $this->assertSame(['accountId', 'tenantId', 'billingRegime', 'canManage', 'availableCredits', 'reservedCredits', 'usage', 'observedAt'], array_keys($result));
                $this->assertNull($result['usage']); // Legacy billing has no meter.
            }
            $this->assertSame($before, $this->auditSnapshot($pdo));
            (new Cutovers($manager, $clock))->schedule(new CutoverInput('tenant', $this->now, (object) ['approval' => 'isolated test']));
            $result = $controller->getActionRead($request, $response);
            $this->assertSame('unified-prepaid-v1', $result->billingRegime);
            $this->assertSame(['usedRatio' => '0.0000', 'periodStartedAt' => $this->now, 'renewsAt' => null], $result->usage);
        });
    }

    #[DataProvider('dialects')]
    public function testUsageMeterSharesThisPeriodsCredits(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $manager = $this->createMock(EntityManager::class);
            $manager->method('getPDO')->willReturn($pdo);
            $clock = $this->createMock(Clock::class);
            $clock->method('now')->willReturnCallback(fn () => $this->now);
            $meter = new UsageMeter($manager, $clock);
            $read = fn (string $available, string $held = '0.0000') =>
                $meter->inspect('tenant', ['availableCredits' => $available, 'reservedCredits' => $held]);
            $this->assertNull($read('0.0000'), 'No grants, no meter.');

            $funding = new Funding($this->lock($pdo));
            $trialStart = $this->now;
            $funding->grant(new GrantInput('tenant', 'migration', 'trial', '100', $trialStart, '2026-10-17 00:00:00',
                (object) ['verifiedSource' => 'trial']));
            $funding->grant($this->input('other-tenant', '50', 'other'));
            $reservations = $this->reservations($pdo);
            $settle = function (string $key, string $credits, string $admittedAt) use ($pdo, $reservations): void {
                $usage = $reservations->reserve($this->admission($key, '1'))['usageId'];
                $q = $pdo->prepare("UPDATE credit_usage SET state = 'settled', settled_credits = ?, admitted_at = ? WHERE id = ?");
                $q->execute([$credits, $admittedAt, $usage]);
            };
            $settle('this-period', '4.0000', $trialStart);
            $settle('before-period', '9.0000', '2026-10-09 00:00:00');
            // Settled since the trial (4) plus held now (6) of 100.
            $this->assertSame(['usedRatio' => '0.1000', 'periodStartedAt' => $trialStart, 'renewsAt' => null],
                $read('90.0000', '6.0000'));
            $this->assertSame(['startedAt' => $trialStart, 'renewsAt' => null, 'usedCredits' => '10.0000',
                'periodCredits' => '100.0000'], $meter->period('tenant', ['availableCredits' => '90.0000', 'reservedCredits' => '6.0000']));

            // A purchase adds room without starting a new period: 4 of 194.
            $this->now = '2026-10-11 00:00:00';
            $funding->grant($this->input('extra', '100'));
            $this->assertSame(['usedRatio' => '0.0206', 'periodStartedAt' => $trialStart, 'renewsAt' => null], $read('190.0000'));
            $this->assertSame('1.0000', $read('0.0000')['usedRatio'], 'Nothing left is 100%.');
            $this->assertSame('1.0000', $read('-2.0000', '1.0000')['usedRatio']);

            // Monthly credits start a new period that renews at the period's end.
            $pdo->exec("INSERT INTO credit_billing_obligation (id, tenant_id, request_key, input_hash, acceptance_hash, provider,
                integration_id, catalog_id, terms_id, terms_snapshot, operation, billing_country, assignment_revision, state,
                created_at, created_by_id) VALUES ('obligation', 'tenant', 'request', 'hash', 'hash', 'asaas', 'integration',
                'catalog', 'terms', '{}', 'subscription', 'BR', 1, 'active', '2026-10-12 00:00:00', 'user')");
            $pdo->exec("INSERT INTO credit_billing_period (id, tenant_id, obligation_id, period_key, starts_at, ends_at, terms_id,
                binding_hash, created_at) VALUES ('period-1', 'tenant', 'obligation', '2026-10-12', '2026-10-12 00:00:00',
                '2026-11-12 00:00:00', 'terms', 'hash', '2026-10-12 00:00:00')");
            $this->now = '2026-10-12 00:00:00';
            $funding->grant($this->input('billing:period-1', '250', expires: '2026-12-12 00:00:00'));
            $this->now = '2026-10-13 00:00:00';
            $settle('renewed', '25.0000', '2026-10-12 06:00:00');
            $this->assertSame(['usedRatio' => '0.1000', 'periodStartedAt' => '2026-10-12 00:00:00',
                'renewsAt' => '2026-11-12 00:00:00'], $read('225.0000'));

            // A cancelled subscription, or a period already over, does not renew.
            $pdo->exec("UPDATE credit_billing_obligation SET state = 'cancelled'");
            $this->assertNull($read('225.0000')['renewsAt']);
            $pdo->exec("UPDATE credit_billing_obligation SET state = 'active'");
            $this->now = '2026-11-12 00:00:00';
            $this->assertNull($read('225.0000')['renewsAt']);
            $this->assertFalse($pdo->inTransaction());
        });
    }
}
