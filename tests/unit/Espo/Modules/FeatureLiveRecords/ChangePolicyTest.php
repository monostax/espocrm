<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureLiveRecords;

use Espo\Modules\FeatureLiveRecords\Persistence\ChangePolicy;
use Espo\ORM\BaseEntity;
use PDO;
use PHPUnit\Framework\TestCase;

final class ChangePolicyTest extends TestCase
{
    public function testMembershipSyncTimestampIsSilentButRoleAndDeletionRemainVisible(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE chatwoot_account_user_membership (id TEXT, role TEXT,
            deleted INTEGER, last_synced_at TEXT, modified_at TEXT)');
        $pdo->exec("INSERT INTO chatwoot_account_user_membership VALUES ('member', 'agent', 0, NULL, NULL)");
        $entity = new BaseEntity('ChatwootAccountUserMembership', ['attributes' => ['id' => ['type' => 'varchar']]]);
        $entity->set('id', 'member');
        $before = ChangePolicy::snapshot($pdo, $entity);
        $pdo->exec("UPDATE chatwoot_account_user_membership SET last_synced_at = '2026-10-11 21:00:00', modified_at = '2026-10-11 21:00:00'");
        self::assertSame($before, ChangePolicy::snapshot($pdo, $entity));
        $pdo->exec("UPDATE chatwoot_account_user_membership SET role = 'administrator'");
        self::assertNotSame($before, ChangePolicy::snapshot($pdo, $entity));
        $before = ChangePolicy::snapshot($pdo, $entity);
        $pdo->exec('UPDATE chatwoot_account_user_membership SET deleted = 1');
        self::assertNotSame($before, ChangePolicy::snapshot($pdo, $entity));
    }
}
