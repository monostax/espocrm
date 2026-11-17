<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Tests;

use Espo\Core\ORM\Entity;
use Espo\Modules\FeatureCatchUp\Classes\DeckRequest;
use Espo\Modules\FeatureCatchUp\Services\{ChatwootAccess, ConversationDrafts, ConversationRecord, Feed, ServiceFeed, Verdicts, Workspace};
use Espo\ORM\EntityManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConversationDraftsTest extends TestCase
{
    private string|false $previousSecret;

    protected function setUp(): void
    {
        $this->previousSecret = getenv('CATCH_UP_BACKEND_SECRET');
        putenv('CATCH_UP_BACKEND_SECRET=unit-test');
    }

    protected function tearDown(): void
    {
        putenv($this->previousSecret === false ? 'CATCH_UP_BACKEND_SECRET' : 'CATCH_UP_BACKEND_SECRET=' . $this->previousSecret);
    }

    public static function lanes(): array
    {
        return [['Opportunity', 'opportunity_id'], ['ChatwootConversation', 'conversation_id']];
    }

    #[DataProvider('lanes')]
    public function testOnlyTheCurrentUnresolvedReplyForThisReaderAndTargetIsShown(string $type, string $column): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->sqliteCreateFunction('UTC_TIMESTAMP', fn () => gmdate('Y-m-d H:i:s'));
        $pdo->exec("CREATE TABLE catch_up_verdict ($column TEXT, revision TEXT, locale TEXT, evidence_scope TEXT, verdict TEXT,
            reason TEXT, card TEXT, deleted INTEGER DEFAULT 0, resolved_at TEXT, flips_at TEXT)");
        $pdo->prepare("INSERT INTO catch_up_verdict ($column, revision, locale, evidence_scope, verdict, card)
            VALUES ('card','r','pt-BR','scope','customer_waiting',?)")->execute([json_encode(['draft' => "Olá!\nTudo bem?"])]);
        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($pdo);
        $verdicts = new Verdicts($em);

        $account = $this->createMock(Entity::class);
        $account->method('get')->willReturnMap([['chatwootAccountId', 12], ['platformId', 'platform']]);
        $workspace = $this->createMock(Workspace::class);
        $workspace->method('resolve')->willReturn(['account' => $account]);
        $chat = $this->createMock(Entity::class);
        $chat->method('getId')->willReturn('conv');
        $chat->method('get')->with('chatwootConversationId')->willReturn(7);
        $record = $this->createMock(ConversationRecord::class);
        $record->method('readableConversation')->willReturn($chat);
        $deals = $this->createMock(Feed::class);
        $service = $this->createMock(ServiceFeed::class);
        $isDeal = $type === 'Opportunity';
        $service->method('hasOpenDeal')->willReturn($isDeal);
        $feed = $isDeal ? $deals : $service;
        $feed->method('list')->willReturn(['items' => [['id' => 'card', 'verdict' => 'customer_waiting']], 'cursor' => null]);
        $snapshot = ['conversation' => ['accountId' => 12, 'conversationId' => 7], 'revision' => 'r', 'evidenceScope' => 'scope',
            'evidence' => [['accountId' => 12, 'conversationId' => 7, 'messageId' => 123]]];
        $feed->method('snapshot')->willReturnCallback(function () use (&$snapshot) { return $snapshot; });
        $access = $this->createMock(ChatwootAccess::class);
        $access->method('chatwootUserId')->willReturn(42);
        $drafts = new ConversationDrafts($workspace, $record, $deals, $service, $verdicts, $access);
        $read = fn () => $drafts->read(new DeckRequest(['accountId' => '12', 'conversationId' => '7', 'locale' => 'pt_BR']));

        $card = $read()[0];
        [$body, $signature] = explode('.', $card['grant']);
        self::assertSame(hash_hmac('sha256', 'catch-up-draft-v1.' . $body, getenv('CATCH_UP_BACKEND_SECRET')), $signature);
        [$accountId, $displayId, $viewer, , $key, $text, $edge] = json_decode(base64_decode($body), true);
        self::assertSame([12, 7, 42, $card['key'], "Olá!\nTudo bem?", 123], [$accountId, $displayId, $viewer, $key, $text, $edge]);
        $snapshot['evidenceScope'] = 'less-access';
        self::assertSame([], $read());
        $snapshot['evidenceScope'] = 'scope';
        $snapshot['conversation']['accountId'] = 13; // Same display id, different account.
        self::assertSame([], $read());
        $snapshot['conversation']['accountId'] = 12;
        $snapshot['conversation']['conversationId'] = 8;
        self::assertSame([], $read());
        $snapshot['conversation']['conversationId'] = 7;
        $snapshot['revision'] = 'new-message';
        self::assertSame([], $read());
        $snapshot['revision'] = 'r';
        $verdicts->resolve($type, 'card');
        self::assertSame([], $read());
    }
}
