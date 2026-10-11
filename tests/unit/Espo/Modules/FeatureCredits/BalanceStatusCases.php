<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\NotFound;
use Espo\Entities\User;
use Espo\Modules\FeatureAiUsage\Services\Access;
use Espo\Modules\FeatureCredits\Accounting\BalanceStatus;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Accounting\Funding;
use Espo\Modules\FeatureCredits\Controllers\CreditBalance;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use LogicException;
use InvalidArgumentException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

trait BalanceStatusCases
{
    #[DataProvider('dialects')]
    public function testAuthorizedBalanceControllerPreservesExactSnapshot(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $user = $this->createMock(User::class);
            $user->method('getLinkMultipleIdList')->willReturn([md5('tenant-admin')]);
            $memberships = $this->createMock(UserTenantResolver::class);
            $memberships->method('resolveTenantIds')->willReturn(['tenant']);
            $manager = $this->createMock(EntityManager::class);
            $manager->method('getEntityById')->with('Tenant', 'tenant')->willReturn($this->createMock(Entity::class));
            $access = new Access($user, $manager, $this->createMock(TenantResolver::class), $memberships);
            $controller = new CreditBalance($access, $this->balanceStatus($pdo));
            $request = $this->createMock(Request::class);
            $request->method('getQueryParams')->willReturn(['tenantId' => 'tenant']);
            $response = $this->createMock(Response::class);
            $response->expects($this->exactly(3))->method('setHeader')->with('Cache-Control', 'private, no-store');
            $this->assertFalse($controller->getActionStatus($request, $response)->walletExists);
            (new Funding($this->lock($pdo)))->grant($this->input(credits: '9999999999.9999'));
            $before = $this->auditSnapshot($pdo);
            $result = $controller->getActionStatus($request, $response);
            $this->assertSame('9999999999.9999', $result->availableCredits);
            $this->assertSame('tenant', $result->tenantId);
            $this->assertTrue($result->walletExists);
            $this->assertSame((array) $result, json_decode(json_encode($result, JSON_THROW_ON_ERROR), true));
            $this->assertSame($before, $this->auditSnapshot($pdo));
            $pdo->exec('UPDATE tenant_credit_balance SET balance = 0');
            $this->expectException(LogicException::class);
            $controller->getActionStatus($request, $response);
        });
    }

    private function balanceStatus(PDO $pdo, ?callable $onSnapshot = null): BalanceStatus
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
        return new BalanceStatus($manager, $clock);
    }

    #[DataProvider('dialects')]
    public function testBalanceStatusDelayedExpirationAndOriginalHoldRelease(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $status = $this->balanceStatus($pdo);
            $this->assertSame(['tenantId' => 'tenant', 'observedAt' => $this->now, 'walletExists' => false,
                'balance' => '0.0000', 'reservedCredits' => '0.0000', 'pendingExpirationCredits' => '0.0000',
                'availableCredits' => '0.0000'], $status->inspect('tenant'));
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input('monthly', '3', expires: '2026-10-11 00:00:00'));
            $funding->grant($this->input('purchase', '7.0001'));
            $funding->grant($this->input(tenant: 'other', credits: '99'));
            $reservations = $this->reservations($pdo);
            $usage = $reservations->reserve($this->admission(credits: '0.5'))['usageId'];
            $this->assertSame('9.5001', $status->inspect('tenant')['availableCredits']);
            $this->now = '2026-10-11 00:00:00';
            $before = $this->auditSnapshot($pdo);
            $result = $status->inspect('tenant');
            $this->assertSame('10.0001', $result['balance']);
            $this->assertSame('0.5000', $result['reservedCredits']);
            $this->assertSame('2.5000', $result['pendingExpirationCredits']);
            $this->assertSame('7.0001', $result['availableCredits']);
            $this->assertSame($before, $this->auditSnapshot($pdo));
            $funding->expire('tenant');
            $this->assertSame('0.0000', $status->inspect('tenant')['pendingExpirationCredits']);
            $this->assertSame('7.0001', $status->inspect('tenant')['availableCredits']);
            $reservations->release('tenant', $usage, 'execution');
            $this->assertSame('7.0001', $status->inspect('tenant')['balance']);
            $this->assertSame('99.0000', $status->inspect('other')['availableCredits']);
        });
    }

    #[DataProvider('dialects')]
    public function testBalanceStatusSnapshotAcrossConcurrentFunding(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            (new Funding($this->lock($pdo)))->grant($this->input(credits: '1'));
            $other = $this->connection($dialect);
            $status = $this->balanceStatus($pdo, function () use ($other): void {
                (new Funding($this->lock($other)))->grant($this->input('concurrent', '2'));
            });
            $this->assertSame('1.0000', $status->inspect('tenant')['availableCredits']);
            $this->assertSame('3.0000', $this->balanceStatus($pdo)->inspect('tenant')['availableCredits']);
            $this->assertFalse($pdo->inTransaction());
        });
    }

    #[DataProvider('dialects')]
    public function testBalanceStatusReadOnlyEnforcementAndInputGuard(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            try {
                $this->balanceStatus($pdo)->inspect('');
                $this->fail('Empty identity accepted.');
            } catch (InvalidArgumentException) {
                $this->assertFalse($pdo->inTransaction());
            }
            try {
                $this->balanceStatus($pdo, function () use ($pdo): void {
                    $pdo->exec("UPDATE tenant SET deleted = TRUE WHERE id = 'tenant'");
                })->inspect('tenant');
                $this->fail('Status transaction accepted a write.');
            } catch (PDOException) {
                $this->assertFalse($pdo->inTransaction());
            }
            (new Funding($this->lock($pdo)))->grant($this->input(credits: '9999999999.9999'));
            $this->assertSame('9999999999.9999', $this->balanceStatus($pdo)->inspect('tenant')['availableCredits']);
        });
    }

    #[DataProvider('dialects')]
    public function testBalanceStatusRejectsMissingParentsDriftAndNestedTransactions(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $status = $this->balanceStatus($pdo);
            foreach (['missing', 'other'] as $tenant) {
                $pdo->exec("UPDATE tenant SET deleted = TRUE WHERE id = 'other'");
                try {
                    $status->inspect($tenant);
                    $this->fail('Missing/deleted tenant accepted.');
                } catch (NotFound) {
                    $this->assertFalse($pdo->inTransaction());
                }
            }
            (new Funding($this->lock($pdo)))->grant($this->input());
            foreach (["UPDATE tenant_credit_balance SET balance = 9", "UPDATE credit_grant SET deleted = TRUE",
                "DELETE FROM tenant_credit_balance"] as $sql) {
                $pdo->exec($sql);
                try {
                    $status->inspect('tenant');
                    $this->fail('Corrupt projection accepted.');
                } catch (LogicException) {
                    $this->assertFalse($pdo->inTransaction());
                }
                $pdo->exec('UPDATE tenant_credit_balance SET balance = 10');
                $pdo->exec('UPDATE credit_grant SET deleted = FALSE');
            }
            $pdo->beginTransaction();
            try {
                $status->inspect('tenant');
                $this->fail('Nested transaction accepted.');
            } catch (LogicException) {
                $this->assertTrue($pdo->inTransaction());
            }
            $pdo->rollBack();
        });
    }
}
