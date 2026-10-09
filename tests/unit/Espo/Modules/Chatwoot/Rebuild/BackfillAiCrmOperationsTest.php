<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Rebuild;

use Espo\Modules\Chatwoot\Rebuild\BackfillAiCrmOperations;
use Espo\ORM\EntityManager;
use PDO;
use PHPUnit\Framework\TestCase;

class BackfillAiCrmOperationsTest extends TestCase
{
    public function testDefaultsOnlyNullValuesAndPreservesExplicitGrantsAndDenials(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE chatwoot_account_user_membership (id TEXT, ai_crm_operations TEXT, deleted BOOLEAN, ai_can_execute BOOLEAN, ai_can_send BOOLEAN)');
        $insert = $pdo->prepare('INSERT INTO chatwoot_account_user_membership VALUES (?, ?, ?, ?, ?)');
        foreach ([['legacy', null, 0, null, null], ['denied', '[]', 0, false, false], ['reader', '["read"]', 0, false, true],
            ['writer', '["read","update"]', 0, true, false], ['partial', '["read"]', 0, null, false], ['deleted', null, 1, null, null]] as $row) {
            $insert->execute($row);
        }
        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getPDO')->willReturn($pdo);
        $action = new BackfillAiCrmOperations($entityManager);
        $action->process();
        $action->process();
        $rows = $pdo->query('SELECT id, ai_crm_operations FROM chatwoot_account_user_membership')->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame('["read","create","update"]', $rows['legacy']);
        $this->assertSame('["read"]', $rows['reader']);
        $this->assertSame('[]', $rows['denied']);
        $this->assertSame('["read","update"]', $rows['writer']);
        $this->assertNull($rows['deleted']);
        $flags = $pdo->query('SELECT id, ai_can_execute, ai_can_send FROM chatwoot_account_user_membership')->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) $flags['legacy']['ai_can_execute']);
        $this->assertSame(1, (int) $flags['legacy']['ai_can_send']);
        $this->assertSame(0, (int) $flags['denied']['ai_can_execute']);
        $this->assertSame(0, (int) $flags['denied']['ai_can_send']);
        $this->assertSame(0, (int) $flags['reader']['ai_can_execute']);
        $this->assertSame(1, (int) $flags['reader']['ai_can_send']);
        $this->assertSame(1, (int) $flags['writer']['ai_can_execute']);
        $this->assertSame(0, (int) $flags['writer']['ai_can_send']);
        $this->assertSame(1, (int) $flags['partial']['ai_can_execute']);
        $this->assertSame(0, (int) $flags['partial']['ai_can_send']);
        $this->assertNull($flags['deleted']['ai_can_execute']);
        $this->assertNull($flags['deleted']['ai_can_send']);
    }
}
