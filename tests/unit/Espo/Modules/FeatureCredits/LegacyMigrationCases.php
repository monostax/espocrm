<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\Conflict;
use Espo\Modules\FeatureCredits\Accounting\{CutoverInput, Funding, LegacyMigration};
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

trait LegacyMigrationCases
{
    private function migrationDatabase(string $dialect, callable $test): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect, $test): void {
            $table = $dialect === 'Mysql' ? '`user`' : '"user"';
            $pdo->exec("CREATE TABLE $table (id VARCHAR(24) PRIMARY KEY, deleted BOOLEAN NOT NULL DEFAULT FALSE)");
            $pdo->exec("INSERT INTO $table (id) VALUES ('admin')");
            try { $test($pdo); }
            finally {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $pdo->exec("DROP TABLE $table");
            }
        });
    }

    private function migration(PDO $pdo): LegacyMigration
    {
        return new LegacyMigration($this->lock($pdo), new Funding($this->lock($pdo)));
    }

    #[DataProvider('dialects')]
    public function testLegacyMigrationOriginalTermsReplayAndTenantIsolation(string $dialect): void
    {
        $this->migrationDatabase($dialect, function (PDO $pdo): void {
            $cutover = $this->cutovers($pdo)->schedule(new CutoverInput('tenant', $this->now, (object) ['approval' => 'approved-cutover']));
            $service = $this->migration($pdo);
            $args = ['tenant', $cutover['id'], '17.25', '2026-11-01 00:00:00', 'legacy-contract:42', 'approved-opening:42', 'admin'];
            $user = $this->createStub(\Espo\Entities\User::class);
            $user->method('isAdmin')->willReturn(true);
            $user->method('getId')->willReturn('admin');
            $request = $this->createStub(\Espo\Core\Api\Request::class);
            $request->method('getBodyContents')->willReturn(json_encode(array_combine(
                ['tenantId', 'cutoverId', 'credits', 'expiresAt', 'legacyReference', 'approvalReference'], array_slice($args, 0, 6))));
            $controller = new \Espo\Modules\FeatureCredits\Controllers\CreditMigration($user, $service);
            $receipt = (array) $controller->postActionGrant($request, $this->createStub(\Espo\Core\Api\Response::class));
            $this->assertSame('17.2500', $receipt['grantedCredits']);
            $normalized = $args; $normalized[2] = '17.2500';
            $this->assertSame($receipt, $service->grant(...$normalized));
            foreach ([0 => 'other', 1 => 'foreign', 2 => '18', 3 => null, 4 => 'another-contract', 5 => 'another-approval', 6 => 'other-admin'] as $index => $value) {
                $changed = $args; $changed[$index] = $value;
                try { $service->grant(...$changed); $this->fail('Changed migration accepted.'); }
                catch (Conflict) {}
            }
            $snapshot = json_decode($pdo->query('SELECT source_snapshot FROM credit_grant')->fetchColumn(), true);
            $this->assertSame('legacy-contract:42', $snapshot['legacyReference']);
            $this->assertSame('approved-opening:42', $snapshot['approvalReference']);
            $this->assertSame('admin', $pdo->query("SELECT actor_id FROM credit_transaction WHERE type='grant'")->fetchColumn());
            $this->assertConsistent($pdo, '17.2500');
            $this->now = '2026-11-01 00:00:00';
            (new Funding($this->lock($pdo)))->expire('tenant');
            $this->assertSame($receipt, $service->grant(...$args));
            $this->assertConsistent($pdo, '0.0000');
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testLegacyMigrationRequiresEffectiveCutoverAndLateGrantExpiresAtomically(string $dialect): void
    {
        $this->migrationDatabase($dialect, function (PDO $pdo): void {
            $service = $this->migration($pdo);
            try { $service->grant('tenant', 'missing', '10', null, 'legacy:1', 'approval:1', 'admin'); $this->fail('Missing cutover funded.'); }
            catch (Conflict) {}
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM tenant_credit_balance')->fetchColumn());
            $cutover = $this->cutovers($pdo)->schedule(new CutoverInput('tenant', '2026-10-11 00:00:00', (object) ['approval' => 'yes']));
            $args = ['tenant', $cutover['id'], '10', '2026-11-01 00:00:00', 'legacy:1', 'approval:1', 'admin'];
            try { $service->grant(...$args); $this->fail('Future cutover funded.'); }
            catch (Conflict) {}
            $this->now = '2026-11-01 00:00:00';
            $receipt = $service->grant(...$args);
            $this->assertSame('2026-11-01 00:00:00', $receipt['expiresAt']);
            $this->assertConsistent($pdo, '0.0000');
            $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM credit_transaction')->fetchColumn());
            $this->assertSame($receipt, $service->grant(...$args));
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testLegacyMigrationRollbackAndIndependentDuplicateWorkers(string $dialect): void
    {
        $this->migrationDatabase($dialect, function (PDO $pdo) use ($dialect): void {
            $cutover = $this->cutovers($pdo)->schedule(new CutoverInput('tenant', $this->now, (object) ['approval' => 'yes']));
            $pdo->exec('ALTER TABLE credit_grant ADD CONSTRAINT migration_fault CHECK (granted_credits < 10)');
            try { $this->migration($pdo)->grant('tenant', $cutover['id'], '10', null, 'legacy:1', 'approval:1', 'admin'); $this->fail('Fault did not fire.'); }
            catch (PDOException) {}
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM credit_transaction')->fetchColumn());
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM tenant_credit_balance')->fetchColumn());
            $pdo->exec('ALTER TABLE credit_grant DROP CONSTRAINT migration_fault');
            $results = $this->race($pdo, $dialect, [['legacy-migration', $cutover['id'], '10', ''], ['legacy-migration', $cutover['id'], '10', '']]);
            $this->assertCount(2, array_column($results, 'result'));
            $this->assertSame($results[0]['result'], $results[1]['result']);
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM credit_grant')->fetchColumn());
            $this->assertConsistent($pdo, '10.0000');
            $this->assertAuditHealthy($pdo);
        });
    }
}
