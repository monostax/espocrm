<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\NotFound;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Accounting\ConsistencyAudit;
use Espo\Modules\FeatureCredits\Accounting\Funding;
use Espo\Modules\FeatureCredits\Accounting\OutcomeInput;
use Espo\Modules\FeatureCredits\Accounting\Outcomes;
use Espo\Modules\FeatureCredits\Accounting\Requests;
use Espo\Modules\FeatureCredits\Accounting\Settlements;
use Espo\ORM\EntityManager;
use InvalidArgumentException;
use LogicException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

/** Reuses FundingTest's generated-schema, real-connection and trusted-service fixtures. */
trait ConsistencyAuditCases
{
    private function audit(PDO $pdo, ?callable $onSnapshot = null): ConsistencyAudit
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
        return new ConsistencyAudit($manager, $clock);
    }

    private function auditSnapshot(PDO $pdo): array
    {
        $snapshot = [];
        foreach (['tenant_credit_balance', 'credit_grant', 'credit_transaction', 'credit_usage',
            'credit_reservation', 'credit_allocation', 'credit_request'] as $table) {
            $snapshot[$table] = $pdo->query("SELECT * FROM $table ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        }
        return $snapshot;
    }

    private function assertAuditHealthy(PDO $pdo): void
    {
        $before = $this->auditSnapshot($pdo);
        $report = $this->audit($pdo)->inspect('tenant');
        $this->assertSame([], $report['findings']);
        $this->assertTrue($report['consistent']);
        $this->assertFalse($report['findingsTruncated']);
        $this->assertSame($before, $this->auditSnapshot($pdo));
        $this->assertFalse($pdo->inTransaction());
    }

    #[DataProvider('dialects')]
    public function testAuditHealthyLifecycleIncludingDelayedExpiryAndUnknownUsage(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $this->assertAuditHealthy($pdo); // No wallet is created for an empty tenant.
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input('monthly', '3', expires: '2026-10-11 00:00:00'));
            $funding->grant($this->input('purchase', '7.0001'));
            $this->assertAuditHealthy($pdo);
            $reservations = $this->reservations($pdo);
            $release = $reservations->reserve($this->admission('release'))['usageId'];
            $this->assertAuditHealthy($pdo);
            $reservations->release('tenant', $release, 'execution');
            $usage = $reservations->reserve($this->admission())['usageId'];
            $requests = new Requests($this->lock($pdo), $funding);
            $request = $requests->authorize($this->request($usage))['requestId'];
            $this->assertAuditHealthy($pdo); // Closed/reallocated admission buffer is valid history.
            $outcomes = new Outcomes($this->lock($pdo));
            $outcomes->record(new OutcomeInput('tenant', $usage, 'execution', $request, 'cancelled', null, null, null,
                (object) ['source' => 'provider']));
            $this->now = '2026-10-11 00:00:00';
            $this->assertAuditHealthy($pdo); // Expiry eligibility is not a projection error; audit does not sweep.
            $funding->expire('tenant');
            $this->assertAuditHealthy($pdo);
            $outcomes->record(new OutcomeInput('tenant', $usage, 'execution', $request, 'cancelled', 0, 0, 999,
                (object) ['source' => 'provider-authoritative']));
            (new Settlements($this->lock($pdo), $funding))->settle('tenant', $usage, 'execution');
            $this->assertAuditHealthy($pdo); // Debit and immediate expiration retain their original lot links.
            $apollo = $reservations->reserve($this->admission('apollo', '0.0001', 'apollo'))['usageId'];
            $this->assertAuditHealthy($pdo);
            $reservations->release('tenant', $apollo, 'execution');
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testAuditDetectsProjectionDriftWithoutRepair(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$usage, $request] = $this->pendingCancellation($pdo);
            $this->assertAuditHealthy($pdo);
            foreach ([
                ['tenant_credit_balance', 'balance', '9.0000', 'wallet.ledger'],
                ['tenant_credit_balance', 'reserved_credits', '1.0000', 'wallet.reservation_holds'],
                ['credit_grant', 'remaining_credits', '9.0000', 'grant.ledger'],
                ['credit_grant', 'reserved_credits', '1.0000', 'grant.holds'],
                ['credit_reservation', 'reserved_credits', '1.0000', 'reservation.holds'],
                ['credit_request', 'authorized_credits', '1.0000', 'request.bound'],
                ['credit_allocation', 'released_credits', '0.0001', 'allocation.partition'],
                ['credit_usage', 'settled_credits', '0.0001', 'usage.consumption'],
                ['credit_reservation', 'execution_id', 'forged', 'reservation.state'],
                ['credit_grant', 'grant_transaction_id', 'missing', 'grant.source'],
                ['credit_transaction', 'grant_id', 'missing', 'transaction.grant'],
            ] as [$table, $field, $value, $code]) {
                $row = $pdo->query("SELECT id, $field FROM $table ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                $update = $pdo->prepare("UPDATE $table SET $field = ? WHERE id = ?");
                $update->execute([$value, $row['id']]);
                $before = $this->auditSnapshot($pdo);
                $report = $this->audit($pdo)->inspect('tenant');
                $this->assertFalse($report['consistent'], $code);
                $this->assertContains($code, array_column($report['findings'], 'code'));
                $this->assertSame($before, $this->auditSnapshot($pdo), 'Audit repaired or changed financial records.');
                $update->execute([$row[$field], $row['id']]);
            }
            $this->assertAuditHealthy($pdo);
            (new Outcomes($this->lock($pdo)))->record(new OutcomeInput('tenant', $usage, 'execution', $request, 'cancelled', 0, 0, 1000,
                (object) ['source' => 'provider'], 'provider-request'));
            (new Settlements($this->lock($pdo), new Funding($this->lock($pdo))))->settle('tenant', $usage, 'execution');
            $this->assertAuditHealthy($pdo);
            $pdo->exec("UPDATE credit_allocation SET debit_transaction_id = NULL WHERE consumed_credits > 0");
            $codes = array_column($this->audit($pdo)->inspect('tenant')['findings'], 'code');
            $this->assertContains('allocation.debit', $codes);
            $this->assertContains('transaction.shape', $codes);
        });
    }

    #[DataProvider('dialects')]
    public function testAuditDetectsDeletedAndMissingRecordsAndBoundsFindings(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $this->pendingCancellation($pdo);
            foreach (array_keys($this->auditSnapshot($pdo)) as $table) {
                $pdo->exec("UPDATE $table SET deleted = TRUE");
            }
            $audit = $this->audit($pdo);
            $full = $audit->inspect('tenant', 500);
            $this->assertCount(7, array_unique(array_column(array_filter($full['findings'],
                static fn (array $finding): bool => $finding['code'] === 'record.deleted'), 'entityType')));
            $bounded = $audit->inspect('tenant', 1);
            $this->assertCount(1, $bounded['findings']);
            $this->assertFalse($bounded['consistent']);
            $this->assertTrue($bounded['findingsTruncated']);
            $this->assertSame($bounded, $audit->inspect('tenant', 1));
            $exact = $audit->inspect('tenant', count($full['findings']));
            $this->assertFalse($exact['findingsTruncated']);
            $this->assertSame($full['findings'], $exact['findings']);
            $pdo->exec('DELETE FROM tenant_credit_balance');
            $this->assertContains('wallet.missing', array_column($audit->inspect('tenant')['findings'], 'code'));
        });
    }

    #[DataProvider('dialects')]
    public function testAuditTenantIsolationAndOwnership(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $this->pendingCancellation($pdo);
            $funding = new Funding($this->lock($pdo));
            $foreign = $funding->grant($this->input(tenant: 'other'));
            $pdo->exec("UPDATE tenant_credit_balance SET balance = 9 WHERE tenant_id = 'other'");
            $this->assertAuditHealthy($pdo);
            $pdo->prepare('UPDATE credit_allocation SET grant_id = ? WHERE tenant_id = ?')->execute([$foreign['grantId'], 'tenant']);
            $report = $this->audit($pdo)->inspect('tenant');
            $this->assertContains('allocation.parents', array_column($report['findings'], 'code'));
            $this->assertNotContains($foreign['grantId'], array_column($report['findings'], 'id'));
            $pdo->exec("UPDATE credit_request SET reservation_id = 'missing'");
            $this->assertContains('request.parents', array_column($this->audit($pdo)->inspect('tenant')['findings'], 'code'));
            $pdo->exec('DELETE FROM credit_reservation');
            $this->assertContains('usage.reservation', array_column($this->audit($pdo)->inspect('tenant')['findings'], 'code'));
        });
    }

    #[DataProvider('dialects')]
    public function testAuditUsesOneReadOnlySnapshotAcrossConcurrentCommit(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input());
            $other = $this->connection($dialect);
            $audit = $this->audit($pdo, function () use ($pdo, $other): void {
                $this->assertTrue($pdo->inTransaction());
                // A complete financial mutation commits after the audit's first read, without waiting on it.
                (new Funding($this->lock($other)))->grant($this->input('concurrent', '1.0001'));
                // Then inject drift to prove ALL diagnostic queries retain the first read's snapshot.
                $other->exec("UPDATE tenant_credit_balance SET balance = 1 WHERE tenant_id = 'tenant'");
            });
            $this->assertTrue($audit->inspect('tenant')['consistent']);
            $this->assertContains('wallet.ledger', array_column($this->audit($pdo)->inspect('tenant')['findings'], 'code'));
            try {
                $this->audit($pdo, static function () use ($pdo): void {
                    $pdo->exec("UPDATE tenant_credit_balance SET balance = 2 WHERE tenant_id = 'tenant'");
                })->inspect('tenant');
                $this->fail('Audit transaction permitted a write.');
            } catch (PDOException) {
                $this->assertFalse($pdo->inTransaction());
                $this->assertSame('1.0000', $pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
            }
            // The audit must not leak its isolation/read-only setting to subsequent accounting work.
            $pdo->beginTransaction();
            $pdo->exec('UPDATE tenant_credit_balance SET balance = 11.0001');
            $pdo->commit();
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testAuditDetectsOffsettingLotDriftAndLateExpirationLinkCorruption(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $funding = new Funding($this->lock($pdo));
            $first = $funding->grant($this->input('first', '1', expires: '2026-10-11 00:00:00'));
            $second = $funding->grant($this->input('second', '2'));
            $reservations = $this->reservations($pdo);
            $usage = $reservations->reserve($this->admission())['usageId'];
            // Preserve all wallet totals while moving a held quantum to the wrong original lot.
            $pdo->prepare('UPDATE credit_grant SET reserved_credits = reserved_credits - 0.0001 WHERE id = ?')
                ->execute([$first['grantId']]);
            $pdo->prepare('UPDATE credit_grant SET reserved_credits = reserved_credits + 0.0001 WHERE id = ?')
                ->execute([$second['grantId']]);
            $report = $this->audit($pdo)->inspect('tenant');
            $codes = array_column($report['findings'], 'code');
            $this->assertNotContains('wallet.grant_holds', $codes);
            $this->assertCount(2, array_filter($codes, static fn (string $code): bool => $code === 'grant.holds'));
            $pdo->exec('UPDATE credit_grant SET reserved_credits = 1');
            $this->assertAuditHealthy($pdo);
            $this->now = '2026-10-11 00:00:00';
            $reservations->release('tenant', $usage, 'execution');
            $this->assertAuditHealthy($pdo);
            $pdo->exec('UPDATE credit_allocation SET expiration_transaction_id = NULL WHERE expired_credits > 0');
            $codes = array_column($this->audit($pdo)->inspect('tenant')['findings'], 'code');
            $this->assertContains('allocation.expiration', $codes);
            $this->assertContains('transaction.shape', $codes);
            $pdo->exec('UPDATE credit_grant SET reserved_credits = -0.0001');
            $this->assertContains('grant.range', array_column($this->audit($pdo)->inspect('tenant')['findings'], 'code'));
        });
    }

    #[DataProvider('dialects')]
    public function testAuditInputGuardsAndTransactionCleanup(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $audit = $this->audit($pdo);
            foreach ([['', 100], ['tenant', 0], ['tenant', 501]] as [$tenant, $limit]) {
                try {
                    $audit->inspect($tenant, $limit);
                    $this->fail('Malformed audit command accepted.');
                } catch (InvalidArgumentException) {
                    $this->assertFalse($pdo->inTransaction());
                }
            }
            $pdo->exec("UPDATE tenant SET deleted = TRUE WHERE id = 'other'");
            foreach (['other', 'missing'] as $tenant) {
                try {
                    $audit->inspect($tenant);
                    $this->fail('Missing/deleted tenant accepted.');
                } catch (NotFound) {
                    $this->assertFalse($pdo->inTransaction());
                }
            }
            $pdo->beginTransaction();
            try {
                $audit->inspect('tenant');
                $this->fail('Audit entered caller-owned transaction.');
            } catch (LogicException) {
                $this->assertTrue($pdo->inTransaction());
            } finally {
                $pdo->rollBack();
            }
            $this->assertAuditHealthy($pdo);
        });
    }
}
