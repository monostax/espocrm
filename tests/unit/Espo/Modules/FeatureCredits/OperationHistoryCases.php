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
use Espo\Modules\FeatureCredits\Accounting\OperationHistory;
use Espo\Modules\FeatureCredits\Accounting\OperationHistoryQuery;
use Espo\Modules\FeatureCredits\Accounting\OutcomeInput;
use Espo\Modules\FeatureCredits\Accounting\Outcomes;
use Espo\Modules\FeatureCredits\Accounting\Requests;
use Espo\Modules\FeatureCredits\Accounting\Reservations;
use Espo\Modules\FeatureCredits\Accounting\Settlements;
use Espo\Modules\FeatureCredits\Controllers\CreditOperations;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use InvalidArgumentException;
use LogicException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

trait OperationHistoryCases
{
    private function operationHistory(PDO $pdo, ?callable $onSnapshot = null): OperationHistory
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
        return new OperationHistory($manager, $clock);
    }

    #[DataProvider('dialects')]
    public function testOperationPagesAndConcurrentClosure(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $history = $this->operationHistory($pdo);
            $this->assertSame([], $history->inspect(new OperationHistoryQuery('tenant'))['list']);
            (new Funding($this->lock($pdo)))->grant($this->input());
            $writer = $this->reservations($pdo);
            for ($i = 0; $i < 5; $i++) {
                $writer->reserve($this->admission('page-' . $i, '1'));
            }
            $expected = $pdo->query('SELECT id FROM credit_usage ORDER BY admitted_at DESC, id DESC')->fetchAll(PDO::FETCH_COLUMN);
            $before = $this->auditSnapshot($pdo);
            $first = $history->inspect(new OperationHistoryQuery('tenant', 2));
            $this->assertSame($before, $this->auditSnapshot($pdo));
            $this->assertSame(array_slice($expected, 0, 2), array_column($first['list'], 'id'));
            $this->now = '2026-10-10 12:00:01';
            $other = $this->connection($dialect);
            $writer = new Reservations($this->lock($other), new Funding($this->lock($other)));
            $writer->reserve($this->admission('newer', '1'));
            $writer->release('tenant', $first['list'][1]['id'], 'execution');
            $second = $history->inspect(new OperationHistoryQuery('tenant', 2, $first['nextCursor']));
            $third = $history->inspect(new OperationHistoryQuery('tenant', 2, $second['nextCursor']));
            $this->assertSame($expected, array_column(array_merge($first['list'], $second['list'], $third['list']), 'id'));
            $this->assertNull($third['nextCursor']);
            $this->assertSame($second, $history->inspect(new OperationHistoryQuery('tenant', 2, $first['nextCursor'])));
            $this->assertSame([], $history->inspect(new OperationHistoryQuery('other'))['list']);
            $this->expectException(InvalidArgumentException::class);
            new OperationHistoryQuery('other', 2, $first['nextCursor']);
        });
    }

    #[DataProvider('dialects')]
    public function testOperationUnknownMeasuredAndSettledCharges(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input(expires: '2026-10-11 00:00:00'));
            $reservations = $this->reservations($pdo);
            $usage = $reservations->reserve($this->admission())['usageId'];
            $released = $reservations->reserve($this->admission('release', '1', 'apollo'))['usageId'];
            $id = (new Requests($this->lock($pdo), $funding))->authorize($this->request($usage))['requestId'];
            $outcomes = new Outcomes($this->lock($pdo));
            $read = fn () => array_column($this->operationHistory($pdo)->inspect(new OperationHistoryQuery('tenant'))['list'], null, 'id');
            $outcomes->record(new OutcomeInput('tenant', $usage, 'execution', $id, 'cancelled', null, null, null,
                (object) ['source' => 'provider']));
            $this->assertSame('admitted', $read()[$usage]['state']);
            $this->assertNull($read()[$usage]['settledCredits']);
            $this->assertSame('0', $read()[$usage]['accruedCreditsExact']);
            $outcomes->record(new OutcomeInput('tenant', $usage, 'execution', $id, 'cancelled', 0, 0, 1,
                (object) ['source' => 'provider']));
            $this->assertSame('0.0001875', $read()[$usage]['accruedCreditsExact']);
            $this->now = '2026-10-11 00:00:00';
            $this->assertSame('2.0000', $read()[$usage]['reservedCredits']);
            $settlements = new Settlements($this->lock($pdo), $funding);
            $settlements->settle('tenant', $usage, 'execution');
            $settlements->settle('tenant', $usage, 'execution');
            $reservations->release('tenant', $released, 'execution');
            $rows = $read();
            $this->assertCount(2, $rows);
            $this->assertSame('0.0002', $rows[$usage]['settledCredits']);
            $this->assertSame('0.0000', $rows[$usage]['reservedCredits']);
            $this->assertSame('settled', $rows[$usage]['state']);
            $this->assertSame('released', $rows[$released]['state']);
            $this->assertSame('0.0000', $rows[$released]['settledCredits']);
            $pdo->exec("UPDATE credit_usage SET source_type = 'Contact', source_id = 'deleted-private-source',
                operation_key = 'private-key', evidence = '{\"private\":true}' WHERE id = " . $pdo->quote($usage));
            $this->assertSame($rows, $read());
        });
    }

    #[DataProvider('dialects')]
    public function testOperationAuthorizedMaximumAndAllowlist(string $dialect): void
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
            $result = (array) (new CreditOperations($access, $this->operationHistory($pdo)))->getActionList($request, $response);
            $this->assertSame('9999999999.9999', $result['list'][0]['reservedCredits']);
            $this->assertSame(['id', 'reservationId', 'operationType', 'billingRegime', 'state', 'reservedCredits',
                'accruedCreditsExact', 'settledCredits', 'admittedAt', 'settledAt'], array_keys($result['list'][0]));
            $this->assertSame($result, json_decode(json_encode($result, JSON_THROW_ON_ERROR), true));
            $this->assertSame($before, $this->auditSnapshot($pdo));
        });
    }

    #[DataProvider('dialects')]
    public function testOperationSnapshotReadOnlyAndTransactionGuards(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            (new Funding($this->lock($pdo)))->grant($this->input());
            $usage = $this->reservations($pdo)->reserve($this->admission())['usageId'];
            $other = $this->connection($dialect);
            $history = $this->operationHistory($pdo, function () use ($other, $usage): void {
                (new Reservations($this->lock($other), new Funding($this->lock($other))))->release('tenant', $usage, 'execution');
            });
            $this->assertSame('admitted', $history->inspect(new OperationHistoryQuery('tenant'))['list'][0]['state']);
            $history = $this->operationHistory($pdo);
            $this->assertSame('released', $history->inspect(new OperationHistoryQuery('tenant'))['list'][0]['state']);
            try {
                $this->operationHistory($pdo, function () use ($pdo): void {
                    $pdo->exec("UPDATE tenant SET deleted = TRUE WHERE id = 'tenant'");
                })->inspect(new OperationHistoryQuery('tenant'));
                $this->fail('Read-only report accepted a write.');
            } catch (PDOException) {
                $this->assertFalse($pdo->inTransaction());
            }
            $pdo->exec("UPDATE tenant SET deleted = TRUE WHERE id = 'other'");
            foreach (['other', 'missing'] as $tenant) {
                try {
                    $history->inspect(new OperationHistoryQuery($tenant));
                    $this->fail('Missing tenant accepted.');
                } catch (NotFound) {
                    $this->assertFalse($pdo->inTransaction());
                }
            }
            $pdo->beginTransaction();
            try {
                $history->inspect(new OperationHistoryQuery('tenant'));
                $this->fail('Nested transaction accepted.');
            } catch (LogicException) {
                $this->assertTrue($pdo->inTransaction());
            }
            $pdo->rollBack();
            $this->assertCount(1, $history->inspect(new OperationHistoryQuery('tenant'))['list']);
        });
    }

    #[DataProvider('dialects')]
    public function testOperationRejectsMissingCorruptReservationAndLookahead(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            (new Funding($this->lock($pdo)))->grant($this->input());
            $reservations = $this->reservations($pdo);
            $usage = $reservations->reserve($this->admission())['usageId'];
            $history = $this->operationHistory($pdo);
            foreach ([
                ['credit_usage', 'deleted = TRUE'],
                ['credit_usage', "billing_regime = 'legacy'"],
                ['credit_usage', "operation_type = 'invalid'"],
                ['credit_usage', "state = 'pendingReconciliation'"],
                ['credit_usage', 'settled_credits = 0'],
                ['credit_reservation', "usage_id = 'missing'"],
                ['credit_reservation', "tenant_id = 'other'"],
                ['credit_reservation', "execution_id = 'foreign'"],
                ['credit_reservation', 'deleted = TRUE'],
                ['credit_reservation', "state = 'released'"],
                ['credit_reservation', 'reserved_credits = -1'],
                ['credit_reservation', "accrued_credits_exact = '3'"],
                ['credit_reservation', "accrued_credits_exact = '1e2'"],
            ] as [$table, $corruption]) {
                $pdo->exec('UPDATE ' . $table . ' SET ' . $corruption);
                try {
                    $history->inspect(new OperationHistoryQuery('tenant'));
                    $this->fail('Corrupt operation accepted: ' . $corruption);
                } catch (LogicException) {
                    $this->assertFalse($pdo->inTransaction());
                }
                $pdo->exec("UPDATE credit_usage SET deleted = FALSE, billing_regime = 'unified-prepaid-v1',
                    operation_type = 'ai', state = 'admitted', settled_credits = NULL");
                $pdo->exec("UPDATE credit_reservation SET deleted = FALSE, tenant_id = 'tenant', execution_id = 'execution',
                    state = 'held', reserved_credits = 2, accrued_credits_exact = '0', usage_id = " . $pdo->quote($usage));
            }
            $reservations->release('tenant', $usage, 'execution');
            $pdo->exec('UPDATE credit_usage SET settled_credits = 1');
            try {
                $history->inspect(new OperationHistoryQuery('tenant'));
                $this->fail('Incorrect closed charge accepted.');
            } catch (LogicException) {
                $this->assertFalse($pdo->inTransaction());
            }
            $pdo->exec('UPDATE credit_usage SET settled_credits = 0');
            $this->now = '2026-10-10 12:00:01';
            $reservations->reserve($this->admission('newer'));
            $pdo->exec("DELETE FROM credit_reservation WHERE state = 'released'");
            try {
                $history->inspect(new OperationHistoryQuery('tenant', 1));
                $this->fail('Missing lookahead reservation accepted.');
            } catch (LogicException) {
                $this->assertFalse($pdo->inTransaction());
            }
        });
    }
}
