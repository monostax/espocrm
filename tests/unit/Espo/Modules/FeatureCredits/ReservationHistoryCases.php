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
use Espo\Modules\FeatureCredits\Accounting\ReservationHistory;
use Espo\Modules\FeatureCredits\Accounting\ReservationHistoryQuery;
use Espo\Modules\FeatureCredits\Accounting\ReservationInput;
use Espo\Modules\FeatureCredits\Accounting\Reservations;
use Espo\Modules\FeatureCredits\Accounting\Settlements;
use Espo\Modules\FeatureCredits\Controllers\CreditReservations;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use InvalidArgumentException;
use LogicException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

trait ReservationHistoryCases
{
    private function reservationHistory(PDO $pdo, ?callable $onSnapshot = null): ReservationHistory
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
        return new ReservationHistory($manager, $clock);
    }

    #[DataProvider('dialects')]
    public function testReservationHistoryPagesTiesIsolationAndConcurrentChange(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $history = $this->reservationHistory($pdo);
            $this->assertSame(['tenantId' => 'tenant', 'observedAt' => $this->now, 'list' => [], 'nextCursor' => null],
                $history->inspect(new ReservationHistoryQuery('tenant')));
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input());
            for ($i = 0; $i < 5; $i++) {
                $reservations->reserve($this->admission('page-' . $i, '1'));
            }
            $funding->grant($this->input(tenant: 'other'));
            $pdo->exec("INSERT INTO tenant_credit_billing_rate
                (id, tenant_id, version, effective_from, currency, credit_unit_price, monthly_credits, created_at)
                VALUES ('other-rate', 'other', 'v1', '2026-10-01 00:00:00', 'USD', '1.00000000', '0.0000', '2026-10-01 00:00:00')");
            $reservations->reserve(new ReservationInput('other', 'apollo', 'foreign', 'execution', 'other-rate', '3'));
            $expected = $pdo->query("SELECT id FROM credit_reservation WHERE tenant_id = 'tenant'
                ORDER BY created_at DESC, id DESC")->fetchAll(PDO::FETCH_COLUMN);
            $before = $this->auditSnapshot($pdo);
            $first = $history->inspect(new ReservationHistoryQuery('tenant', 2));
            $this->assertSame(array_slice($expected, 0, 2), array_column($first['list'], 'id'));
            $this->assertSame($before, $this->auditSnapshot($pdo));
            $this->now = '2026-10-10 12:00:01';
            $other = $this->connection($dialect);
            $writer = new Reservations($this->lock($other), new Funding($this->lock($other)));
            $writer->reserve($this->admission('appended', '1'));
            // Closing a cursor row must not make the following page unreachable.
            $writer->release('tenant', $first['list'][1]['usageId'], 'execution');
            $second = $history->inspect(new ReservationHistoryQuery('tenant', 2, $first['nextCursor']));
            $third = $history->inspect(new ReservationHistoryQuery('tenant', 2, $second['nextCursor']));
            $this->assertSame($expected, array_column(array_merge($first['list'], $second['list'], $third['list']), 'id'));
            $this->assertNull($third['nextCursor']);
            $this->assertSame($second, $history->inspect(new ReservationHistoryQuery('tenant', 2, $first['nextCursor'])));
            $this->assertCount(6, $history->inspect(new ReservationHistoryQuery('tenant'))['list']);
            $this->assertSame('3.0000', $history->inspect(new ReservationHistoryQuery('other'))['list'][0]['reservedCredits']);
            $this->expectException(InvalidArgumentException::class);
            new ReservationHistoryQuery('other', 2, $first['nextCursor']);
        });
    }

    #[DataProvider('dialects')]
    public function testReservationHistoryUnknownAccrualSettlementAndLateRelease(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input(expires: '2026-10-11 00:00:00'));
            $usage = $reservations->reserve($this->admission())['usageId'];
            $released = $reservations->reserve($this->admission('release', '1', 'apollo'))['usageId'];
            $requests = new Requests($this->lock($pdo), $funding);
            $id = $requests->authorize($this->request($usage))['requestId'];
            $outcomes = new Outcomes($this->lock($pdo));
            $history = $this->reservationHistory($pdo);
            $read = fn () => array_column($history->inspect(new ReservationHistoryQuery('tenant'))['list'], null, 'usageId');
            $this->assertSame('held', $read()[$usage]['state']);
            $this->assertNull($read()[$usage]['settledCredits']);
            $outcomes->record(new OutcomeInput('tenant', $usage, 'execution', $id, 'cancelled', null, null, null,
                (object) ['source' => 'provider']));
            $before = $this->auditSnapshot($pdo);
            $this->assertSame('0', $read()[$usage]['accruedCreditsExact']);
            $this->assertNull($read()[$usage]['settledCredits']);
            $this->assertSame($before, $this->auditSnapshot($pdo));
            $outcomes->record(new OutcomeInput('tenant', $usage, 'execution', $id, 'cancelled', 0, 0, 1,
                (object) ['source' => 'provider']));
            $this->assertSame('0.0001875', $read()[$usage]['accruedCreditsExact']);
            $this->assertSame('2.0000', $read()[$usage]['reservedCredits']);
            $this->now = '2026-10-11 00:00:00';
            $before = $this->auditSnapshot($pdo);
            $this->assertSame('2.0000', $read()[$usage]['reservedCredits']);
            $this->assertSame($before, $this->auditSnapshot($pdo));
            $settlements = new Settlements($this->lock($pdo), $funding);
            $settlements->settle('tenant', $usage, 'execution');
            $settlements->settle('tenant', $usage, 'execution');
            $reservations->release('tenant', $released, 'execution');
            $rows = $read();
            $this->assertCount(2, $rows);
            $this->assertSame('settled', $rows[$usage]['state']);
            $this->assertSame('0.0000', $rows[$usage]['reservedCredits']);
            $this->assertSame('0.0001875', $rows[$usage]['accruedCreditsExact']);
            $this->assertSame('0.0002', $rows[$usage]['settledCredits']);
            $this->assertSame($this->now, $rows[$usage]['settledAt']);
            $this->assertSame('released', $rows[$released]['state']);
            $this->assertSame('0.0000', $rows[$released]['settledCredits']);
        });
    }

    #[DataProvider('dialects')]
    public function testAuthorizedReservationHistoryExactMaximumAndAllowlist(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            (new Funding($this->lock($pdo)))->grant($this->input(credits: '9999999999.9999'));
            $this->reservations($pdo)->reserve($this->admission(credits: '9999999999.9999'));
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
            $result = (array) (new CreditReservations($access, $this->reservationHistory($pdo)))->getActionList($request, $response);
            $this->assertSame('9999999999.9999', $result['list'][0]['reservedCredits']);
            $this->assertSame(['id', 'usageId', 'operationType', 'state', 'reservedCredits', 'accruedCreditsExact',
                'settledCredits', 'createdAt', 'modifiedAt', 'settledAt'], array_keys($result['list'][0]));
            $this->assertSame($result, json_decode(json_encode($result, JSON_THROW_ON_ERROR), true));
            $this->assertSame($before, $this->auditSnapshot($pdo));
        });
    }

    #[DataProvider('dialects')]
    public function testReservationHistorySnapshotAndReadOnlyEnforcement(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            (new Funding($this->lock($pdo)))->grant($this->input());
            $reservations = $this->reservations($pdo);
            $usage = $reservations->reserve($this->admission())['usageId'];
            $other = $this->connection($dialect);
            $history = $this->reservationHistory($pdo, function () use ($other, $usage): void {
                (new Reservations($this->lock($other), new Funding($this->lock($other))))->release('tenant', $usage, 'execution');
            });
            $this->assertSame('held', $history->inspect(new ReservationHistoryQuery('tenant'))['list'][0]['state']);
            $this->assertSame('released', $this->reservationHistory($pdo)->inspect(new ReservationHistoryQuery('tenant'))['list'][0]['state']);
            try {
                $this->reservationHistory($pdo, function () use ($pdo): void {
                    $pdo->exec("UPDATE tenant SET deleted = TRUE WHERE id = 'tenant'");
                })->inspect(new ReservationHistoryQuery('tenant'));
                $this->fail('Reservation report accepted a write.');
            } catch (PDOException) {
                $this->assertFalse($pdo->inTransaction());
            }
            $reservations->reserve($this->admission('after-read-only'));
            $this->assertCount(2, $this->reservationHistory($pdo)->inspect(new ReservationHistoryQuery('tenant'))['list']);
        });
    }

    #[DataProvider('dialects')]
    public function testReservationHistoryRejectsInvalidClosedAndLookaheadRows(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            (new Funding($this->lock($pdo)))->grant($this->input());
            $reservations = $this->reservations($pdo);
            $usage = $reservations->reserve($this->admission())['usageId'];
            $reservations->release('tenant', $usage, 'execution');
            $history = $this->reservationHistory($pdo);
            foreach ([
                ['credit_reservation', 'reserved_credits = 1'],
                ['credit_reservation', "accrued_credits_exact = '0.00001'"],
                ['credit_reservation', 'settled_at = NULL'],
                ['credit_usage', 'settled_credits = NULL'],
                ['credit_usage', 'settled_credits = -1'],
                ['credit_usage', 'settled_credits = 1'],
                ['credit_usage', "settled_at = '2026-10-10 12:00:01'"],
            ] as [$table, $corruption]) {
                $pdo->exec('UPDATE ' . $table . ' SET ' . $corruption);
                try {
                    $history->inspect(new ReservationHistoryQuery('tenant'));
                    $this->fail('Invalid closed projection accepted: ' . $corruption);
                } catch (LogicException) {
                    $this->assertFalse($pdo->inTransaction());
                }
                $pdo->exec("UPDATE credit_reservation SET reserved_credits = 0, accrued_credits_exact = '0',
                    settled_at = '2026-10-10 12:00:00'");
                $pdo->exec("UPDATE credit_usage SET settled_credits = 0, settled_at = '2026-10-10 12:00:00'");
            }
            $this->assertSame('released', $history->inspect(new ReservationHistoryQuery('tenant'))['list'][0]['state']);
            $this->now = '2026-10-10 12:00:01';
            $reservations->reserve($this->admission('newer'));
            $pdo->exec("UPDATE credit_reservation SET deleted = TRUE WHERE state = 'released'");
            try {
                $history->inspect(new ReservationHistoryQuery('tenant', 1));
                $this->fail('Invalid lookahead row accepted.');
            } catch (LogicException) {
                $this->assertFalse($pdo->inTransaction());
            }
        });
    }

    #[DataProvider('dialects')]
    public function testReservationHistoryParentProjectionAndTransactionGuards(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $history = $this->reservationHistory($pdo);
            $pdo->exec("UPDATE tenant SET deleted = TRUE WHERE id = 'other'");
            foreach (['missing', 'other'] as $tenant) {
                try {
                    $history->inspect(new ReservationHistoryQuery($tenant));
                    $this->fail('Missing/deleted tenant accepted.');
                } catch (NotFound) {
                    $this->assertFalse($pdo->inTransaction());
                }
            }
            $pdo->beginTransaction();
            try {
                $history->inspect(new ReservationHistoryQuery('tenant'));
                $this->fail('Nested transaction accepted.');
            } catch (LogicException) {
                $this->assertTrue($pdo->inTransaction());
            }
            $pdo->rollBack();
            (new Funding($this->lock($pdo)))->grant($this->input());
            $this->reservations($pdo)->reserve($this->admission());
            $this->assertCount(1, $history->inspect(new ReservationHistoryQuery('tenant'))['list']);
            foreach ([
                ['credit_reservation', 'deleted = TRUE'],
                ['credit_reservation', 'reserved_credits = -1'],
                ['credit_reservation', "accrued_credits_exact = '-1'"],
                ['credit_reservation', "accrued_credits_exact = '1e2'"],
                ['credit_reservation', "accrued_credits_exact = '3'"],
                ['credit_reservation', "state = 'settled'"],
                ['credit_reservation', "usage_id = 'missing'"],
                ['credit_usage', 'deleted = TRUE'],
                ['credit_usage', "tenant_id = 'other'"],
                ['credit_usage', "execution_id = 'foreign'"],
                ['credit_usage', "state = 'released'"],
                ['credit_usage', "operation_type = 'invalid'"],
                ['credit_usage', 'settled_credits = 0'],
            ] as [$table, $corruption]) {
                $pdo->exec('UPDATE ' . $table . ' SET ' . $corruption);
                try {
                    $history->inspect(new ReservationHistoryQuery('tenant'));
                    $this->fail('Invalid reservation projection accepted: ' . $corruption);
                } catch (LogicException) {
                    $this->assertFalse($pdo->inTransaction());
                }
                $pdo->exec("UPDATE credit_reservation SET deleted = FALSE, reserved_credits = 2,
                    accrued_credits_exact = '0', state = 'held', usage_id = (SELECT id FROM credit_usage)");
                $pdo->exec("UPDATE credit_usage SET deleted = FALSE, tenant_id = 'tenant', execution_id = 'execution',
                    state = 'admitted', operation_type = 'ai', settled_credits = NULL");
            }
        });
    }
}
