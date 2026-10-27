<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureLiveRecords;

use Espo\Modules\FeatureLiveRecords\Subscription\Dependencies;
use PHPUnit\Framework\TestCase;

final class DependenciesTest extends TestCase
{
    private const QUERY = 'offset=0&maxSize=100&orderBy=createdAt&order=desc';

    private static function links(string $type, string $link): ?string
    {
        return ['ChatwootConversation' => ['opportunities' => 'Opportunity', 'tasks' => 'Task']][$type][$link] ?? null;
    }

    private static function of(string $path, string $query, string $body): ?array
    {
        return Dependencies::of($path, $query, $body, self::links(...));
    }

    public function testPanelDeclaresItsEntityTypeAndEveryShownRecordId(): void
    {
        $body = json_encode(['total' => 1, 'list' => [[
            'id' => 'opp00000000000001', 'name' => 'Deal', 'contactId' => 'con00000000000001', 'contactName' => 'Ana',
            'teamsIds' => ['team0000000000001'], 'teamsNames' => ['team0000000000001' => 'Sales'],
            'tagsNames' => ['tag00000000000001' => 'VIP'], 'stage' => ['id' => 'stage000000000001'],
        ]]]);
        $deps = self::of('/ChatwootConversation/conv0000000000001/opportunities', self::QUERY, $body);
        self::assertSame(['Opportunity'], $deps['types']);
        $ids = $deps['ids'];
        sort($ids);
        self::assertSame(['con00000000000001', 'conv0000000000001', 'opp00000000000001', 'stage000000000001',
            'tag00000000000001', 'team0000000000001'], $ids);
    }

    public function testEmptyPanelStillDependsOnItsEntityType(): void
    {
        $deps = self::of('/ChatwootConversation/conv0000000000001/tasks', '', '{"total":0,"list":[]}');
        self::assertSame(['types' => ['Task'], 'ids' => ['conv0000000000001']], $deps);
    }

    /** Anything not provably covered keeps tenant-wide re-checks. */
    public function testUncoveredProjectionsDeclareNothing(): void
    {
        $list = '{"total":0,"list":[]}';
        $conversation = '/ChatwootConversation/conv0000000000001';
        self::assertNull(self::of("$conversation/opportunities", 'offset=100&maxSize=100', $list), 'later page');
        self::assertNull(self::of("$conversation/opportunities", self::QUERY . '&where%5B0%5D%5Btype%5D=equals', $list), 'filtered');
        self::assertNull(self::of("$conversation/opportunities", 'q=deal', $list), 'text search');
        self::assertNull(self::of("$conversation/contacts", self::QUERY, $list), 'panel outside the allowlist');
        self::assertNull(self::of("$conversation/calls", self::QUERY, $list), 'link missing from metadata');
        self::assertNull(self::of('/Opportunity/action/navigationCounts', '', '{"unread":1}'), 'aggregate');
        self::assertNull(self::of('/Opportunity/opp00000000000001', '', '{"id":"opp00000000000001"}'), 'record view');
        self::assertNull(self::of("$conversation/opportunities", self::QUERY, 'not json'), 'malformed body');
        self::assertNull(self::of("$conversation/opportunities", self::QUERY, '{"total":0}'), 'not a list');
    }

    public function testTooManyIdsDeclaresNothing(): void
    {
        $list = array_map(static fn ($i) => ['id' => sprintf('opp%014d', $i)], range(1, Dependencies::MAX_IDS + 1));
        self::assertNull(self::of('/ChatwootConversation/conv0000000000001/opportunities', self::QUERY,
            json_encode(['total' => count($list), 'list' => $list])));
    }

    /** A declaration must fit a result line the socket accepts (it kills lines past 16KB). */
    public function testLongIdsPastTheByteBudgetDeclareNothing(): void
    {
        $list = array_map(static fn ($i) => ['id' => sprintf('%064d', $i)], range(1, 200));
        $body = json_encode(['total' => 200, 'list' => $list]);
        self::assertNull(self::of('/ChatwootConversation/conv0000000000001/opportunities', self::QUERY, $body));
        $fits = array_map(static fn ($i) => ['id' => sprintf('opp%014d', $i)], range(1, 300));
        $deps = self::of('/ChatwootConversation/conv0000000000001/opportunities', self::QUERY,
            json_encode(['total' => 300, 'list' => $fits]));
        self::assertNotNull($deps);
        $line = json_encode(['index' => 31, 'result' => ['fingerprint' => str_repeat('a', 64),
            'tenantIds' => array_fill(0, 64, str_repeat('t', 64)), 'deps' => $deps]]);
        self::assertLessThan(16384, strlen($line));
    }
}
