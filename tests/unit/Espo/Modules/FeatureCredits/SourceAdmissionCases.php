<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\Conflict;
use Espo\Modules\FeatureCredits\Accounting\Funding;
use Espo\Modules\FeatureCredits\Accounting\ReservationInput;
use Espo\Modules\FeatureCredits\Accounting\SourceReference;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

trait SourceAdmissionCases
{
    #[DataProvider('dialects')]
    public function testConcurrentSourceAdmissionReplayAndConflicts(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $this->reservations($pdo);
            (new Funding($this->lock($pdo)))->grant($this->input());
            $pdo->exec('CREATE TABLE opportunity (id VARCHAR(24) PRIMARY KEY, tenant_id VARCHAR(24), deleted BOOLEAN NOT NULL DEFAULT FALSE)');
            try {
                $pdo->exec("INSERT INTO opportunity (id, tenant_id) VALUES ('one', 'tenant'), ('two', 'tenant')");
                $results = $this->race($pdo, $dialect, [
                    ['reserve-source', 'same', '1', 'one'], ['reserve-source', 'same', '1', 'one'],
                ]);
                $this->assertArrayHasKey('result', $results[0]);
                $this->assertSame($results[0], $results[1]);
                $this->assertHeld($pdo, '1.0000');
                $results = $this->race($pdo, $dialect, [
                    ['reserve-source', 'conflict', '1', 'one'], ['reserve-source', 'conflict', '1', 'two'],
                ]);
                $this->assertCount(1, array_column($results, 'result'));
                $this->assertSame([Conflict::class], array_values(array_column($results, 'error')));
                $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM credit_usage')->fetchColumn());
                $this->assertHeld($pdo, '2.0000');
                $this->assertConsistent($pdo, '10.0000');
            } finally {
                $pdo->exec('DROP TABLE opportunity');
            }
        });
    }

    #[DataProvider('dialects')]
    public function testAdmissionSourceOwnershipReplayAndRollback(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $service = $this->reservations($pdo);
            (new Funding($this->lock($pdo)))->grant($this->input());
            foreach (SourceReference::TABLES as $scope => $table) {
                $pdo->exec("CREATE TABLE $table (id VARCHAR(24) PRIMARY KEY, tenant_id VARCHAR(24), deleted BOOLEAN NOT NULL DEFAULT FALSE)");
                try {
                    $pdo->exec("INSERT INTO $table (id, tenant_id, deleted) VALUES
                        ('source', 'tenant', FALSE), ('foreign', 'other', FALSE), ('gone', 'tenant', TRUE), ('unknown', NULL, FALSE)");
                    foreach (['missing', 'foreign', 'gone', 'unknown'] as $id) {
                        try {
                            $service->reserve(new ReservationInput('tenant', 'ai', 'op', 'execution', 'rate', '1', new SourceReference($scope, $id)));
                            $this->fail('Invalid source accepted.');
                        } catch (Conflict) {
                            $this->assertHeld($pdo, '0.0000');
                            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM credit_usage')->fetchColumn());
                        }
                    }
                    $input = new ReservationInput('tenant', 'ai', 'op', 'execution', 'rate', '1', new SourceReference($scope, 'source'));
                    $pdo->exec('ALTER TABLE credit_allocation ADD CONSTRAINT source_fault CHECK (1 = 0)');
                    try {
                        $service->reserve($input);
                        $this->fail('Injected failure ignored.');
                    } catch (PDOException) {
                        $this->assertHeld($pdo, '0.0000');
                        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM credit_usage')->fetchColumn());
                    } finally {
                        $pdo->exec('ALTER TABLE credit_allocation DROP ' . ($dialect === 'Mysql' ? 'CHECK' : 'CONSTRAINT') . ' source_fault');
                    }
                    $receipt = $service->reserve($input);
                    $this->assertSame(['source_type' => $scope, 'source_id' => 'source'],
                        $pdo->query('SELECT source_type, source_id FROM credit_usage')->fetch(PDO::FETCH_ASSOC));
                    $this->assertSame($receipt, $service->reserve($input));
                    foreach ([null, new SourceReference($scope, 'foreign')] as $replacement) {
                        try {
                            $service->reserve(new ReservationInput('tenant', 'ai', 'op', 'execution', 'rate', '1', $replacement));
                            $this->fail('Source attribution was rewritten.');
                        } catch (Conflict) {
                            $this->assertHeld($pdo, '1.0000');
                        }
                    }
                    $pdo->exec("DELETE FROM $table WHERE id = 'source'");
                    $this->assertSame($receipt, $service->reserve($input), 'Deleted source must not break financial replay.');
                    $released = $service->release('tenant', $receipt['usageId'], 'execution');
                    $this->assertSame($released, $service->reserve($input));
                    $this->assertHeld($pdo, '0.0000');
                    // Clear only this fixture operation so both allowlisted tables exercise the same guards.
                    $pdo->exec('DELETE FROM credit_allocation');
                    $pdo->exec('DELETE FROM credit_reservation');
                    $pdo->exec('DELETE FROM credit_usage');
                } finally {
                    $pdo->exec("DROP TABLE $table");
                }
            }
            $legacy = $service->reserve($this->admission());
            $this->assertNull($pdo->query('SELECT source_id FROM credit_usage')->fetchColumn());
            $this->assertSame($legacy, $service->reserve($this->admission()));
            $this->assertConsistent($pdo, '10.0000');
        });
    }
}
