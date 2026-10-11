<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\NotFound;
use Espo\Entities\User;
use Espo\Modules\FeatureAiUsage\Services\Access;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Accounting\Funding;
use Espo\Modules\FeatureCredits\Accounting\GrantBalances;
use Espo\Modules\FeatureCredits\Accounting\GrantBalancesQuery;
use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use Espo\Modules\FeatureCredits\Controllers\CreditGrants;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use InvalidArgumentException;
use LogicException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

trait GrantBalancesCases
{
    private function grantBalances(PDO $pdo, ?callable $onSnapshot = null): GrantBalances
    {
        $manager = $this->createMock(EntityManager::class);
        $manager->method('getPDO')->willReturn($pdo);
        $clock = $this->createMock(Clock::class);
        $clock->method('now')->willReturnCallback(function () use ($onSnapshot): string {
            if ($onSnapshot !== null) {
                $onSnapshot();
            }
            return $this->now;
        });
        return new GrantBalances($manager, $clock);
    }

    #[DataProvider('dialects')]
    public function testGrantPagesTiesIsolationAndConcurrentAppend(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $grants = $this->grantBalances($pdo);
            $this->assertSame(['tenantId' => 'tenant', 'observedAt' => $this->now, 'list' => [], 'nextCursor' => null],
                $grants->inspect(new GrantBalancesQuery('tenant')));
            $funding = new Funding($this->lock($pdo));
            for ($i = 0; $i < 5; $i++) {
                $funding->grant($this->input('page-' . $i, '1.0001'));
            }
            $funding->grant($this->input(tenant: 'other', credits: '99'));
            $expected = $pdo->query("SELECT id FROM credit_grant WHERE tenant_id = 'tenant'
                ORDER BY created_at DESC, id DESC")->fetchAll(PDO::FETCH_COLUMN);
            $before = $this->auditSnapshot($pdo);
            $first = $grants->inspect(new GrantBalancesQuery('tenant', 2));
            $this->assertSame(array_slice($expected, 0, 2), array_column($first['list'], 'id'));
            $this->assertSame($before, $this->auditSnapshot($pdo));
            $this->now = '2026-10-10 12:00:01';
            (new Funding($this->lock($this->connection($dialect))))->grant($this->input('appended', '2'));
            $second = $grants->inspect(new GrantBalancesQuery('tenant', 2, $first['nextCursor']));
            $third = $grants->inspect(new GrantBalancesQuery('tenant', 2, $second['nextCursor']));
            $this->assertSame($expected, array_column(array_merge($first['list'], $second['list'], $third['list']), 'id'));
            $this->assertNull($third['nextCursor']);
            $this->assertSame($second, $grants->inspect(new GrantBalancesQuery('tenant', 2, $first['nextCursor'])));
            $this->assertCount(6, $grants->inspect(new GrantBalancesQuery('tenant'))['list']);
            $this->assertSame('99.0000', $grants->inspect(new GrantBalancesQuery('other'))['list'][0]['availableCredits']);
            $this->expectException(InvalidArgumentException::class);
            new GrantBalancesQuery('other', 2, $first['nextCursor']);
        });
    }

    #[DataProvider('dialects')]
    public function testGrantExpirationBoundaryHeldFundsAndLateRelease(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input('monthly', '2', expires: '2026-10-11 00:00:00'));
            $funding->grant($this->input('purchase', '3'));
            $funding->grant(new GrantInput('tenant', 'migration', 'opening', '1', '2026-10-01 00:00:00',
                null, (object) ['agreement' => 'private']));
            $usage = $reservations->reserve($this->admission(credits: '0.5'))['usageId'];
            $grants = $this->grantBalances($pdo);
            $read = fn () => array_column($grants->inspect(new GrantBalancesQuery('tenant'))['list'], null, 'sourceType');
            $this->now = '2026-10-10 23:59:59';
            $this->assertSame('1.5000', $read()['subscription']['availableCredits']);
            $this->now = '2026-10-11 00:00:00';
            $before = $this->auditSnapshot($pdo);
            $rows = $read();
            $this->assertSame($before, $this->auditSnapshot($pdo));
            $this->assertSame('2.0000', $rows['subscription']['remainingCredits']);
            $this->assertSame('0.5000', $rows['subscription']['reservedCredits']);
            $this->assertSame('1.5000', $rows['subscription']['pendingExpirationCredits']);
            $this->assertSame('0.0000', $rows['subscription']['availableCredits']);
            $this->assertSame('3.0000', $rows['purchase']['availableCredits']);
            $this->assertNull($rows['purchase']['expiresAt']);
            $this->assertSame('1.0000', $rows['migration']['availableCredits']);
            $funding->expire('tenant');
            $this->assertSame('0.5000', $read()['subscription']['remainingCredits']);
            $this->assertSame('0.0000', $read()['subscription']['pendingExpirationCredits']);
            $reservations->release('tenant', $usage, 'execution');
            $this->assertSame('0.0000', $read()['subscription']['remainingCredits']);
            $this->assertSame('0.0000', $read()['subscription']['reservedCredits']);
            $this->assertCount(3, $read());
        });
    }

    #[DataProvider('dialects')]
    public function testAuthorizedGrantControllerExactMaximumAndAllowlist(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            (new Funding($this->lock($pdo)))->grant($this->input(credits: '9999999999.9999'));
            $user = $this->createMock(User::class);
            $user->method('getLinkMultipleIdList')->willReturn([md5('tenant-admin')]);
            $memberships = $this->createMock(UserTenantResolver::class);
            $memberships->method('resolveTenantIds')->willReturn(['tenant']);
            $manager = $this->createMock(EntityManager::class);
            $manager->method('getEntityById')->with('Tenant', 'tenant')->willReturn($this->createMock(Entity::class));
            $access = new Access($user, $manager, $this->createMock(TenantResolver::class), $memberships);
            $request = $this->createMock(Request::class);
            $request->method('getQueryParams')->willReturn(['tenantId' => 'tenant', 'limit' => '100']);
            $response = $this->createMock(Response::class);
            $response->expects($this->once())->method('setHeader')->with('Cache-Control', 'private, no-store');
            $before = $this->auditSnapshot($pdo);
            $result = (array) (new CreditGrants($access, $this->grantBalances($pdo)))->getActionList($request, $response);
            $this->assertSame('9999999999.9999', $result['list'][0]['availableCredits']);
            $this->assertSame(['id', 'sourceType', 'grantedCredits', 'remainingCredits', 'reservedCredits',
                'pendingExpirationCredits', 'availableCredits', 'expiresAt', 'createdAt'], array_keys($result['list'][0]));
            $this->assertSame($result, json_decode(json_encode($result, JSON_THROW_ON_ERROR), true));
            $this->assertSame($before, $this->auditSnapshot($pdo));
        });
    }

    #[DataProvider('dialects')]
    public function testGrantSnapshotAndDatabaseReadOnlyEnforcement(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            (new Funding($this->lock($pdo)))->grant($this->input());
            $other = $this->connection($dialect);
            $grants = $this->grantBalances($pdo, function () use ($other): void {
                (new Funding($this->lock($other)))->grant($this->input('concurrent', '2'));
            });
            $this->assertCount(1, $grants->inspect(new GrantBalancesQuery('tenant'))['list']);
            $this->assertCount(2, $this->grantBalances($pdo)->inspect(new GrantBalancesQuery('tenant'))['list']);
            try {
                $this->grantBalances($pdo, function () use ($pdo): void {
                    $pdo->exec("UPDATE tenant SET deleted = TRUE WHERE id = 'tenant'");
                })->inspect(new GrantBalancesQuery('tenant'));
                $this->fail('Grant report accepted a write.');
            } catch (PDOException) {
                $this->assertFalse($pdo->inTransaction());
            }
            (new Funding($this->lock($pdo)))->grant($this->input('after-read-only', '1'));
            $this->assertCount(3, $this->grantBalances($pdo)->inspect(new GrantBalancesQuery('tenant'))['list']);
        });
    }

    #[DataProvider('dialects')]
    public function testGrantParentProjectionAndTransactionGuards(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $grants = $this->grantBalances($pdo);
            $pdo->exec("UPDATE tenant SET deleted = TRUE WHERE id = 'other'");
            foreach (['missing', 'other'] as $tenant) {
                try {
                    $grants->inspect(new GrantBalancesQuery($tenant));
                    $this->fail('Missing/deleted tenant accepted.');
                } catch (NotFound) {
                    $this->assertFalse($pdo->inTransaction());
                }
            }
            $pdo->beginTransaction();
            try {
                $grants->inspect(new GrantBalancesQuery('tenant'));
                $this->fail('Nested transaction accepted.');
            } catch (LogicException) {
                $this->assertTrue($pdo->inTransaction());
            }
            $pdo->rollBack();
            (new Funding($this->lock($pdo)))->grant($this->input());
            foreach (['deleted = TRUE', 'reserved_credits = -1', 'reserved_credits = 11',
                'remaining_credits = 11', "source_type = 'invalid'"] as $corruption) {
                $pdo->exec('UPDATE credit_grant SET ' . $corruption);
                try {
                    $grants->inspect(new GrantBalancesQuery('tenant'));
                    $this->fail('Invalid grant projection accepted.');
                } catch (LogicException) {
                    $this->assertFalse($pdo->inTransaction());
                }
                $pdo->exec("UPDATE credit_grant SET deleted = FALSE, reserved_credits = 0,
                    remaining_credits = 10, source_type = 'purchase'");
            }
        });
    }
}
