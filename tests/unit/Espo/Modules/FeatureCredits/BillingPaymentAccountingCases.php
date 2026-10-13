<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Modules\FeatureCredits\Accounting\{NativeSearchDebt, Settlements};
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/BillingPaymentFixtures.php';

trait BillingPaymentAccountingCases
{
    #[DataProvider('dialects')]
    public function testVerifiedEndedAsaasAllowanceCannotRepayMeasuredNativeSearchDebt(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [, $funding, $usage] = $this->nativeDebtFixture($pdo);
            (new Settlements($this->lock($pdo), $funding))->settle('tenant', $usage, 'execution');
            $this->assertSame('5.0000', (string) NativeSearchDebt::outstanding($pdo, 'tenant'));
            $this->now = '2026-10-12 00:00:00'; $f = new BillingPaymentFixtures($pdo, $this->now);
            $f->catalog->publish(BillingFixtures::definition(), 'admin'); $this->now = '2026-10-13 00:00:00';
            [, $charge] = $f->charge(true); $id = $f->payment($charge);
            $this->now = '2026-11-15 03:00:00'; $receipt = $f->read($id);
            $this->assertTrue($receipt['endedPeriod']);
            $this->assertSame('-5.0000', $this->balanceStatus($pdo)->inspect('tenant')['balance']);
            $this->assertSame('5.0000', (string) NativeSearchDebt::outstanding($pdo, 'tenant'));
            $q = $pdo->prepare('SELECT remaining_credits, reserved_credits FROM credit_grant WHERE id=?'); $q->execute([$receipt['funding']['grantId']]);
            $this->assertSame(['remaining_credits' => '0.0000', 'reserved_credits' => '0.0000'], $q->fetch(PDO::FETCH_ASSOC));
            $this->assertAuditHealthy($pdo);
            $next = $f->payment($charge, 'pay_next', '2026-11-15'); $f->read($next);
            $this->assertSame('0.0000', (string) NativeSearchDebt::outstanding($pdo, 'tenant'));
            $this->assertSame('95.0000', $this->balanceStatus($pdo)->inspect('tenant')['balance']);
            $this->assertAuditHealthy($pdo);
        });
    }
}
