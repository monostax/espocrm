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
use Espo\Modules\FeatureCredits\Accounting\OutcomeInput;
use Espo\Modules\FeatureCredits\Accounting\Outcomes;
use Espo\Modules\FeatureCredits\Accounting\Requests;
use Espo\Modules\FeatureCredits\Accounting\Settlements;
use Espo\Modules\FeatureCredits\Accounting\TransactionHistory;
use Espo\Modules\FeatureCredits\Accounting\TransactionHistoryQuery;
use Espo\Modules\FeatureCredits\Controllers\CreditHistory;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use InvalidArgumentException;
use LogicException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

trait TransactionHistoryCases
{
    private function history(PDO $pdo, ?callable $onSnapshot = null): TransactionHistory
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
        return new TransactionHistory($manager, $clock);
    }

    #[DataProvider('dialects')]
    public function testHistoryPaginationTiesTenantIsolationAndConcurrentAppend(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $history = $this->history($pdo);
            $this->assertSame(['tenantId' => 'tenant', 'observedAt' => $this->now, 'list' => [], 'nextCursor' => null],
                $history->inspect(new TransactionHistoryQuery('tenant')));
            $funding = new Funding($this->lock($pdo));
            for ($i = 0; $i < 5; $i++) {
                $funding->grant($this->input('page-' . $i, '1.0001'));
            }
            $funding->grant($this->input(tenant: 'other', credits: '99'));
            $expected = $pdo->query("SELECT id FROM credit_transaction WHERE tenant_id = 'tenant'
                ORDER BY posted_at DESC, id DESC")->fetchAll(PDO::FETCH_COLUMN);
            $before = $this->auditSnapshot($pdo);
            $first = $history->inspect(new TransactionHistoryQuery('tenant', 2));
            $this->assertSame(array_slice($expected, 0, 2), array_column($first['list'], 'id'));
            $this->assertSame($before, $this->auditSnapshot($pdo));
            $this->now = '2026-10-10 12:00:01';
            (new Funding($this->lock($this->connection($dialect))))->grant($this->input('appended', '2'));
            $second = $history->inspect(new TransactionHistoryQuery('tenant', 2, $first['nextCursor']));
            $third = $history->inspect(new TransactionHistoryQuery('tenant', 2, $second['nextCursor']));
            $this->assertSame($expected, array_column(array_merge($first['list'], $second['list'], $third['list']), 'id'));
            $this->assertNull($third['nextCursor']);
            $this->assertCount(6, $history->inspect(new TransactionHistoryQuery('tenant'))['list']);
            $this->assertSame($second, $history->inspect(new TransactionHistoryQuery('tenant', 2, $first['nextCursor'])));
            $this->assertSame('99.0000', $history->inspect(new TransactionHistoryQuery('other'))['list'][0]['credits']);
            $this->expectException(InvalidArgumentException::class);
            new TransactionHistoryQuery('other', 2, $first['nextCursor']);
        });
    }

    #[DataProvider('dialects')]
    public function testHistoryAuthorizedControllerExactAmountsAndExpiration(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input(credits: '9999999999.9999', expires: '2026-10-11 00:00:00'));
            $this->now = '2026-10-11 00:00:00';
            $funding->expire('tenant');
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
            $result = (array) (new CreditHistory($access, $this->history($pdo)))->getActionList($request, $response);
            $this->assertSame(['expiration', 'grant'], array_column($result['list'], 'type'));
            $this->assertSame(['-9999999999.9999', '9999999999.9999'], array_column($result['list'], 'credits'));
            $this->assertSame(['id', 'type', 'credits', 'occurredAt', 'postedAt'], array_keys($result['list'][0]));
            $this->assertSame($result, json_decode(json_encode($result, JSON_THROW_ON_ERROR), true));
            $this->assertSame($before, $this->auditSnapshot($pdo));
        });
    }

    #[DataProvider('dialects')]
    public function testHistoryShowsPostedDebitsOnlyAndSurvivesSourceDeletion(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input(credits: '1'));
            $usage = $reservations->reserve($this->admission(credits: '0.5'))['usageId'];
            $id = (new Requests($this->lock($pdo), $funding))->authorize($this->request($usage))['requestId'];
            (new Outcomes($this->lock($pdo)))->record(new OutcomeInput('tenant', $usage, 'execution', $id,
                'success', 0, 0, 1000, (object) ['privateProviderEvidence' => 'not-for-reporting']));
            $history = $this->history($pdo);
            $this->assertCount(1, $history->inspect(new TransactionHistoryQuery('tenant'))['list']);
            $this->now = '2026-10-10 12:00:01';
            $settlements = new Settlements($this->lock($pdo), $funding);
            $settlements->settle('tenant', $usage, 'execution');
            $result = $history->inspect(new TransactionHistoryQuery('tenant'));
            $this->assertSame(['debit', 'grant'], array_column($result['list'], 'type'));
            $this->assertSame(['-0.1875', '1.0000'], array_column($result['list'], 'credits'));
            $settlements->settle('tenant', $usage, 'execution');
            $this->assertSame($result, $history->inspect(new TransactionHistoryQuery('tenant')));
            // Financial history depends on the immutable posting, not joined mutable sources.
            $pdo->exec('UPDATE credit_usage SET deleted = TRUE');
            $pdo->exec('UPDATE credit_grant SET deleted = TRUE');
            $this->assertSame($result, $history->inspect(new TransactionHistoryQuery('tenant')));
        });
    }

    #[DataProvider('dialects')]
    public function testHistorySnapshotAndDatabaseReadOnlyEnforcement(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            (new Funding($this->lock($pdo)))->grant($this->input());
            $other = $this->connection($dialect);
            $history = $this->history($pdo, function () use ($other): void {
                (new Funding($this->lock($other)))->grant($this->input('concurrent', '2'));
            });
            $this->assertCount(1, $history->inspect(new TransactionHistoryQuery('tenant'))['list']);
            $this->assertCount(2, $this->history($pdo)->inspect(new TransactionHistoryQuery('tenant'))['list']);
            try {
                $this->history($pdo, function () use ($pdo): void {
                    $pdo->exec("UPDATE tenant SET deleted = TRUE WHERE id = 'tenant'");
                })->inspect(new TransactionHistoryQuery('tenant'));
                $this->fail('History accepted a write.');
            } catch (PDOException) {
                $this->assertFalse($pdo->inTransaction());
            }
            (new Funding($this->lock($pdo)))->grant($this->input('after-read-only', '1'));
            $this->assertCount(3, $this->history($pdo)->inspect(new TransactionHistoryQuery('tenant'))['list']);
        });
    }

    #[DataProvider('dialects')]
    public function testHistoryParentCorruptionAndTransactionGuards(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $history = $this->history($pdo);
            $pdo->exec("UPDATE tenant SET deleted = TRUE WHERE id = 'other'");
            foreach (['missing', 'other'] as $tenant) {
                try {
                    $history->inspect(new TransactionHistoryQuery($tenant));
                    $this->fail('Missing/deleted tenant accepted.');
                } catch (NotFound) {
                    $this->assertFalse($pdo->inTransaction());
                }
            }
            $pdo->beginTransaction();
            try {
                $history->inspect(new TransactionHistoryQuery('tenant'));
                $this->fail('Nested transaction accepted.');
            } catch (LogicException) {
                $this->assertTrue($pdo->inTransaction());
            }
            $pdo->rollBack();
            (new Funding($this->lock($pdo)))->grant($this->input());
            $pdo->exec('UPDATE credit_transaction SET deleted = TRUE');
            try {
                $history->inspect(new TransactionHistoryQuery('tenant'));
                $this->fail('Deleted ledger entry silently hidden.');
            } catch (LogicException) {
                $this->assertFalse($pdo->inTransaction());
            }
        });
    }
}
