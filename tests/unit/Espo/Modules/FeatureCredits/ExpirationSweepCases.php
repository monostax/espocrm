<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Accounting\ExpirationSweep;
use Espo\Modules\FeatureCredits\Accounting\Funding;
use Espo\Modules\FeatureCredits\Jobs\ExpireCredits;
use Espo\ORM\EntityManager;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

trait ExpirationSweepCases
{
    private function expirationSweep(PDO $pdo, int $failures = 0): ExpirationSweep
    {
        $manager = $this->createMock(EntityManager::class);
        $manager->method('getPDO')->willReturn($pdo);
        $clock = $this->createMock(Clock::class);
        $clock->method('now')->willReturnCallback(fn () => $this->now);
        $log = $this->createMock(Log::class);
        $log->expects($this->exactly($failures))->method('error');
        return new ExpirationSweep($manager, $clock, new Funding($this->lock($pdo)), $log);
    }

    #[DataProvider('dialects')]
    public function testExpirationSweepPreservesHoldsAndRetries(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $funding = new Funding($this->lock($pdo));
            $expires = '2026-10-10 13:00:00';
            $funding->grant($this->input('expired', '10', expires: $expires));
            $funding->grant($this->input('purchase', '5'));
            $funding->grant($this->input('future', '7', expires: '2026-10-12 00:00:00'));
            $pdo->exec("UPDATE credit_grant SET reserved_credits = 2 WHERE source_key = 'expired'");
            $pdo->exec('UPDATE tenant_credit_balance SET reserved_credits = 2');
            $this->now = $expires;
            $sweep = $this->expirationSweep($pdo);
            $this->assertSame(1, $sweep->run()['processed']);
            $this->assertConsistent($pdo, '14.0000');
            $this->assertSame('2.0000', $pdo->query('SELECT reserved_credits FROM tenant_credit_balance')->fetchColumn());
            $this->assertSame(0, $sweep->run()['processed']);
            (new ExpireCredits($sweep))->run();
            $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM credit_transaction WHERE type = 'expiration'")->fetchColumn());
            $this->assertSame('-8.0000', $pdo->query("SELECT credits FROM credit_transaction WHERE type = 'expiration'")->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testExpirationSweepPagesAndIsolatesTenantFailures(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $funding = new Funding($this->lock($pdo));
            $expires = '2026-10-10 13:00:00';
            // The first tenant fails inside Funding; later pages must still progress.
            for ($i = 0; $i < 102; $i++) {
                $tenant = sprintf('batch-%03d', $i);
                $pdo->prepare('INSERT INTO tenant (id) VALUES (?)')->execute([$tenant]);
                $funding->grant($this->input('expiring', '10', $tenant, $expires));
            }
            $pdo->exec("UPDATE credit_grant SET reserved_credits = -1 WHERE tenant_id = 'batch-000'");
            $this->now = $expires;
            $result = $this->expirationSweep($pdo, 1)->run();
            $this->assertSame(101, $result['processed']);
            $this->assertSame(1, $result['failed']);
            $this->assertConsistent($pdo, '10.0000', 'batch-000');
            $this->assertConsistent($pdo, '0.0000', 'batch-101');
            try {
                (new ExpireCredits($this->expirationSweep($pdo, 1)))->run();
                $this->fail('A failed tenant must mark the scheduled job as failed.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('1 tenant(s)', $e->getMessage());
            }
            $pdo->exec("UPDATE credit_grant SET reserved_credits = 0 WHERE tenant_id = 'batch-000'");
            $this->assertSame(1, $this->expirationSweep($pdo)->run()['processed']);
            $this->assertConsistent($pdo, '0.0000', 'batch-000');
        });
    }
}
