<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureAiUsage\Attribution;

use Espo\Modules\FeatureAiUsage\Attribution\Reconciler;
use PDO;
use PHPUnit\Framework\TestCase;

class ReconcilerTest extends TestCase
{
    public function testLateMirrorsRepairOnlyMatchingScopeWithoutRemovingWaiversOrExistingLinks(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE chatwoot_account (id TEXT, tenant_id TEXT, deleted INTEGER DEFAULT 0);
            CREATE TABLE chatwoot_conversation (id TEXT, chatwoot_account_id TEXT, chatwoot_conversation_id INTEGER, deleted INTEGER DEFAULT 0);
            CREATE TABLE chatwoot_ai_agent_run (id TEXT, kind TEXT, chatwoot_account_id TEXT, tenant_id TEXT,
                source_conversation_id INTEGER, conversation_id TEXT, billing_waived INTEGER DEFAULT 0, modified_at TEXT, deleted INTEGER DEFAULT 0)');
        $pdo->exec("INSERT INTO chatwoot_account (id, tenant_id) VALUES ('a', 't'), ('b', 'other');
            INSERT INTO chatwoot_conversation (id, chatwoot_account_id, chatwoot_conversation_id) VALUES ('foreign', 'b', 17)");
        $insert = $pdo->prepare('INSERT INTO chatwoot_ai_agent_run (id, kind, chatwoot_account_id, tenant_id, source_conversation_id, conversation_id, billing_waived) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ([
            ['pending', 'customer-message', 'a', 't', 17, null, 0],
            ['waived', 'private-mention', 'a', 't', 17, null, 1],
            ['linked', 'customer-message', 'a', 't', 17, 'original', 0],
            ['wrong-tenant', 'customer-message', 'a', 'other', 17, null, 0],
            ['opportunity', 'opportunity-mention', 'a', 't', 17, null, 0],
            ['missing-source', 'customer-message', 'a', 't', null, null, 0],
            ['deleted-target', 'customer-message', 'a', 't', 18, null, 0],
        ] as $row) $insert->execute($row);
        $reconciler = new Reconciler();
        $this->assertSame(0, $reconciler->reconcile($pdo));
        $pdo->exec("INSERT INTO chatwoot_conversation VALUES ('local', 'a', 17, 0), ('deleted', 'a', 18, 1)");
        $this->assertSame(2, $reconciler->reconcile($pdo));
        $this->assertSame(0, $reconciler->reconcile($pdo));
        $links = $pdo->query('SELECT id, conversation_id FROM chatwoot_ai_agent_run')->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame('local', $links['pending']);
        $this->assertSame('local', $links['waived']);
        $this->assertSame('original', $links['linked']);
        foreach (['wrong-tenant', 'opportunity', 'missing-source', 'deleted-target'] as $id) $this->assertNull($links[$id]);
        $this->assertSame(1, (int) $pdo->query("SELECT billing_waived FROM chatwoot_ai_agent_run WHERE id = 'waived'")->fetchColumn());
    }
}
