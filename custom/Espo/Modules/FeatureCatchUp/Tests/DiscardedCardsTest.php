<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Tests;

use Espo\Modules\FeatureCatchUp\Hooks\Opportunity\DiscardDealCards;
use Espo\Modules\FeatureCatchUp\Rebuild\DiscardObsoleteDealCards;
use Espo\Modules\FeatureCatchUp\Services\ConversationCycle;
use Espo\Modules\FeatureCatchUp\Services\Verdicts;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\RemoveOptions;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

class DiscardedCardsTest extends TestCase
{
    private \PDO $db;
    private EntityManager $em;
    private ConversationCycle $cycle;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->exec("CREATE TABLE opportunity (id TEXT, tenant_id TEXT, deleted INT, status TEXT, stage_entered_at TEXT, modified_at TEXT, created_at TEXT);
            CREATE TABLE chatwoot_account (id TEXT, tenant_id TEXT);
            CREATE TABLE chatwoot_conversation (id TEXT, deleted INT, chatwoot_account_id TEXT, catch_up_discarded_at TEXT,
                last_message_sent_at TEXT, last_message_received_at TEXT);
            CREATE TABLE chatwoot_conversation_opportunity (opportunity_id TEXT, chatwoot_conversation_id TEXT, deleted INT);
            CREATE TABLE catch_up_verdict (opportunity_id TEXT, conversation_id TEXT, verdict TEXT);
            CREATE TABLE catch_up_record (subject_type TEXT, subject_id TEXT);
            INSERT INTO opportunity VALUES ('d','t',0,'Open',NULL,NULL,NULL), ('other','t',0,'Open',NULL,NULL,NULL);
            INSERT INTO chatwoot_account VALUES ('a','t'),('b','another');
            INSERT INTO chatwoot_conversation VALUES ('c',0,'a',NULL,'2026-11-01',NULL), ('cross',0,'b',NULL,'2026-11-01',NULL);
            INSERT INTO chatwoot_conversation_opportunity VALUES ('d','c',0),('d','cross',0),('other','c',0);
            INSERT INTO catch_up_verdict VALUES ('d',NULL,'follow_up_due'), (NULL,'c','customer_waiting'), (NULL,'c','new_deal'),
                ('other',NULL,'promise_due'),(NULL,'cross','follow_up_due');
            INSERT INTO catch_up_record VALUES ('ChatwootConversation','c'), ('Opportunity','other');");
        $this->em = $this->createMock(EntityManager::class);
        $this->em->method('getPDO')->willReturn($this->db);
        $this->cycle = new ConversationCycle($this->em);
    }

    private function checkQueues(): void
    {
        self::assertSame(['promise_due','follow_up_due'], $this->db->query('SELECT verdict FROM catch_up_verdict ORDER BY rowid')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame(['other'], $this->db->query('SELECT subject_id FROM catch_up_record')->fetchAll(\PDO::FETCH_COLUMN));
        $eligible = fn () => $this->db->query("SELECT c.id FROM chatwoot_conversation c WHERE c.id = 'c' " . ConversationCycle::CURRENT_SQL)->fetchColumn();
        self::assertFalse($eligible());
        $this->db->exec("UPDATE chatwoot_conversation SET last_message_received_at = datetime(catch_up_discarded_at, '+1 second') WHERE id = 'c'");
        self::assertSame('c', $eligible());
    }

    public function testLosingOrDeletingRetiresBothSourcesOfWaitingCards(): void
    {
        $deal = $this->createMock(Entity::class);
        $deal->method('getId')->willReturn('d');
        $deal->method('get')->willReturnMap([['status', 'Lost'], ['tenantId', 't']]);
        $deal->method('isAttributeChanged')->with('status')->willReturn(true);
        $hook = new DiscardDealCards(new Verdicts($this->em), $this->cycle);
        $this->db->beginTransaction();
        $hook->afterSave($deal, SaveOptions::fromAssoc([]));
        $this->checkQueues();
        $this->db->rollBack();
        $hook->beforeRemove($deal, RemoveOptions::fromAssoc([]));
        $this->checkQueues();
    }

    public function testRebuildDiscardsExistingCardsUsingActualLossOrDeletionTime(): void
    {
        $rebuild = new DiscardObsoleteDealCards($this->em, $this->cycle);
        foreach (["status = 'Lost', stage_entered_at = '2026-11-02'", "deleted = 1, modified_at = '2026-11-02'"] as $change) {
            $this->db->beginTransaction();
            $this->db->exec("UPDATE opportunity SET $change WHERE id = 'd'");
            $rebuild->process();
            self::assertSame('2026-11-02', $this->db->query("SELECT catch_up_discarded_at FROM chatwoot_conversation WHERE id = 'c'")->fetchColumn());
            $this->checkQueues();
            $this->db->exec("INSERT INTO catch_up_verdict VALUES (NULL, 'c', 'promise_due')");
            $rebuild->process();
            self::assertSame(1, (int) $this->db->query("SELECT COUNT(*) FROM catch_up_verdict WHERE conversation_id = 'c'")->fetchColumn());
            $this->db->rollBack();
        }
    }
}
