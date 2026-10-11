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
use Espo\Modules\FeatureCredits\Accounting\Reconciliation;
use Espo\Modules\FeatureCredits\Accounting\Requests;
use Espo\Modules\FeatureCredits\Accounting\RequestHistory;
use Espo\Modules\FeatureCredits\Accounting\RequestHistoryQuery;
use Espo\Modules\FeatureCredits\Accounting\Settlements;
use Espo\Modules\FeatureCredits\Controllers\CreditRequests;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use LogicException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

trait RequestHistoryCases
{
    private function requestHistory(PDO $pdo, ?callable $onSnapshot = null): RequestHistory
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
        return new RequestHistory($manager, $clock);
    }

    #[DataProvider('dialects')]
    public function testRequestHistoryPendingMeasuredWaivedAndSettled(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$usage, $cancelled] = $this->pendingCancellation($pdo);
            $funding = new Funding($this->lock($pdo));
            $requests = new Requests($this->lock($pdo), $funding);
            $outcomes = new Outcomes($this->lock($pdo));
            $success = $requests->authorize($this->request($usage, 'success'))['requestId'];
            $failure = $requests->authorize($this->request($usage, 'failed'))['requestId'];
            $zero = $requests->authorize($this->request($usage, 'zero'))['requestId'];
            $unknownFailure = $requests->authorize($this->request($usage, 'unknown-failure'))['requestId'];
            $read = fn () => array_column($this->requestHistory($pdo)->inspect(new RequestHistoryQuery('tenant'))['list'], null, 'id');
            $rows = $read();
            $this->assertSame('inFlight', $rows[$success]['outcome']);
            $this->assertNull($rows[$success]['completedAt']);
            $this->assertSame('pending', $rows[$cancelled]['billingState']);
            $this->assertSame('unknown', $rows[$cancelled]['meteringState']);
            $this->assertNull($rows[$cancelled]['pricedCreditsExact']);
            foreach ([[$success, 'success', 1], [$failure, 'infrastructureFailure', 100000],
                [$zero, 'superseded', 0], [$unknownFailure, 'infrastructureFailure', null]] as [$id, $outcome, $tokens]) {
                $outcomes->record(new OutcomeInput('tenant', $usage, 'execution', $id, $outcome, 0, 0, $tokens,
                    (object) ['source' => 'private-provider-evidence']));
            }
            $rows = $read();
            $this->assertSame('0.0001875', $rows[$success]['pricedCreditsExact']);
            $this->assertSame('billable', $rows[$success]['billingState']);
            $this->assertSame('0', $rows[$zero]['pricedCreditsExact']);
            $this->assertSame('measured', $rows[$zero]['meteringState']);
            // A measured infrastructure failure can exceed its bound, but its price is waived.
            $this->assertSame('18.75', $rows[$failure]['pricedCreditsExact']);
            $this->assertSame('waived', $rows[$failure]['billingState']);
            $this->assertSame('infrastructure_failure', $rows[$failure]['waiverReason']);
            $this->assertNull($rows[$unknownFailure]['pricedCreditsExact']);
            $this->assertSame('waived', $rows[$unknownFailure]['billingState']);
            $reconciliation = new Reconciliation($this->lock($pdo));
            $reconciliation->recordUnavailable($this->reconciliation($usage, $cancelled));
            $this->now = '2026-10-10 12:02:00';
            $reconciliation->recordUnavailable($this->reconciliation($usage, $cancelled, 'last'));
            $this->assertSame('unrecoverable', $read()[$cancelled]['meteringState']);
            $this->assertSame('unrecoverable_cancellation', $read()[$cancelled]['waiverReason']);
            $settlement = new Settlements($this->lock($pdo), $funding);
            $this->assertSame('0.0002', $settlement->settle('tenant', $usage, 'execution')['settledCredits']);
            $settlement->settle('tenant', $usage, 'execution');
            $before = $this->auditSnapshot($pdo);
            $rows = $read();
            $this->assertCount(5, $rows);
            $this->assertSame(['settled'], array_values(array_unique(array_column($rows, 'operationState'))));
            $this->assertSame('0.0001875', $rows[$success]['pricedCreditsExact']);
            $this->assertNull($rows[$cancelled]['pricedCreditsExact']);
            $this->assertSame($before, $this->auditSnapshot($pdo));
        });
    }

    #[DataProvider('dialects')]
    public function testRequestHistoryPaginationAndConcurrentResolution(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $history = $this->requestHistory($pdo);
            $this->assertSame(['tenantId' => 'tenant', 'observedAt' => $this->now, 'list' => [], 'nextCursor' => null],
                $history->inspect(new RequestHistoryQuery('tenant')));
            [$usage] = $this->pendingCancellation($pdo, 'inFlight');
            $requests = new Requests($this->lock($pdo), new Funding($this->lock($pdo)));
            for ($i = 0; $i < 4; $i++) {
                $requests->authorize($this->request($usage, 'page-' . $i));
            }
            $expected = $pdo->query('SELECT id FROM credit_request ORDER BY authorized_at DESC, id DESC')->fetchAll(PDO::FETCH_COLUMN);
            $first = $history->inspect(new RequestHistoryQuery('tenant', 2));
            $this->assertSame(array_slice($expected, 0, 2), array_column($first['list'], 'id'));
            $this->now = '2026-10-10 12:00:01';
            $other = $this->connection($dialect);
            (new Requests($this->lock($other), new Funding($this->lock($other))))->authorize($this->request($usage, 'newer'));
            (new Outcomes($this->lock($other)))->record(new OutcomeInput('tenant', $usage, 'execution', $first['list'][1]['id'],
                'success', 0, 0, 0, (object) ['source' => 'provider']));
            $second = $history->inspect(new RequestHistoryQuery('tenant', 2, $first['nextCursor']));
            $third = $history->inspect(new RequestHistoryQuery('tenant', 2, $second['nextCursor']));
            $this->assertSame($expected, array_column(array_merge($first['list'], $second['list'], $third['list']), 'id'));
            $this->assertNull($third['nextCursor']);
            $this->assertSame($second, $history->inspect(new RequestHistoryQuery('tenant', 2, $first['nextCursor'])));
            $this->assertCount(6, $history->inspect(new RequestHistoryQuery('tenant'))['list']);
            $this->assertSame([], $history->inspect(new RequestHistoryQuery('other'))['list']);
        });
    }

    #[DataProvider('dialects')]
    public function testRequestHistoryAuthorizedAllowlistAndExactAmounts(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$usage, $id] = $this->pendingCancellation($pdo);
            // Projection fixture exercises the storage maximum without an enormous provider call.
            $pdo->exec("UPDATE credit_request SET authorized_credits = '9999999999.9999'");
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
            $result = (array) (new CreditRequests($access, $this->requestHistory($pdo)))->getActionList($request, $response);
            $this->assertSame('9999999999.9999', $result['list'][0]['authorizedCredits']);
            $this->assertSame(['id', 'usageId', 'reservationId', 'operationState', 'outcome', 'meteringState',
                'billingState', 'waiverReason', 'authorizedCredits', 'pricedCreditsExact', 'authorizedAt', 'completedAt'],
                array_keys($result['list'][0]));
            $this->assertSame($result, json_decode(json_encode($result, JSON_THROW_ON_ERROR), true));
            $this->assertSame($before, $this->auditSnapshot($pdo));
        });
    }

    #[DataProvider('dialects')]
    public function testRequestHistoryReadOnlySnapshotAndCleanup(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            [$usage, $id] = $this->pendingCancellation($pdo);
            $other = $this->connection($dialect);
            $history = $this->requestHistory($pdo, function () use ($other, $usage, $id): void {
                (new Outcomes($this->lock($other)))->record(new OutcomeInput('tenant', $usage, 'execution', $id,
                    'cancelled', 0, 0, 0, (object) ['source' => 'provider'], 'provider-request'));
            });
            $this->assertSame('pending', $history->inspect(new RequestHistoryQuery('tenant'))['list'][0]['billingState']);
            $this->assertSame('billable', $this->requestHistory($pdo)->inspect(new RequestHistoryQuery('tenant'))['list'][0]['billingState']);
            try {
                $this->requestHistory($pdo, function () use ($pdo): void {
                    $pdo->exec("UPDATE tenant SET deleted = TRUE WHERE id = 'tenant'");
                })->inspect(new RequestHistoryQuery('tenant'));
                $this->fail('Request report accepted a write.');
            } catch (PDOException) {
                $this->assertFalse($pdo->inTransaction());
            }
            (new Funding($this->lock($pdo)))->grant($this->input('after-read-only'));
            $this->assertCount(1, $this->requestHistory($pdo)->inspect(new RequestHistoryQuery('tenant'))['list']);
        });
    }

    #[DataProvider('dialects')]
    public function testRequestHistoryRejectsCorruptParentsStatesAndLookahead(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$usage, $id] = $this->pendingCancellation($pdo);
            $history = $this->requestHistory($pdo);
            foreach ([
                ['credit_request', 'deleted = TRUE'],
                ['credit_request', "usage_id = 'missing'"],
                ['credit_request', "reservation_id = 'missing'"],
                ['credit_request', 'authorized_credits = -1'],
                ['credit_request', "priced_credits_exact = '-1'"],
                ['credit_request', "priced_credits_exact = '1e2'"],
                ['credit_request', "priced_credits_exact = '0'"],
                ['credit_request', "metering_state = 'measured'"],
                ['credit_request', "metering_state = 'unrecoverable'"],
                ['credit_request', "billing_state = 'waived'"],
                ['credit_request', "billing_state = 'billable'"],
                ['credit_request', "waiver_reason = 'invented'"],
                ['credit_request', "outcome = 'inFlight'"],
                ['credit_request', "outcome = 'noMatch'"],
                ['credit_request', 'completed_at = NULL'],
                ['credit_usage', 'deleted = TRUE'],
                ['credit_usage', "tenant_id = 'other'"],
                ['credit_usage', "execution_id = 'foreign'"],
                ['credit_usage', "operation_type = 'apollo'"],
                ['credit_usage', "state = 'settled'"],
                ['credit_reservation', 'deleted = TRUE'],
                ['credit_reservation', "tenant_id = 'other'"],
                ['credit_reservation', "usage_id = 'missing'"],
                ['credit_reservation', "state = 'released'"],
            ] as [$table, $corruption]) {
                $original = $pdo->query('SELECT * FROM ' . $table)->fetch(PDO::FETCH_ASSOC);
                $pdo->exec('UPDATE ' . $table . ' SET ' . $corruption);
                try {
                    $history->inspect(new RequestHistoryQuery('tenant'));
                    $this->fail('Invalid projection accepted: ' . $corruption);
                } catch (LogicException) {
                    $this->assertFalse($pdo->inTransaction());
                }
                $column = trim(explode('=', $corruption)[0]);
                $value = $original[$column];
                $pdo->prepare('UPDATE ' . $table . ' SET ' . $column . ' = ?')->execute([is_bool($value) ? (int) $value : $value]);
            }
            $this->assertCount(1, $history->inspect(new RequestHistoryQuery('tenant'))['list']);
            $this->now = '2026-10-10 12:00:01';
            (new Requests($this->lock($pdo), new Funding($this->lock($pdo))))->authorize($this->request($usage, 'newer'));
            $pdo->prepare('UPDATE credit_request SET deleted = TRUE WHERE id = ?')->execute([$id]);
            $this->expectException(LogicException::class);
            $history->inspect(new RequestHistoryQuery('tenant', 1));
        });
    }

    #[DataProvider('dialects')]
    public function testRequestHistoryRejectsInvalidTerminalProjections(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$usage, $id] = $this->pendingCancellation($pdo);
            (new Outcomes($this->lock($pdo)))->record(new OutcomeInput('tenant', $usage, 'execution', $id,
                'cancelled', 0, 0, 1, (object) ['source' => 'provider'], 'provider-request'));
            $history = $this->requestHistory($pdo);
            foreach (["priced_credits_exact = '100'", "waiver_reason = 'infrastructure_failure'",
                "billing_state = 'pending'", "billing_state = 'waived'", "outcome = 'infrastructureFailure'",
                "metering_state = 'unrecoverable'", 'completed_at = NULL'] as $corruption) {
                $pdo->exec('UPDATE credit_request SET ' . $corruption);
                try {
                    $history->inspect(new RequestHistoryQuery('tenant'));
                    $this->fail('Invalid terminal projection accepted: ' . $corruption);
                } catch (LogicException) {
                    $this->assertFalse($pdo->inTransaction());
                }
                $pdo->exec("UPDATE credit_request SET priced_credits_exact = '0.0001875', waiver_reason = NULL,
                    billing_state = 'billable', outcome = 'cancelled', metering_state = 'measured',
                    completed_at = '2026-10-10 12:00:00'");
            }
            (new Settlements($this->lock($pdo), new Funding($this->lock($pdo))))->settle('tenant', $usage, 'execution');
            $this->assertSame('settled', $history->inspect(new RequestHistoryQuery('tenant'))['list'][0]['operationState']);
            $pdo->exec("UPDATE credit_request SET billing_state = 'pending', metering_state = 'unknown', priced_credits_exact = NULL");
            $this->expectException(LogicException::class);
            $history->inspect(new RequestHistoryQuery('tenant'));
        });
    }

    #[DataProvider('dialects')]
    public function testRequestHistoryTenantAndTransactionGuards(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $history = $this->requestHistory($pdo);
            $pdo->exec("UPDATE tenant SET deleted = TRUE WHERE id = 'other'");
            foreach (['missing', 'other'] as $tenant) {
                try {
                    $history->inspect(new RequestHistoryQuery($tenant));
                    $this->fail('Missing/deleted tenant accepted.');
                } catch (NotFound) {
                    $this->assertFalse($pdo->inTransaction());
                }
            }
            $pdo->beginTransaction();
            try {
                $history->inspect(new RequestHistoryQuery('tenant'));
                $this->fail('Nested transaction accepted.');
            } catch (LogicException) {
                $this->assertTrue($pdo->inTransaction());
            }
            $pdo->rollBack();
        });
    }
}
