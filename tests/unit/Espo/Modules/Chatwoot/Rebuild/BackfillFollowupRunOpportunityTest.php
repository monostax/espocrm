<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Rebuild;

use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Rebuild\BackfillFollowupRunOpportunity;
use Espo\ORM\EntityManager;
use PDO;
use PHPUnit\Framework\TestCase;

/** Executes the backfill SQL on disposable data. */
class BackfillFollowupRunOpportunityTest extends TestCase
{
    private PDO $pdo;
    private BackfillFollowupRunOpportunity $action;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE opportunity (id TEXT, tenant_id TEXT, created_at TEXT, deleted INTEGER DEFAULT 0,
            followup_lifetime_count INTEGER DEFAULT 0)');
        $this->pdo->exec('CREATE TABLE chatwoot_conversation_opportunity (id INTEGER, opportunity_id TEXT,
            chatwoot_conversation_id TEXT, deleted INTEGER DEFAULT 0)');
        $this->pdo->exec('CREATE TABLE chatwoot_ai_agent_run (id TEXT, kind TEXT, opportunity_id TEXT, conversation_id TEXT,
            tenant_id TEXT, run_at TEXT, sent_followup_message INTEGER DEFAULT 1, deleted INTEGER DEFAULT 0)');
        $this->pdo->exec("INSERT INTO opportunity (id, tenant_id, created_at, followup_lifetime_count) VALUES
            ('first', 't1', '2026-08-01 10:00:00', 0),
            ('repeat', 't1', '2026-09-01 10:00:00', 0),
            ('counted', 't1', '2026-09-10 10:00:00', 5),
            ('live', 't1', '2026-10-20 10:00:00', 1),
            ('foreign', 't2', '2026-08-01 09:00:00', 0)");
        $this->pdo->exec("INSERT INTO chatwoot_conversation_opportunity (id, opportunity_id, chatwoot_conversation_id) VALUES
            (1, 'first', 'c1'), (2, 'repeat', 'c1'), (3, 'counted', 'c2'), (4, 'live', 'c3'), (5, 'foreign', 'c4')");
        $this->pdo->exec("INSERT INTO chatwoot_ai_agent_run (id, kind, opportunity_id, conversation_id, tenant_id, run_at, sent_followup_message) VALUES
            ('before-any', 'followup-trigger', NULL, 'c1', 't1', '2026-07-01 10:00:00', 1),
            ('first-1', 'followup-trigger', NULL, 'c1', 't1', '2026-08-01 14:00:00', 1),
            ('first-2', 'followup-trigger', NULL, 'c1', 't1', '2026-08-02 14:00:00', 1),
            ('first-skipped', 'followup-trigger', NULL, 'c1', 't1', '2026-08-03 14:00:00', 0),
            ('repeat-1', 'followup-trigger', NULL, 'c1', 't1', '2026-09-01 14:00:00', 1),
            ('counted-1', 'followup-trigger', NULL, 'c2', 't1', '2026-09-10 14:00:00', 1),
            ('live-1', 'followup-trigger', NULL, 'c3', 't1', '2026-10-20 14:00:00', 1),
            ('live-2', 'followup-trigger', 'live', 'c3', 't1', '2026-10-21 14:00:00', 1),
            ('cross-tenant', 'followup-trigger', NULL, 'c4', 't1', '2026-08-02 14:00:00', 1),
            ('customer', 'customer-message', NULL, 'c1', 't1', '2026-08-01 15:00:00', 0),
            ('stamped', 'followup-trigger', 'counted', 'c1', 't1', '2026-08-05 14:00:00', 1)");

        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($this->pdo);
        $this->action = new BackfillFollowupRunOpportunity($em, $this->createMock(Log::class));
    }

    private function column(string $sql): array
    {
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function testLinksRunsToTheConversationsActiveOpportunityWithinTheTenant(): void
    {
        $this->action->process();

        self::assertSame([
            'before-any' => null,
            'counted-1' => 'counted',
            'cross-tenant' => null,
            'customer' => null,
            'first-1' => 'first',
            'first-2' => 'first',
            'first-skipped' => 'first',
            'live-1' => 'live',
            'live-2' => 'live',
            'repeat-1' => 'repeat',
            'stamped' => 'counted',
        ], $this->column('SELECT id, opportunity_id FROM chatwoot_ai_agent_run ORDER BY id'));
    }

    public function testRaisesOnlyPreLiveCountersToTheSentFollowupsAndNeverLowersThem(): void
    {
        $this->action->process();

        self::assertSame([
            'counted' => 5, // already above its 2 sent runs
            'first' => 2, // the unsent run does not count
            'foreign' => 0,
            'live' => 1, // counted live since creation, left alone
            'repeat' => 1,
        ], array_map('intval', $this->column('SELECT id, followup_lifetime_count FROM opportunity ORDER BY id')));
    }

    public function testRepeatedRebuildsChangeNothing(): void
    {
        $this->action->process();
        $runs = $this->column('SELECT id, opportunity_id FROM chatwoot_ai_agent_run ORDER BY id');
        $counts = $this->column('SELECT id, followup_lifetime_count FROM opportunity ORDER BY id');

        $this->action->process();

        self::assertSame($runs, $this->column('SELECT id, opportunity_id FROM chatwoot_ai_agent_run ORDER BY id'));
        self::assertSame($counts, $this->column('SELECT id, followup_lifetime_count FROM opportunity ORDER BY id'));
    }
}
