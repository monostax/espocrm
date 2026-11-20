<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Jobs;

use Espo\Modules\Chatwoot\Jobs\SyncConversationsFromChatwoot;
use PHPUnit\Framework\TestCase;

/**
 * Chatwoot's conversation filter has no total any more (all_count left the meta in the fork on 2026-04-11), and it
 * compares whole days: the sync pages while pages are full and stops at the first conversation older than the cursor.
 */
class SyncConversationsPagingTest extends TestCase
{
    /** @return list<array<string, mixed>> newest first, like Chatwoot */
    private static function page(int ...$lastActivityAt): array
    {
        return array_map(fn (int $at, int $i) => ['id' => $i + 1, 'last_activity_at' => $at], $lastActivityAt, array_keys($lastActivityAt));
    }

    public function testAFullPageWithoutACursorMayHaveMoreAfterIt(): void
    {
        $page = self::page(...range(2000, 2000 - SyncConversationsFromChatwoot::PAGE_SIZE + 1));

        [$changed, $more] = SyncConversationsFromChatwoot::changedOnPage($page, null);

        self::assertCount(SyncConversationsFromChatwoot::PAGE_SIZE, $changed);
        self::assertTrue($more);
    }

    public function testAShortPageIsTheLast(): void
    {
        [$changed, $more] = SyncConversationsFromChatwoot::changedOnPage(self::page(300, 200, 100), null);

        self::assertCount(3, $changed);
        self::assertFalse($more);
        self::assertSame([[], false], SyncConversationsFromChatwoot::changedOnPage([], null), 'an empty page too');
    }

    public function testTheFirstConversationOlderThanTheCursorEndsThePaging(): void
    {
        $page = self::page(...range(2000, 2000 - SyncConversationsFromChatwoot::PAGE_SIZE + 1));

        [$changed, $more] = SyncConversationsFromChatwoot::changedOnPage($page, 1990);

        self::assertSame(range(2000, 1990), array_column($changed, 'last_activity_at'), 'the cursor itself is read again');
        self::assertFalse($more, 'the rest are older');
    }

    public function testAFullPageNewerThanTheCursorMayHaveMoreAfterIt(): void
    {
        $page = self::page(...range(2000, 2000 - SyncConversationsFromChatwoot::PAGE_SIZE + 1));

        [$changed, $more] = SyncConversationsFromChatwoot::changedOnPage($page, 1000);

        self::assertCount(SyncConversationsFromChatwoot::PAGE_SIZE, $changed);
        self::assertTrue($more);
    }

    public function testAConversationWithoutAnActivityTimeIsRead(): void
    {
        [$changed] = SyncConversationsFromChatwoot::changedOnPage([['id' => 1], ['id' => 2, 'last_activity_at' => 50]], 100);

        self::assertSame([1], array_column($changed, 'id'));
    }
}
